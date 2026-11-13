<?php

use App\Modules\Admin\Livewire\Users\Index;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Ticking members and changing them all at once. The ticks are ulids that come back from the
 * browser: anything that is not a member of THIS site is dropped. Of the ticked members, the
 * actor changes only the ones strictly below their own level and never themselves — the rest are
 * named as skipped and left as they were, so nobody is lifted, and the last owner is never removed.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate users restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

function bulkPickMember(string $name, int $site = 1): User
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

function bulkPickRoles(User $user, int $site = 1): array
{
    return app(Tenancy::class)->enter($site, fn (): array => $user->fresh()?->roles()->orderBy('name')->pluck('name')->all() ?? []);
}

function bulkPickPage(User $actor, Closure $then): mixed
{
    return app(Tenancy::class)->enter(1, fn () => $then(Livewire::actingAs($actor->fresh())->test(Index::class)));
}

it('gives every ticked member it may change exactly the chosen roles, skipping itself and anyone at or above its level', function (): void {
    $admin = bulkPickMember('admin');
    $owner = bulkPickMember('owner');
    $editor = bulkPickMember('editor');
    $author = bulkPickMember('author');
    bulkPickMember('viewer');

    bulkPickPage($admin, fn (Testable $page) => $page
        ->call('togglePick', [$admin->ulid, $owner->ulid, $editor->ulid, $author->ulid])
        ->assertSee(__('admin.members.bulk_selected', ['n' => 4]))
        ->assertSee(__('admin.members.bulk_groups', ['n' => 2]))
        ->assertSee(__('admin.members.skip_level', ['name' => $owner->name]))
        ->call('openBulkPanel')
        ->assertSet('panelBulk', true)
        ->assertSee(__('admin.members.panel_title_many', ['n' => 2]))
        ->set('panelRoles', ['viewer'])
        ->call('saveRoles')
        ->assertSet('panelBulk', false)
        ->assertSet('picked', [])
        ->assertSet('toast', __('admin.members.saved_roles_many', ['n' => 2])));

    expect(bulkPickRoles($editor))->toBe(['viewer'])
        ->and(bulkPickRoles($author))->toBe(['viewer'])
        ->and(bulkPickRoles($owner))->toBe(['owner'])
        ->and(bulkPickRoles($admin))->toBe(['admin']);
});

it('never lets the bulk panel grant a role at or above the actor', function (): void {
    $admin = bulkPickMember('admin');
    $author = bulkPickMember('author');
    bulkPickMember('owner');

    bulkPickPage($admin, fn (Testable $page) => $page
        ->call('togglePick', [$author->ulid])
        ->call('openBulkPanel')
        ->set('panelRoles', ['owner'])
        ->call('saveRoles'));

    expect(bulkPickRoles($author))->toBe(['author']);
});

it('removes the ticked members it may change after one question, and leaves the skipped ones', function (): void {
    $admin = bulkPickMember('admin');
    $owner = bulkPickMember('owner');
    $editor = bulkPickMember('editor');
    $author = bulkPickMember('author');

    bulkPickPage($admin, fn (Testable $page) => $page
        ->call('togglePick', [$admin->ulid, $owner->ulid, $editor->ulid, $author->ulid])
        ->call('askRemovePicked')
        ->assertSet('confirmOpen', true)
        ->assertSee(__('admin.members.rm_title_many', ['n' => 2]))
        ->call('confirmRemove')
        ->assertSet('toast', __('admin.members.removed_many', ['n' => 2])));

    expect(bulkPickRoles($editor))->toBe([])
        ->and(bulkPickRoles($author))->toBe([])
        ->and(bulkPickRoles($owner))->toBe(['owner'])
        ->and(bulkPickRoles($admin))->toBe(['admin']);
});

it('drops ticks that are not members of this site, and refuses when nobody ticked may be changed', function (): void {
    $owner = bulkPickMember('owner');
    $outsider = bulkPickMember('viewer', 2);

    bulkPickPage($owner, fn (Testable $page) => $page
        ->call('togglePick', [$outsider->ulid, 'not-a-ulid', (string) $outsider->id, $owner->ulid])
        ->assertSet('picked', [$owner->ulid])
        ->call('askRemovePicked')
        ->assertSet('confirmOpen', false)
        ->assertSet('toast', __('admin.members.cannot_touch'))
        // a forged tick list is resolved again before anything changes
        ->set('picked', [$outsider->ulid])->set('removeBulk', true)->set('confirmOpen', true)->call('confirmRemove'));

    expect(bulkPickRoles($outsider, 2))->toBe(['viewer'])
        ->and(bulkPickRoles($owner))->toBe(['owner']);
});

it('unticks the whole page when every member on it is ticked already', function (): void {
    $owner = bulkPickMember('owner');
    $a = bulkPickMember('author');
    $b = bulkPickMember('viewer');

    bulkPickPage($owner, fn (Testable $page) => $page
        ->call('togglePick', [$a->ulid])
        ->call('togglePick', [$a->ulid, $b->ulid])
        ->assertCount('picked', 2)
        ->call('togglePick', [$a->ulid, $b->ulid])
        ->assertSet('picked', []));
});
