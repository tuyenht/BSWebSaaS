<?php

use App\Modules\Admin\Livewire\Users\Index;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Livewire\Livewire;

/*
 * The members page is for `users.manage` (owner/admin). A member of the site without it gets the
 * no-permission card, never the list, and every action answers 403. The gate is site-scoped — `users.manage` on one site is not
 * membership on another. (The mutations themselves route through the guarded AssignRole/RevokeRole
 * actions, covered by RoleHierarchyGuardTest / RevokeRoleTest.)
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('lets a member with users.manage open the users page', function (): void {
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'admin', ['users.manage', 'entries.view']));

    $this->actingAs($admin)->get('http://a.test/admin/users')
        ->assertOk()
        ->assertSee(__('admin.members.title'))
        ->assertDontSee(__('admin.members.noperm_title'));
});

it('shows a member without users.manage the no-permission card, and refuses every action', function (): void {
    $author = User::factory()->create(['name' => 'Đỗ Thu Hà']);
    $other = User::factory()->create(['email' => 'nguoi-khac@example.test']);
    app(Tenancy::class)->enter(1, function () use ($author, $other): void {
        grantRole($author, 'author', ['entries.view']);
        grantRole($other, 'viewer', ['entries.view']);
    });

    $this->actingAs($author)->get('http://a.test/admin/users')
        ->assertOk()
        ->assertSee(__('admin.members.noperm_title'))
        ->assertDontSee('nguoi-khac@example.test');

    app(Tenancy::class)->enter(1, function () use ($author, $other): void {
        foreach ([['openPanel', $other->ulid], ['askRemove', $other->ulid], ['openInvite'], ['sendInvites'], ['older', $other->ulid]] as $call) {
            Livewire::actingAs($author->fresh())->test(Index::class)->call(...$call)->assertForbidden();
        }
    });
});

it('scopes the users page per site — manage on site A is not membership on site B', function (): void {
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'admin', ['users.manage', 'entries.view']));

    $this->actingAs($admin)->get('http://b.test/admin/users')->assertForbidden();
});
