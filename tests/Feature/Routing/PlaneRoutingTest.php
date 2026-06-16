<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use Illuminate\Support\Facades\DB;

/*
 * P0.8 — two-plane routing skeleton. Three planes with distinct middleware stacks:
 *   • storefront — public, tenant context
 *   • admin      — tenant context + auth (on the tenant's own domain, /admin)
 *   • platform   — operator host, auth + platform role
 */

beforeEach(function () {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

// ── Storefront ────────────────────────────────────────────────────────────────
it('serves the storefront on a tenant host within its context', function () {
    $this->getJson('http://a.test/')->assertOk()->assertJson(['plane' => 'storefront', 'site_id' => 1]);
    $this->getJson('http://b.test/')->assertOk()->assertJson(['plane' => 'storefront', 'site_id' => 2]);
});

it('404s the storefront on an unknown host', function () {
    $this->getJson('http://nope.test/')->assertNotFound();
});

it('refuses a suspended site', function () {
    DB::connection('pgsql_admin')->table('sites')->where('id', 2)->update(['status' => 'suspended']);

    $this->getJson('http://a.test/')->assertOk();
    $this->getJson('http://b.test/')->assertForbidden();
});

// ── Tenant admin ────────────────────────────────────────────────────────────────
it('redirects an unauthenticated visitor on the admin plane to login', function () {
    $this->get('http://a.test/admin')->assertRedirect('http://a.test/login');
});

it('serves the admin plane to an authenticated user in tenant context', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('http://a.test/admin')
        ->assertOk()
        ->assertJson(['plane' => 'admin', 'site_id' => 1]);
});

// ── Platform ────────────────────────────────────────────────────────────────────
it('redirects an unauthenticated visitor on the platform plane to login', function () {
    $this->get('http://platform.bswebsaas.test/')->assertRedirect('http://platform.bswebsaas.test/login');
});

it('forbids a non-operator on the platform plane', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('http://platform.bswebsaas.test/')->assertForbidden();
});

it('sends a platform operator without 2FA to the setup flow', function () {
    $user = User::factory()->create(['two_factor_confirmed_at' => null]);
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('super_admin'));

    $this->actingAs($user)->get('http://platform.bswebsaas.test/')
        ->assertRedirect('http://platform.bswebsaas.test/two-factor/setup');
});

it('serves the platform plane to a super_admin operator with 2FA', function () {
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('super_admin'));

    $this->actingAs($user)->getJson('http://platform.bswebsaas.test/')
        ->assertOk()
        ->assertJson(['plane' => 'platform', 'operator' => $user->email]);
});
