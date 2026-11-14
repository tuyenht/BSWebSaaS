<?php

use App\Modules\Admin\Livewire\Users\Index;
use App\Modules\Cms\Actions\SetMemberCategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Actions\AssignRole;
use App\Modules\Rbac\Actions\CreateCustomRole;
use App\Modules\Rbac\Actions\DeleteCustomRole;
use App\Modules\Rbac\Actions\InviteMember;
use App\Modules\Rbac\Actions\RevokeInvitation;
use App\Modules\Rbac\Actions\RevokeRole;
use App\Modules\Rbac\Actions\UpdateCustomRole;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\MembershipLock;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Rbac\Support\RoleCeiling;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteFeature;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Platform staff who open a customer website's admin directly — not through a support session —
 * pass every tenant gate (super_admin, Gate::before). They still invite, remove, re-role and make
 * nobody an owner there, alone or in a bulk selection: the screen says why, and the actions refuse
 * on their own too. Staff who hold a real role on the website act as that member, no more: one
 * who is only an editor there manages nobody, though the platform gate would let a super_admin
 * through. The website's own owner and admins keep doing all of it.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate users, audit_logs restart identity cascade');   // the invitation daily limits count audit rows
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    Notification::fake();
});

function staffLockMember(string $name, int $site = 1): User
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

/** A platform super_admin; with $memberAs, also a member of site 1 holding that role. */
function staffLockStaff(?string $memberAs = null): User
{
    $staff = $memberAs === null ? User::factory()->create() : staffLockMember($memberAs);
    app(PlatformAccess::class)->onPlatform(fn () => $staff->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    return $staff;
}

function staffLockRoles(User $user, int $site = 1): array
{
    return app(Tenancy::class)->enter($site, fn (): array => $user->fresh()?->roles()->orderBy('name')->pluck('name')->all() ?? []);
}

function staffLockPage(User $actor, Closure $then): mixed
{
    return app(Tenancy::class)->enter(1, fn () => $then(Livewire::actingAs($actor->fresh())->test(Index::class)));
}

function staffLockRole(string $name, int $site = 1): Role
{
    return app(Tenancy::class)->enter($site, fn (): Role => Role::query()->where('site_id', $site)->where('name', $name)->firstOrFail());
}

/**
 * Site 1 may keep custom roles, and has one ("Cộng tác", below an editor) that nobody holds: the
 * role actions then have nothing left to stop staff but the membership lock.
 */
function staffLockCustomRole(): Role
{
    return app(Tenancy::class)->enter(1, function (): Role {
        SiteFeature::query()->create(['feature_key' => 'rbac.custom_roles', 'value' => true]);
        $role = Role::findOrCreate('Cộng tác', PermissionRegistry::GUARD);
        $role->forceFill(['level' => 15, 'is_system' => false])->save();
        $role->syncPermissions(['entries.view']);

        return $role;
    });
}

/** The custom roles of site 1 as name => level, so a refused create, edit or delete shows as no change. */
function staffLockCustomRoles(): array
{
    return app(Tenancy::class)->enter(1, fn (): array => Role::query()->where('site_id', 1)->where('is_system', false)
        ->orderBy('name')->pluck('level', 'name')->all());
}

it('refuses direct platform staff every member change on the screen, saying why', function (): void {
    $owner = staffLockMember('owner');
    $member = staffLockMember('viewer');
    staffLockMember('editor');
    $staff = staffLockStaff();

    staffLockPage($staff, fn (Testable $page) => $page
        // the rows show the reason instead of opening anything
        ->assertSee(__('admin.staff.locked'))
        // change a role
        ->call('openPanel', $member->ulid)
        ->assertSet('panelFor', '')
        ->assertSet('toast', __('admin.staff.locked'))
        ->set('panelFor', $member->ulid)->set('panelRoles', ['editor'])->call('saveRoles')
        // grant "Chủ website"
        ->set('panelFor', $member->ulid)->set('panelRoles', ['owner'])->call('saveRoles')
        // a forged undo
        ->set('undoRoles', ['ulid' => $member->ulid, 'roles' => ['owner']])->call('undo')
        // remove
        ->call('askRemove', $member->ulid)
        ->assertSet('confirmOpen', false)
        ->set('removeFor', $member->ulid)->call('confirmRemove')
        ->set('removeFor', $owner->ulid)->call('confirmRemove')
        // invite
        ->call('openInvite')
        ->assertSet('inviteOpen', false)
        ->set('inviteEmail', 'nguoi-moi-'.Str::lower(Str::random(8)).'@example.test')->set('inviteRoles', ['owner'])->call('sendInvites')
        ->assertSet('inviteResults', []));

    expect(staffLockRoles($member))->toBe(['viewer'])
        ->and(staffLockRoles($owner))->toBe(['owner'])
        ->and(staffLockRoles($staff))->toBe([])
        ->and(app(Tenancy::class)->enter(1, fn (): int => Invitation::query()->count()))->toBe(0);
    Notification::assertNothingSent();
});

it('refuses direct platform staff a bulk change, whatever mix of rows is ticked', function (): void {
    $owner = staffLockMember('owner');
    $admin = staffLockMember('admin');
    $viewer = staffLockMember('viewer');
    staffLockMember('editor');
    $staff = staffLockStaff();

    staffLockPage($staff, fn (Testable $page) => $page
        ->call('togglePick', [$owner->ulid, $admin->ulid, $viewer->ulid])
        ->call('openBulkPanel')
        ->assertSet('panelBulk', false)
        ->assertSet('toast', __('admin.staff.locked'))
        // forged: the bulk panel "open" and saved anyway, then a forged bulk removal
        ->set('panelBulk', true)->set('panelRoles', ['owner'])->call('saveRoles')
        ->call('askRemovePicked')
        ->assertSet('confirmOpen', false)
        ->set('removeBulk', true)->call('confirmRemove'));

    expect(staffLockRoles($owner))->toBe(['owner'])
        ->and(staffLockRoles($admin))->toBe(['admin'])
        ->and(staffLockRoles($viewer))->toBe(['viewer']);
});

it('refuses direct platform staff on the invitations tab: no mail again, no withdrawal', function (): void {
    $owner = staffLockMember('owner');
    staffLockMember('viewer');
    $viewerRole = staffLockRole('viewer');
    app(Tenancy::class)->enter(1, fn () => app(InviteMember::class)->handle($owner->fresh(), 'cho-'.Str::lower(Str::random(8)).'@example.test', [$viewerRole]));
    $invitation = app(Tenancy::class)->enter(1, fn (): Invitation => Invitation::query()->firstOrFail());
    $staff = staffLockStaff();

    staffLockPage($staff, fn (Testable $page) => $page
        ->set('tab', 'loi-moi')
        ->call('resendInvitation', $invitation->ulid)
        ->assertSet('toast', __('admin.staff.locked'))
        ->call('reinvite', $invitation->ulid)
        ->call('askRevoke', $invitation->ulid)
        ->set('revokeFor', $invitation->ulid)->call('confirmRevoke'));

    $after = app(Tenancy::class)->enter(1, fn (): Invitation => Invitation::query()->firstOrFail());
    expect($after->revoked_at)->toBeNull()
        ->and($after->sent_at->equalTo($invitation->sent_at))->toBeTrue();
    Notification::assertNothingSent();
});

it('refuses direct platform staff in the actions themselves, not only on the screen', function (string $path): void {
    $owner = staffLockMember('owner');
    $member = staffLockMember('viewer');
    $viewerRole = staffLockRole('viewer');
    app(Tenancy::class)->enter(1, fn () => app(InviteMember::class)->handle($owner->fresh(), 'cho-'.Str::lower(Str::random(8)).'@example.test', [$viewerRole]));
    $custom = staffLockCustomRole();
    $staff = staffLockStaff();

    $ownerRole = staffLockRole('owner');

    expect(fn () => app(Tenancy::class)->enter(1, fn () => match ($path) {
        'assign owner' => app(AssignRole::class)->handle($staff->fresh(), $member->fresh(), $ownerRole),
        'revoke' => app(RevokeRole::class)->handle($staff->fresh(), $owner->fresh(), $ownerRole),
        'invite' => app(InviteMember::class)->handle($staff->fresh(), 'khac-'.Str::lower(Str::random(8)).'@example.test', [$ownerRole]),
        'withdraw' => app(RevokeInvitation::class)->handle($staff->fresh(), Invitation::query()->firstOrFail()),
        'create a role' => app(CreateCustomRole::class)->handle($staff->fresh(), 'Nhóm mới', 15, ['entries.view']),
        'edit a role' => app(UpdateCustomRole::class)->handle($staff->fresh(), $custom->fresh(), 'Cộng tác đổi tên', 15, []),
        'delete a role' => app(DeleteCustomRole::class)->handle($staff->fresh(), $custom->fresh()),
    }))->toThrow(AuthorizationException::class, __('admin.staff.locked'));

    expect(staffLockRoles($member))->toBe(['viewer'])
        ->and(staffLockRoles($owner))->toBe(['owner'])
        ->and(staffLockCustomRoles())->toBe(['Cộng tác' => 15]);
})->with(['assign owner', 'revoke', 'invite', 'withdraw', 'create a role', 'edit a role', 'delete a role']);

it('refuses a grant of another website\'s role made from a site where the staff member belongs', function (): void {
    $staff = staffLockStaff('owner');               // a real owner of site 1
    $member = staffLockMember('viewer', 2);
    staffLockMember('owner', 2);

    $ownerB = staffLockRole('owner', 2);

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(AssignRole::class)->handle($staff->fresh(), $member->fresh(), $ownerB)))
        ->toThrow(AuthorizationException::class);
    expect(staffLockRoles($member, 2))->toBe(['viewer']);
});

it('lets staff who are a real member of the site act as that member, and no more', function (): void {
    staffLockMember('owner');
    $staff = staffLockStaff('admin');
    $member = staffLockMember('viewer');
    staffLockMember('editor');

    staffLockPage($staff, fn (Testable $page) => $page
        ->assertDontSee(__('admin.staff.locked'))
        ->call('openPanel', $member->ulid)
        ->assertSet('panelFor', $member->ulid)
        // above their own level as a member: refused like any admin
        ->set('panelRoles', ['owner'])->call('saveRoles')
        ->assertSet('panelError', __('admin.role_change_denied'))
        ->set('panelRoles', ['editor'])->call('saveRoles')
        ->assertHasNoErrors());

    expect(staffLockRoles($member))->toBe(['editor']);
});

it('keeps the site\'s own owner able to invite, re-role, make an owner and remove', function (): void {
    $owner = staffLockMember('owner');
    $member = staffLockMember('viewer');
    $leaver = staffLockMember('viewer');
    staffLockMember('admin');

    staffLockPage($owner, fn (Testable $page) => $page
        ->assertDontSee(__('admin.staff.locked'))
        ->call('openPanel', $member->ulid)
        ->set('panelRoles', ['admin'])->call('saveRoles')
        ->assertSet('panelError', '')
        ->call('askRemove', $leaver->ulid)
        ->assertSet('confirmOpen', true)
        ->call('confirmRemove')
        ->call('openInvite')
        ->set('inviteEmail', 'nguoi-moi-'.Str::lower(Str::random(8)).'@example.test')->set('inviteRoles', ['viewer'])->call('sendInvites')
        ->assertHasNoErrors());

    expect(staffLockRoles($member))->toBe(['admin'])
        ->and(staffLockRoles($leaver))->toBe([])
        ->and(app(Tenancy::class)->enter(1, fn (): int => Invitation::query()->count()))->toBe(1);
});

it('names staff on a site as outside only when they hold no role there', function (): void {
    $outside = staffLockStaff();
    $inside = staffLockStaff('editor');
    $owner = staffLockMember('owner');
    $lock = app(MembershipLock::class);

    $verdicts = app(Tenancy::class)->enter(1, fn (): array => [
        $lock->outsideStaff($outside->fresh(), 1),
        $lock->outsideStaff($inside->fresh(), 1),
        $lock->outsideStaff($owner->fresh(), 1),
        $lock->outsideStaff($outside->fresh(), PermissionRegistry::PLATFORM_TEAM_ID),
    ]);

    expect($verdicts)->toBe([true, false, false, false]);
});

it('shows staff who are only an editor on the site the no-permission card, and every member write is refused', function (string $method, array $args): void {
    $owner = staffLockMember('owner');
    $member = staffLockMember('viewer');
    $viewerRole = staffLockRole('viewer');
    app(Tenancy::class)->enter(1, fn () => app(InviteMember::class)->handle($owner->fresh(), 'cho-'.Str::lower(Str::random(8)).'@example.test', [$viewerRole]));
    $invitation = app(Tenancy::class)->enter(1, fn (): Invitation => Invitation::query()->firstOrFail());
    $staff = staffLockStaff('editor');
    $args = array_map(fn (string $arg): string => match ($arg) {
        'member' => $member->ulid,
        'invitation' => $invitation->ulid,
        default => $arg,
    }, $args);

    staffLockPage($staff, fn (Testable $page) => $page
        ->assertSee(__('admin.members.noperm_title'))
        ->assertDontSee($member->email)
        ->call($method, ...$args)
        ->assertForbidden());

    $after = app(Tenancy::class)->enter(1, fn (): Invitation => Invitation::query()->firstOrFail());
    expect(staffLockRoles($member))->toBe(['viewer'])
        ->and(staffLockRoles($owner))->toBe(['owner'])
        ->and(app(Tenancy::class)->enter(1, fn (): int => Invitation::query()->count()))->toBe(1)
        ->and($after->revoked_at)->toBeNull();
})->with([
    'open a member' => ['openPanel', ['member']],
    'save roles' => ['saveRoles', []],
    'ask to remove' => ['askRemove', ['member']],
    'remove' => ['confirmRemove', []],
    'open the invite form' => ['openInvite', []],
    'send invitations' => ['sendInvites', []],
    'open the bulk panel' => ['openBulkPanel', []],
    'mail an invitation again' => ['resendInvitation', ['invitation']],
    'withdraw an invitation' => ['confirmRevoke', []],
]);

it('refuses staff who are only an editor on the site in the actions themselves', function (string $path): void {
    $owner = staffLockMember('owner');
    $member = staffLockMember('viewer');
    $other = staffLockMember('contributor');
    $viewerRole = staffLockRole('viewer');
    app(Tenancy::class)->enter(1, fn () => app(InviteMember::class)->handle($owner->fresh(), 'cho-'.Str::lower(Str::random(8)).'@example.test', [$viewerRole]));
    $custom = staffLockCustomRole();
    // every viewer permission is one an editor holds: only the missing users.* / roles.manage stop them
    $staff = staffLockStaff('editor');

    expect(fn () => app(Tenancy::class)->enter(1, fn () => match ($path) {
        'assign' => app(AssignRole::class)->handle($staff->fresh(), $other->fresh(), $viewerRole),
        'revoke' => app(RevokeRole::class)->handle($staff->fresh(), $member->fresh(), $viewerRole),
        'invite' => app(InviteMember::class)->handle($staff->fresh(), 'khac-'.Str::lower(Str::random(8)).'@example.test', [$viewerRole]),
        'withdraw' => app(RevokeInvitation::class)->handle($staff->fresh(), Invitation::query()->firstOrFail()),
        'category limits' => app(SetMemberCategoryScopes::class)->handle($staff->fresh(), $member->fresh(), []),
        'create a role' => app(CreateCustomRole::class)->handle($staff->fresh(), 'Nhóm mới', 15, ['entries.view']),
        'edit a role' => app(UpdateCustomRole::class)->handle($staff->fresh(), $custom->fresh(), 'Cộng tác đổi tên', 15, []),
        'delete a role' => app(DeleteCustomRole::class)->handle($staff->fresh(), $custom->fresh()),
    }))->toThrow(AuthorizationException::class);

    expect(staffLockRoles($member))->toBe(['viewer'])
        ->and(staffLockRoles($other))->toBe(['contributor'])
        ->and(app(Tenancy::class)->enter(1, fn (): array => [
            Invitation::query()->count(),
            Invitation::query()->whereNotNull('revoked_at')->count(),
        ]))->toBe([1, 0])
        ->and(staffLockCustomRoles())->toBe(['Cộng tác' => 15]);
})->with(['assign', 'revoke', 'invite', 'withdraw', 'category limits', 'create a role', 'edit a role', 'delete a role']);

it('bounds what staff may put in a role by their own role on the site, not the whole site', function (): void {
    $outside = staffLockStaff();
    $editor = staffLockStaff('editor');
    $ceiling = PermissionRegistry::TENANT_PERMISSIONS;

    [$forOutside, $forEditor] = app(Tenancy::class)->enter(1, fn (): array => [
        app(RoleCeiling::class)->grantableBy($outside->fresh(), $ceiling),
        app(RoleCeiling::class)->grantableBy($editor->fresh(), $ceiling),
    ]);

    $editorHolds = PermissionRegistry::tenantRoleTemplates('corporate')['editor']['permissions'];
    expect($forOutside)->toBe($ceiling)
        ->and($forEditor)->not->toContain('users.manage')
        ->and($forEditor)->not->toContain('roles.manage')
        ->and(array_diff($forEditor, $editorHolds))->toBe([]);
});
