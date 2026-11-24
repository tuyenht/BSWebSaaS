<?php

use App\Modules\Admin\Livewire\Users\Index;
use App\Modules\Admin\Support\Members\InvitationDirectory;
use App\Modules\Admin\Support\Members\MemberDirectory;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Actions\DeleteCustomRole;
use App\Modules\Rbac\Actions\InviteMember;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * «Thành viên» with a custom role in the trash. The trash hides the role from every reader, but an
 * expired or withdrawn invitation can still name it (only a pending one holds a role back from the
 * trash): its role filter and counts must not list it, and mailing that invitation again, or
 * reinviting from it, is refused with a plain sentence instead of reaching the person with fewer
 * roles than it names.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate invitations, invitation_roles, audit_logs, users restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    Notification::fake();
});

function trashedRoleMember(string $template): User
{
    $tpl = PermissionRegistry::tenantRoleTemplates('corporate')[$template];
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($template, $tpl, $user): void {
        $role = Role::findOrCreate($template, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $tpl['level'], 'is_system' => true])->save();
        $role->syncPermissions($tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions']);
        $user->assignRole($role);
    });

    return $user;
}

/**
 * $owner invites $email with the viewer role and a custom role «Kế toán»; the invitation is then
 * set to $state and the custom role moved to the trash. Returns the invitation.
 */
function trashedRoleInvitation(User $owner, string $email, string $state): Invitation
{
    return app(Tenancy::class)->enter(1, function () use ($owner, $email, $state): Invitation {
        $custom = Role::findOrCreate('Kế toán', PermissionRegistry::GUARD);
        $custom->forceFill(['level' => 20, 'is_system' => false])->save();
        $custom->syncPermissions(['entries.view']);
        $viewer = Role::findByName('viewer', PermissionRegistry::GUARD);

        $invitation = app(InviteMember::class)->handle($owner->fresh(), $email, [$viewer, $custom])->invitation;
        Invitation::query()->whereKey($invitation->getKey())->update(match ($state) {
            'expired' => ['expires_at' => now()->subHour(), 'sent_at' => now()->subDays(8)],
            'revoked' => ['revoked_at' => now(), 'sent_at' => now()->subDays(8)],
        });
        app(DeleteCustomRole::class)->handle($owner->fresh(), $custom);

        return $invitation->fresh();
    });
}

it('refuses to mail again an expired invitation whose role went to the trash', function (): void {
    $owner = trashedRoleMember('owner');
    trashedRoleMember('viewer');
    $invitation = trashedRoleInvitation($owner, 'het-han@example.test', 'expired');
    $tokenHash = $invitation->token_hash;

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner->fresh())->test(Index::class)
        ->set('tab', 'loi-moi')->set('state', 'expired')
        ->call('resendInvitation', $invitation->ulid)
        ->assertSet('toast', __('admin.members.inv_role_trashed')));

    expect(app(Tenancy::class)->enter(1, fn (): ?string => $invitation->fresh()?->token_hash))->toBe($tokenHash);
    Notification::assertNothingSent();
});

it('refuses to reinvite from a withdrawn invitation whose role went to the trash', function (): void {
    $owner = trashedRoleMember('owner');
    trashedRoleMember('viewer');
    $invitation = trashedRoleInvitation($owner, 'da-huy@example.test', 'revoked');

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner->fresh())->test(Index::class)
        ->set('tab', 'loi-moi')->set('state', 'revoked')
        ->call('reinvite', $invitation->ulid)
        ->assertSet('toast', __('admin.members.inv_role_trashed'))
        ->assertSet('inviteOpen', false));
});

it('never counts or offers a role in the trash in the role filter', function (): void {
    $owner = trashedRoleMember('owner');
    $member = trashedRoleMember('viewer');
    trashedRoleInvitation($owner, 'het-han@example.test', 'expired');
    $trashed = Role::withTrashed()->where('site_id', 1)->where('name', 'Kế toán')->firstOrFail();
    // a holder written behind the actions' back is not counted either
    DB::connection('pgsql_admin')->table('model_has_roles')->insert(['role_id' => $trashed->getKey(), 'model_type' => User::class, 'model_id' => $member->id, 'site_id' => 1]);

    [$invitationCounts, $memberCounts, $matching] = app(Tenancy::class)->enter(1, fn (): array => [
        app(InvitationDirectory::class)->roleCounts('expired'),
        app(MemberDirectory::class)->counts(),
        app(InvitationDirectory::class)->matchCount('expired', '', ['Kế toán']),
    ]);

    expect($invitationCounts)->toBe(['viewer' => 1])
        ->and($memberCounts['roles'])->not->toHaveKey('Kế toán')
        ->and($matching)->toBe(0);
});
