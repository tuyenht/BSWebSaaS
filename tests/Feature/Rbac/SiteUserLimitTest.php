<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Actions\AcceptInvitation;
use App\Modules\Rbac\Actions\InviteMember;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Exceptions\InvitationRejectedException;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\SiteHeadcount;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Tenancy\Registry\FeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The people a website may hold (`users.max`, default 10): its members plus the invitations still
 * waiting. An invitation past the limit is refused; accepting counts the members alone, as the
 * waiting invitations were counted when sent — so a website exactly at its limit still lets them
 * in, and a website whose plan was lowered below its people keeps them all but lets nobody new in.
 * The seeding migration gives every plan room for its largest website, and a customer with no plan
 * an override for a website above 10, so nobody is held back the day the check ships.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $admin = DB::connection('pgsql_admin');
    $admin->statement('truncate invitations, invitation_roles, audit_logs, users restart identity cascade');
    $admin->statement('delete from plans');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    fakePwnedVerifier();
});

/** $site's system role $name, materialized with its corporate template level and permissions. */
function limitRole(int $site, string $name): Role
{
    $tpl = PermissionRegistry::tenantRoleTemplates('corporate')[$name];
    $perms = $tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions'];

    return app(Tenancy::class)->enter($site, function () use ($name, $tpl, $perms): Role {
        $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $tpl['level'], 'is_system' => true])->save();
        $role->syncPermissions($perms);

        return $role;
    });
}

/** A fresh account holding $site's role $name. */
function limitMember(int $site, string $name = 'viewer'): User
{
    $role = limitRole($site, $name);
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => $user->assignRole($role));

    return $user;
}

/** The website's owner plus $others viewers: 1 + $others members. */
function limitTeam(int $site, int $others): User
{
    $owner = limitMember($site, 'owner');
    for ($i = 0; $i < $others; $i++) {
        limitMember($site);
    }

    return $owner;
}

/** A waiting invitation written as it stands in the database (no limit is checked); its link is "link-$email". */
function limitWaiting(int $site, User $inviter, string $email, int $daysLeft = 5): Invitation
{
    $viewer = limitRole($site, 'viewer');

    return app(Tenancy::class)->enter($site, function () use ($inviter, $email, $daysLeft, $viewer): Invitation {
        $invitation = Invitation::query()->forceCreate([
            'email' => $email, 'token_hash' => Invitation::hashToken('link-'.$email), 'invited_by' => $inviter->id,
            'expires_at' => now()->addDays($daysLeft), 'sent_at' => now()->subDays(7 - $daysLeft),
        ]);
        $invitation->roles()->syncWithPivotValues([$viewer->id], ['site_id' => $invitation->site_id]);

        return $invitation;
    });
}

/** Why inviting $email to $site was refused, or null when it went through. */
function limitInviteRefusal(int $site, User $actor, string $email): ?string
{
    $viewer = limitRole($site, 'viewer');
    try {
        app(Tenancy::class)->enter($site, fn () => app(InviteMember::class)->handle($actor->fresh(), $email, [$viewer]));
    } catch (InvitationRejectedException $e) {
        return $e->reason;
    }

    return null;
}

/** Why accepting the invitation to $email (a new account) was refused, or null when it went through. */
function limitAcceptRefusal(int $site, string $email): ?string
{
    try {
        app(Tenancy::class)->enter($site, fn () => app(AcceptInvitation::class)->withNewAccount('link-'.$email, 'Người Mới', 'Mat-khau-rat-manh-2026'));
    } catch (InvitationRejectedException $e) {
        return $e->reason;
    }

    return null;
}

/** @return array{members: int, pending: int, limit: int, is_member: bool} */
function limitHeadcount(int $site): array
{
    return app(Tenancy::class)->enter($site, fn (): array => app(SiteHeadcount::class)->current());
}

/** A plan granting $features, given to customer 1 (both seeded websites). */
function limitPlan(string $key, array $features = []): int
{
    $admin = DB::connection('pgsql_admin');
    $plan = (int) $admin->table('plans')->insertGetId(['key' => $key, 'name' => ucfirst($key), 'created_at' => now(), 'updated_at' => now()]);
    foreach ($features as $feature => $value) {
        $admin->table('plan_features')->insert(['plan_id' => $plan, 'feature_key' => $feature, 'value' => json_encode($value)]);
    }

    return $plan;
}

function userLimitMigration(): Migration
{
    return require database_path('migrations/2076_01_01_000000_seed_user_limit_into_plans.php');
}

/** Runs the seeding migration on the privileged connection, as migrations run. */
function seedUserLimit(): void
{
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        userLimitMigration()->up();
    } finally {
        DB::setDefaultConnection($original);
    }
}

function planUserLimit(int $plan): mixed
{
    $value = DB::connection('pgsql_admin')->table('plan_features')->where('plan_id', $plan)->where('feature_key', 'users.max')->value('value');

    return $value === null ? null : json_decode((string) $value, true);
}

it('gives a website ten places by default: members and waiting invitations fill them', function (): void {
    expect(FeatureRegistry::all()['users.max'])->toBe(['name' => 'Số người dùng tối đa / website', 'kind' => 'limit', 'default' => 10, 'scope' => 'site'])
        ->and(app(Tenancy::class)->enter(1, fn () => app(Entitlement::class)->featureValue('users.max')))->toBe(10);

    $owner = limitTeam(1, 8);   // 9 members
    expect(limitInviteRefusal(1, $owner, 'thu-muoi@example.test'))->toBeNull()
        ->and(limitHeadcount(1))->toBe(['members' => 9, 'pending' => 1, 'limit' => 10, 'is_member' => false])
        ->and(limitInviteRefusal(1, $owner, 'thu-muoi-mot@example.test'))->toBe('user_limit')
        // the other website counts its own people
        ->and(limitInviteRefusal(2, limitMember(2, 'owner'), 'thu-muoi-mot@example.test'))->toBeNull();
});

it('seeds a plan at its largest website: 12 members and a waiting invitation make 13, and the invitation still gets in', function (): void {
    $plan = limitPlan('pro');
    $unused = limitPlan('ca-nhan');
    DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->update(['plan_id' => $plan]);
    $owner = limitTeam(1, 11);   // 12 members
    limitWaiting(1, $owner, 'dang-cho@example.test');
    limitWaiting(1, $owner, 'het-han@example.test', daysLeft: -1);   // expired: not counted
    limitTeam(2, 2);

    seedUserLimit();
    seedUserLimit();   // a second run changes nothing

    expect(planUserLimit($plan))->toBe(13)
        ->and(planUserLimit($unused))->toBe(10)
        ->and(DB::connection('pgsql_admin')->table('site_features')->where('feature_key', 'users.max')->count())->toBe(0)
        ->and(limitHeadcount(1))->toBe(['members' => 12, 'pending' => 1, 'limit' => 13, 'is_member' => false])
        ->and(limitInviteRefusal(1, $owner, 'nguoi-moi@example.test'))->toBe('user_limit')
        ->and(limitAcceptRefusal(1, 'dang-cho@example.test'))->toBeNull()
        ->and(limitHeadcount(1))->toBe(['members' => 13, 'pending' => 0, 'limit' => 13, 'is_member' => false]);
});

it('gives a website of a customer with no plan its own limit at its count, so nobody waiting is turned away', function (): void {
    $owner = limitTeam(1, 9);   // 10 members
    limitWaiting(1, $owner, 'cho-mot@example.test');
    limitWaiting(1, $owner, 'cho-hai@example.test');
    limitTeam(2, 2);            // 3 people: the default covers it

    seedUserLimit();
    seedUserLimit();

    $overrides = DB::connection('pgsql_admin')->table('site_features')->where('feature_key', 'users.max')->get(['site_id', 'value']);
    expect($overrides->map(fn (object $row): array => [$row->site_id, json_decode((string) $row->value, true)])->all())->toBe([[1, 12]]);

    $history = DB::connection('pgsql_admin')->table('tenant_history')->where('kind', 'feature_overridden')->get(['tenant_id', 'reason', 'actor_user_id', 'meta']);
    expect($history)->toHaveCount(1)
        ->and($history[0]->tenant_id)->toBe(1)
        ->and($history[0]->actor_user_id)->toBeNull()
        ->and($history[0]->reason)->toBe('Giữ số người đang có khi bật giới hạn số người dùng mỗi website')
        ->and(json_decode((string) $history[0]->meta, true))->toEqual(['site' => 'SITEA000000000000000000001', 'value' => 12, 'feature' => 'users.max', 'site_name' => 'A']);

    expect(limitAcceptRefusal(1, 'cho-mot@example.test'))->toBeNull()
        ->and(limitAcceptRefusal(1, 'cho-hai@example.test'))->toBeNull()
        ->and(limitHeadcount(1))->toBe(['members' => 12, 'pending' => 0, 'limit' => 12, 'is_member' => false])
        ->and(limitInviteRefusal(1, $owner, 'them-nua@example.test'))->toBe('user_limit');
});

it('keeps everyone when the plan is lowered below the people a website has, and lets nobody new in', function (): void {
    $plan = limitPlan('pro', ['users.max' => 13]);
    DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->update(['plan_id' => $plan]);
    $owner = limitTeam(1, 11);   // 12 members
    limitWaiting(1, $owner, 'dang-cho@example.test');

    DB::connection('pgsql_admin')->table('plan_features')->where('plan_id', $plan)->where('feature_key', 'users.max')->update(['value' => json_encode(5)]);

    expect(limitHeadcount(1))->toBe(['members' => 12, 'pending' => 1, 'limit' => 5, 'is_member' => false])
        ->and(limitInviteRefusal(1, $owner, 'nguoi-moi@example.test'))->toBe('user_limit')
        ->and(limitAcceptRefusal(1, 'dang-cho@example.test'))->toBe('user_limit_join')
        // the refused join left no account behind, and the invitation still waits
        ->and(User::query()->where('email', 'dang-cho@example.test')->exists())->toBeFalse()
        ->and(limitHeadcount(1))->toBe(['members' => 12, 'pending' => 1, 'limit' => 5, 'is_member' => false]);
});

it('re-sends a waiting invitation at the limit, but holds an expired one to it like a new one', function (): void {
    $site = 1;
    app(Tenancy::class)->enter($site, fn () => SiteFeature::query()->create(['feature_key' => 'users.max', 'value' => 4]));
    $owner = limitTeam($site, 1);   // 2 members
    expect(limitInviteRefusal($site, $owner, 'cho@example.test'))->toBeNull();
    limitWaiting($site, $owner, 'het-han@example.test', daysLeft: -1);
    expect(limitInviteRefusal($site, $owner, 'thu-tu@example.test'))->toBeNull()   // 2 + 2 = 4
        ->and(limitInviteRefusal($site, $owner, 'thu-nam@example.test'))->toBe('user_limit');

    $this->travel(InviteMember::RESEND_COOLDOWN_SECONDS + 1)->seconds();

    expect(limitInviteRefusal($site, $owner, 'cho@example.test'))->toBeNull()          // counted already: re-sent
        ->and(limitInviteRefusal($site, $owner, 'het-han@example.test'))->toBe('user_limit')   // would wait again: a fifth
        ->and(limitHeadcount($site))->toBe(['members' => 2, 'pending' => 2, 'limit' => 4, 'is_member' => false]);
});

it('never turns away someone who is a member already', function (): void {
    app(Tenancy::class)->enter(1, fn () => SiteFeature::query()->create(['feature_key' => 'users.max', 'value' => 2]));
    $owner = limitMember(1, 'owner');
    $member = limitMember(1);   // 2 members: full
    limitWaiting(1, $owner, $member->email);

    expect(app(Tenancy::class)->enter(1, fn (): array => app(SiteHeadcount::class)->current($member->id)))
        ->toBe(['members' => 2, 'pending' => 0, 'limit' => 2, 'is_member' => true]);

    app(Tenancy::class)->enter(1, fn () => app(AcceptInvitation::class)->asSignedInUser('link-'.$member->email, $member->fresh()));

    expect(DB::connection('pgsql_admin')->table('invitations')->where('email', $member->email)->value('accepted_user_id'))->toBe($member->id);
});

it('reads the limit in SQL exactly as Entitlement resolves it: override, then plan, then the default', function (): void {
    $value = fn (): array => app(Tenancy::class)->enter(1, function (): array {
        $entitlement = app(Entitlement::class);
        [$sql, $bindings] = $entitlement->featureValueExpression('users.max');

        return [$entitlement->featureValue('users.max'), $entitlement->valueFrom(DB::selectOne("select {$sql} as v", $bindings)?->v, 'users.max')];
    });

    expect($value())->toBe([10, 10]);

    $plan = limitPlan('pro', ['users.max' => 25]);
    DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->update(['plan_id' => $plan]);
    expect($value())->toBe([25, 25]);

    app(Tenancy::class)->enter(1, fn () => SiteFeature::query()->create(['feature_key' => 'users.max', 'value' => 40]));
    expect($value())->toBe([40, 40]);

    // a customer-wide feature never reads an override
    $siteWide = app(Tenancy::class)->enter(1, fn (): array => app(Entitlement::class)->featureValueExpression('sites.max'));
    expect($siteWide[0])->not->toContain('site_features');
});

it('costs an invitation one more query: the counts and the limit come in one statement', function (): void {
    $owner = limitTeam(1, 3);
    $viewer = limitRole(1, 'viewer');

    $check = app(Tenancy::class)->enter(1, fn (): int => countQueries(fn () => app(SiteHeadcount::class)->assertRoomToInvite(null)));
    $invite = app(Tenancy::class)->enter(1, fn (): int => countQueries(fn () => app(InviteMember::class)->handle($owner->fresh(), 'moi@example.test', [$viewer])));

    // 18 before the people limit, + the one statement
    expect($check)->toBe(1)->and($invite)->toBeLessThanOrEqual(19);
});
