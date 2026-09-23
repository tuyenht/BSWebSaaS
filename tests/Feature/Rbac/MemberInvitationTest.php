<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Actions\AcceptInvitation;
use App\Modules\Rbac\Actions\InviteMember;
use App\Modules\Rbac\Actions\RevokeInvitation;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Exceptions\InvitationRejectedException;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\IssuedInvitation;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * Member invitations (design.md §15.3). An invitation is the only way into a site for an address:
 * the inviter never learns whether it holds an account, the mailed token is stored only as a hash,
 * it works once, on its own site, until it expires or is withdrawn — and joining re-checks that the
 * inviter can still grant those roles. An existing account is never touched by the link: it signs
 * in to join, and the link can never set its password.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate invitations, invitation_roles, users restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    fakePwnedVerifier();
});

/** A fresh user holding the corporate role template $name in $site. */
function invitationMember(int $site, string $name): User
{
    $tpl = PermissionRegistry::tenantRoleTemplates('corporate')[$name];
    $perms = $tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions'];
    $user = User::factory()->create();

    app(Tenancy::class)->enter($site, function () use ($name, $tpl, $perms, $user): void {
        $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $tpl['level'], 'is_system' => true])->save();
        $role->syncPermissions($perms);
        $user->assignRole($role);
    });

    return $user;
}

/** $site's role called $name, materialized with its template level + permissions. */
function invitationRole(int $site, string $name): Role
{
    invitationMember($site, $name);

    return app(Tenancy::class)->enter($site, fn (): Role => Role::findByName($name, PermissionRegistry::GUARD));
}

function invite(int $site, User $actor, string $email, string $roleName = 'viewer', ?string $message = null): IssuedInvitation
{
    $role = invitationRole($site, $roleName);

    return app(Tenancy::class)->enter($site, fn (): IssuedInvitation => app(InviteMember::class)->handle($actor->fresh(), $email, [$role], $message));
}

/** Why an invitation step was refused, or null when it went through. */
function invitationRefusal(Closure $step): ?string
{
    try {
        $step();
    } catch (InvitationRejectedException $e) {
        return $e->reason;
    }

    return null;
}

/** @return list<string> */
function invitationRoles(int $site, User $user): array
{
    return app(Tenancy::class)->enter($site, fn (): array => $user->fresh()?->roles()->pluck('name')->all() ?? []);
}

it('stores only the hash of the mailed token', function (): void {
    $issued = invite(1, invitationMember(1, 'owner'), 'Moi@Example.test', message: 'Chào anh, mời anh vào quản trị.');

    $row = DB::connection('pgsql_admin')->table('invitations')->first();

    expect($issued->token)->toHaveLength(64)
        ->and($row->token_hash)->toBe(hash('sha256', $issued->token))
        ->and(json_encode($row))->not->toContain($issued->token)
        ->and($row->email)->toBe('moi@example.test')
        ->and($row->message)->toBe('Chào anh, mời anh vào quản trị.');
});

it('grants nothing to an existing account until it accepts', function (): void {
    $existing = User::factory()->create(['email' => 'da-co@example.test']);

    invite(1, invitationMember(1, 'owner'), 'da-co@example.test');

    expect(invitationRoles(1, $existing))->toBe([]);
});

it('creates the account, verified, with the roles, when a new address accepts', function (): void {
    $issued = invite(1, invitationMember(1, 'owner'), 'moi@example.test', 'editor');

    $user = app(Tenancy::class)->enter(1, fn (): User => app(AcceptInvitation::class)
        ->withNewAccount($issued->token, 'Nguyễn Văn An', 'Mat-khau-rat-manh-2026'));

    expect($user->email)->toBe('moi@example.test')
        ->and($user->name)->toBe('Nguyễn Văn An')
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('Mat-khau-rat-manh-2026', $user->password))->toBeTrue()
        ->and(invitationRoles(1, $user))->toBe(['editor']);

    $row = DB::connection('pgsql_admin')->table('invitations')->first();
    expect($row->accepted_at)->not->toBeNull()->and($row->accepted_user_id)->toBe($user->id);
});

it('joins an existing account that is signed in as the invited address', function (): void {
    $existing = User::factory()->create(['email' => 'da-co@example.test']);
    $issued = invite(1, invitationMember(1, 'owner'), 'DA-CO@example.test');

    app(Tenancy::class)->enter(1, fn () => app(AcceptInvitation::class)->asSignedInUser($issued->token, $existing));

    expect(invitationRoles(1, $existing))->toBe(['viewer']);
});

it('never lets the link set the password of an existing account', function (): void {
    $existing = User::factory()->create(['email' => 'da-co@example.test', 'password' => Hash::make('mat-khau-cu-cua-chu')]);
    $issued = invite(1, invitationMember(1, 'owner'), 'da-co@example.test');

    $refusal = invitationRefusal(fn () => app(Tenancy::class)->enter(1, fn () => app(AcceptInvitation::class)
        ->withNewAccount($issued->token, 'Kẻ lạ', 'mat-khau-ke-la-2026')));

    expect($refusal)->toBe('account_exists')
        ->and(Hash::check('mat-khau-cu-cua-chu', $existing->fresh()->password))->toBeTrue()
        ->and(invitationRoles(1, $existing))->toBe([]);
});

it('refuses a signed-in account that is not the invited address', function (): void {
    $other = User::factory()->create(['email' => 'nguoi-khac@example.test']);
    $issued = invite(1, invitationMember(1, 'owner'), 'moi@example.test');

    $refusal = invitationRefusal(fn () => app(Tenancy::class)->enter(1, fn () => app(AcceptInvitation::class)
        ->asSignedInUser($issued->token, $other)));

    expect($refusal)->toBe('wrong_account')->and(invitationRoles(1, $other))->toBe([]);
});

it('works once', function (): void {
    $issued = invite(1, invitationMember(1, 'owner'), 'moi@example.test');
    $accept = fn (string $name) => app(Tenancy::class)->enter(1, fn () => app(AcceptInvitation::class)
        ->withNewAccount($issued->token, $name, 'Mat-khau-rat-manh-2026'));

    expect(invitationRefusal(fn () => $accept('Lần một')))->toBeNull()
        ->and(invitationRefusal(fn () => $accept('Lần hai')))->toBe('invalid');
});

it('expires after seven days', function (): void {
    $issued = invite(1, invitationMember(1, 'owner'), 'moi@example.test');

    $this->travel(InviteMember::VALID_DAYS)->days();
    $this->travel(1)->minutes();

    $refusal = invitationRefusal(fn () => app(Tenancy::class)->enter(1, fn () => app(AcceptInvitation::class)->find($issued->token)));

    expect($refusal)->toBe('expired');
});

it('stops working once withdrawn', function (): void {
    $owner = invitationMember(1, 'owner');
    $issued = invite(1, $owner, 'moi@example.test');

    app(Tenancy::class)->enter(1, fn () => app(RevokeInvitation::class)->handle($owner->fresh(), $issued->invitation->fresh()));

    $refusal = invitationRefusal(fn () => app(Tenancy::class)->enter(1, fn () => app(AcceptInvitation::class)->find($issued->token)));

    expect($refusal)->toBe('invalid');
});

it('only works on the site it was issued for', function (): void {
    $issued = invite(1, invitationMember(1, 'owner'), 'moi@example.test');

    $refusal = invitationRefusal(fn () => app(Tenancy::class)->enter(2, fn () => app(AcceptInvitation::class)->find($issued->token)));

    expect($refusal)->toBe('invalid')
        ->and(app(Tenancy::class)->enter(2, fn (): int => Invitation::query()->count()))->toBe(0);
});

it('refuses to join when the inviter can no longer grant the role', function (): void {
    $admin = invitationMember(1, 'admin');
    $issued = invite(1, $admin, 'moi@example.test', 'editor');

    // The inviter loses their role before the invitation is answered.
    app(Tenancy::class)->enter(1, fn () => $admin->fresh()->syncRoles([]));

    $refusal = invitationRefusal(fn () => app(Tenancy::class)->enter(1, fn () => app(AcceptInvitation::class)
        ->withNewAccount($issued->token, 'Người mới', 'Mat-khau-rat-manh-2026')));

    expect($refusal)->toBe('no_longer_valid')
        ->and(User::query()->where('email', 'moi@example.test')->exists())->toBeFalse();   // rolled back
});

it('keeps an inviter below their own level', function (): void {
    $admin = invitationMember(1, 'admin');

    expect(fn () => invite(1, $admin, 'moi@example.test', 'owner'))->toThrow(AuthorizationException::class);
});

it('lets only members holding users.invite send invitations', function (): void {
    $editor = invitationMember(1, 'editor');

    expect(fn () => invite(1, $editor, 'moi@example.test', 'viewer'))->toThrow(AuthorizationException::class);
});

it('tells the inviter when the address is already a member here', function (): void {
    $owner = invitationMember(1, 'owner');
    $member = invitationMember(1, 'viewer');

    expect(invitationRefusal(fn () => invite(1, $owner, $member->email)))->toBe('already_member');
});

it('refreshes the open invitation instead of stacking a second one', function (): void {
    $owner = invitationMember(1, 'owner');
    $first = invite(1, $owner, 'moi@example.test');

    expect(invitationRefusal(fn () => invite(1, $owner, 'moi@example.test')))->toBe('resend_too_soon');

    $this->travel(InviteMember::RESEND_COOLDOWN_SECONDS + 1)->seconds();
    $second = invite(1, $owner, 'moi@example.test', 'author');

    expect($second->invitation->id)->toBe($first->invitation->id)
        ->and($second->token)->not->toBe($first->token)
        ->and(DB::connection('pgsql_admin')->table('invitations')->count())->toBe(1)
        ->and(invitationRefusal(fn () => app(Tenancy::class)->enter(1, fn () => app(AcceptInvitation::class)->find($first->token))))->toBe('invalid')
        ->and(app(Tenancy::class)->enter(1, fn (): array => $second->invitation->fresh()->roles()->pluck('name')->all()))->toBe(['author']);
});

it('caps invitation mail per site per 24 hours', function (): void {
    $owner = invitationMember(1, 'owner');
    $viewer = invitationRole(1, 'viewer');

    app(Tenancy::class)->enter(1, function () use ($viewer): void {
        for ($i = 0; $i < InviteMember::DAILY_LIMIT; $i++) {
            $invitation = Invitation::query()->create([
                'email' => "da-moi-{$i}@example.test", 'token_hash' => hash('sha256', "token-{$i}"),
                'expires_at' => now()->addDays(7), 'sent_at' => now(),
            ]);
            $invitation->roles()->attach($viewer->getKey(), ['site_id' => 1]);
        }
    });

    expect(invitationRefusal(fn () => invite(1, $owner, 'them-mot@example.test')))->toBe('daily_limit');

    $this->travel(1)->days();
    $this->travel(1)->minutes();

    expect(invitationRefusal(fn () => invite(1, $owner, 'them-mot@example.test')))->toBeNull();
});
