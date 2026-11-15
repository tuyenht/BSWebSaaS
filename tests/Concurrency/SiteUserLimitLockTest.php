<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Actions\AcceptInvitation;
use App\Modules\Rbac\Actions\InviteMember;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Exceptions\InvitationRejectedException;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteFeature;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * A website holds at most `users.max` people. Inviting and accepting both count the people and
 * then add one, under the website's invitation advisory lock (InviteMember::lockKey) taken inside
 * the write's transaction before the count. Two writes racing for the last place therefore run one
 * after the other: the second waits for the first to finish, then counts it and is refused.
 *
 * PHP runs one statement at a time, so the race is staged on two real connections: the first write
 * runs on pgsql_admin and stays uncommitted while the second runs on the app connection with a
 * short lock_timeout. Without the lock the second would count the people before the first (it
 * cannot see the first's rows) and get in too, one past the limit; with the lock it waits and
 * times out, and once the first commits it is refused.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 (a.test) / 2 (b.test)
    $admin = DB::connection('pgsql_admin');
    $admin->statement('truncate invitations, invitation_roles, audit_logs, users restart identity cascade');
    $admin->statement('delete from plans');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    fakePwnedVerifier();

    // Three places: the owner and one viewer hold two, one is left.
    app(Tenancy::class)->enter(1, fn () => SiteFeature::query()->create(['feature_key' => 'users.max', 'value' => 3]));
    $this->viewer = seatRaceRole('viewer');
    $this->owner = seatRaceMember('owner');
    seatRaceMember('viewer');
});

/** Site 1's system role $name, materialized with its corporate template level and permissions. */
function seatRaceRole(string $name): Role
{
    $tpl = PermissionRegistry::tenantRoleTemplates('corporate')[$name];
    $perms = $tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions'];

    return app(Tenancy::class)->enter(1, function () use ($name, $tpl, $perms): Role {
        $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $tpl['level'], 'is_system' => true])->save();
        $role->syncPermissions($perms);

        return $role;
    });
}

/** A fresh account holding site 1's role $name. */
function seatRaceMember(string $name): User
{
    $role = seatRaceRole($name);
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => $user->assignRole($role));

    return $user;
}

/** A waiting invitation to $email on site 1 (as it stands in the database); its link is "link-$email". */
function seatRaceWaiting(User $inviter, Role $role, string $email): void
{
    app(Tenancy::class)->enter(1, function () use ($inviter, $role, $email): void {
        $invitation = Invitation::query()->forceCreate([
            'email' => $email, 'token_hash' => Invitation::hashToken('link-'.$email), 'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(5), 'sent_at' => now()->subDays(2),
        ]);
        $invitation->roles()->syncWithPivotValues([$role->id], ['site_id' => 1]);
    });
}

/**
 * Run $write on the app connection, in site 1, giving up on a lock after a short wait. Returns the
 * refusal reason, the SQLSTATE of a database error (55P03 = gave up waiting for a lock), or null
 * when the write went through.
 */
function seatRaceOnAppConnection(Closure $write): ?string
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql');

    try {
        return app(Tenancy::class)->enter(1, function () use ($write): ?string {
            DB::statement("set local lock_timeout = '300ms'");
            try {
                $write();
            } catch (InvitationRejectedException $e) {
                return $e->reason;
            } catch (QueryException $e) {
                return (string) $e->getCode();
            }

            return null;
        });
    } finally {
        DB::setDefaultConnection($previous);
    }
}

/**
 * Run $first on pgsql_admin in site 1 and keep it uncommitted while $second runs on the app
 * connection, then commit the first. Returns what the second got.
 */
function seatRaceWhileFirstOpen(Closure $first, Closure $second): ?string
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');

    try {
        return app(Tenancy::class)->enter(1, function () use ($first, $second): ?string {
            $first();

            return seatRaceOnAppConnection($second);
        });
    } finally {
        DB::setDefaultConnection($previous);
    }
}

/** Site 1's members and open invitations, committed (read past RLS). */
function seatRacePeople(): array
{
    $admin = DB::connection('pgsql_admin');

    return [
        'members' => $admin->table('model_has_roles')->where('site_id', 1)->distinct()->count('model_id'),
        'open' => $admin->table('invitations')->where('site_id', 1)->whereNull('accepted_at')->whereNull('revoked_at')->orderBy('email')->pluck('email')->all(),
    ];
}

it('lets exactly one of two invitations racing for the last place through', function (): void {
    /** @var User $owner */
    $owner = $this->owner;
    /** @var Role $viewer */
    $viewer = $this->viewer;
    $invite = fn (User $actor, string $email, Role $role) => app(InviteMember::class)->handle($actor, $email, [$role]);

    // The second invitation waits for the first one's lock: it neither counts two people nor gets in.
    // The first reads its actor and role on pgsql_admin, so every query of it runs there.
    $whileOpen = seatRaceWhileFirstOpen(
        fn () => $invite(User::on('pgsql_admin')->findOrFail($owner->id), 'thu-nhat@example.test', Role::on('pgsql_admin')->findOrFail($viewer->id)),
        fn () => $invite($owner->fresh(), 'thu-hai@example.test', $viewer),
    );
    expect($whileOpen)->toBe('55P03')
        ->and(seatRacePeople())->toBe(['members' => 2, 'open' => ['thu-nhat@example.test']]);

    // Once the first has committed, the second counts it and is refused.
    expect(seatRaceOnAppConnection(fn () => $invite($owner->fresh(), 'thu-hai@example.test', $viewer)))->toBe('user_limit')
        ->and(seatRacePeople())->toBe(['members' => 2, 'open' => ['thu-nhat@example.test']]);
});

it('lets exactly one of two accepted invitations racing for the last place join', function (): void {
    // Both invitations wait (sent before the limit was lowered to 3): two people for one place.
    seatRaceWaiting($this->owner, $this->viewer, 'cho-mot@example.test');
    seatRaceWaiting($this->owner, $this->viewer, 'cho-hai@example.test');
    $accept = fn (string $email) => app(AcceptInvitation::class)->withNewAccount('link-'.$email, 'Người Mới', 'Mat-khau-rat-manh-2026');

    // The second accept waits for the first one's lock: it neither counts two members nor joins.
    $whileOpen = seatRaceWhileFirstOpen(
        fn () => $accept('cho-mot@example.test'),
        fn () => $accept('cho-hai@example.test'),
    );
    expect($whileOpen)->toBe('55P03')
        ->and(seatRacePeople())->toBe(['members' => 3, 'open' => ['cho-hai@example.test']]);

    // Once the first has committed, the second counts three members and is refused, leaving no account.
    expect(seatRaceOnAppConnection(fn () => $accept('cho-hai@example.test')))->toBe('user_limit_join')
        ->and(seatRacePeople())->toBe(['members' => 3, 'open' => ['cho-hai@example.test']])
        ->and(DB::connection('pgsql_admin')->table('users')->where('email', 'cho-hai@example.test')->exists())->toBeFalse();
});
