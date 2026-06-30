<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * Operator console nav (platform plane). Data-driven by platform permissions (PlatformNav): a
 * link shows only when its route EXISTS and the operator may see it. Full matrix now that S0–S4
 * exist: super_admin sees every section; support sees the view-scoped ones (tenants/sites/audit)
 * but NOT the super-only Settings; billing (tenants.view only) sees just Tenants. A tenant user
 * is forbidden; a guest is sent to the platform login.
 */

beforeEach(function () {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app(PlatformAccess::class)->forgetMemo();
});

function navAs(string $role): User
{
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user->fresh();
}

it('shows super_admin every console section', function () {
    $host = (string) config('tenancy.platform_host');

    $this->actingAs(navAs('super_admin'))->get("http://{$host}/tenants")
        ->assertOk()
        ->assertSee(route('platform.tenants.index'))
        ->assertSee(route('platform.sites.index'))
        ->assertSee(route('platform.audit.index'))
        ->assertSee(route('platform.settings.email')); // super-only
});

it('shows support tenants+sites+audit but not the super-only Settings', function () {
    $host = (string) config('tenancy.platform_host');

    $this->actingAs(navAs('support'))->get("http://{$host}/tenants")
        ->assertOk()
        ->assertSee(route('platform.tenants.index'))
        ->assertSee(route('platform.sites.index'))
        ->assertSee(route('platform.audit.index'))
        ->assertDontSee(route('platform.settings.email'));
});

it('shows billing only the Tenants section', function () {
    $host = (string) config('tenancy.platform_host');

    $this->actingAs(navAs('billing'))->get("http://{$host}/tenants")
        ->assertOk()
        ->assertSee(route('platform.tenants.index'))
        ->assertDontSee(route('platform.sites.index'))
        ->assertDontSee(route('platform.audit.index'))
        ->assertDontSee(route('platform.settings.email'));
});

it('forbids a tenant user (no platform role) on the platform host', function () {
    $host = (string) config('tenancy.platform_host');
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'admin', ['users.manage', 'entries.view']));

    $this->actingAs($user)->get("http://{$host}/tenants")->assertForbidden();
});

it('redirects a guest on the platform host to the platform login', function () {
    $host = (string) config('tenancy.platform_host');

    $this->get("http://{$host}/tenants")->assertRedirect(route('platform.login'));
});
