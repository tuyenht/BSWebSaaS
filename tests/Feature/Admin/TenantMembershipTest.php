<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * H1 — tenant admin plane authority boundary (design.md §136 "role per site"). An
 * authenticated user reaches a site's /admin only if they hold a role on THAT site, or
 * are a platform operator (Bảo Sơn support). Authentication alone is not site authority,
 * so a valid account from one tenant cannot enter a foreign tenant's admin.
 */

beforeEach(function () {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('lets a site member into the admin shell', function () {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    $this->actingAs($user)->get('http://a.test/admin')->assertOk()->assertSee('Bảng điều khiển');
});

it('forbids an authenticated non-member', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get('http://a.test/admin')->assertForbidden();
});

it('lets a platform super_admin into any tenant admin (support)', function () {
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));

    $this->actingAs($operator)->get('http://a.test/admin')->assertOk();
});

it('scopes membership per site — a role on site A is not membership on site B', function () {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    $this->actingAs($user)->get('http://b.test/admin')->assertForbidden();
});
