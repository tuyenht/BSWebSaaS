<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * The tenant Users & Roles page is gated by `users.manage` (owner/admin). A member without it
 * is forbidden, and the gate is site-scoped — `users.manage` on one site is not membership on
 * another. (The mutations themselves route through the guarded AssignRole/RevokeRole actions,
 * covered by RoleHierarchyGuardTest / RevokeRoleTest.)
 */

beforeEach(function () {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('lets a member with users.manage open the users page', function () {
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'admin', ['users.manage', 'entries.view']));

    $this->actingAs($admin)->get('http://a.test/admin/users')
        ->assertOk()
        ->assertSee(__('admin.users'));
});

it('forbids a member without users.manage', function () {
    $author = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($author, 'author', ['entries.view']));

    $this->actingAs($author)->get('http://a.test/admin/users')->assertForbidden();
});

it('scopes the users page per site — manage on site A is not membership on site B', function () {
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'admin', ['users.manage', 'entries.view']));

    $this->actingAs($admin)->get('http://b.test/admin/users')->assertForbidden();
});
