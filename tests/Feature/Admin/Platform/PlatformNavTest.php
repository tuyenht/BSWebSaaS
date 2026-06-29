<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * S0 — operator console nav (platform plane @ PLATFORM_HOST). The topbar nav is data-driven
 * by platform permissions (PlatformNav): a link shows only when its route EXISTS and the
 * operator may see it. super_admin sees all; support sees its view-scoped sections but NOT
 * the super-only Settings; a tenant user is forbidden on the platform host; a guest is sent
 * to the platform login (redirectGuestsTo host branch — fixes the JSON-stub fall-through).
 */

beforeEach(function () {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app(PlatformAccess::class)->forgetMemo();
});

it('shows the full operator nav to a super_admin', function () {
    $host = (string) config('tenancy.platform_host');
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();
    $user = $user->fresh();

    $this->actingAs($user)->get("http://{$host}/tenants")
        ->assertOk()
        ->assertSee(route('platform.tenants.index'))
        ->assertSee(route('platform.settings.email')); // super-only Settings link present
});

it('shows support the Tenants section but not the super-only Settings', function () {
    $host = (string) config('tenancy.platform_host');
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('support'));
    app(PlatformAccess::class)->forgetMemo();
    $user = $user->fresh();

    $this->actingAs($user)->get("http://{$host}/tenants")
        ->assertOk()
        ->assertSee(route('platform.tenants.index'))
        ->assertDontSee(route('platform.settings.email')); // Settings is super_admin-only
});

it('forbids a tenant user (no platform role) on the platform host', function () {
    $host = (string) config('tenancy.platform_host');
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'admin', ['users.manage', 'entries.view']));

    $this->actingAs($user)->get("http://{$host}/tenants")->assertForbidden();
});

it('redirects a guest on the platform host to the platform login (not the JSON stub)', function () {
    $host = (string) config('tenancy.platform_host');

    $this->get("http://{$host}/tenants")->assertRedirect(route('platform.login'));
});
