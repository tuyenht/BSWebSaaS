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
    seedHomepage(1, 'A home');
    seedHomepage(2, 'B home');

    expect($this->get('http://a.test/')->assertOk()->getContent())->toContain('A home');
    expect($this->get('http://b.test/')->assertOk()->getContent())->toContain('B home');
});

it('404s the storefront on an unknown host', function () {
    $this->get('http://nope.test/')->assertNotFound();
});

it('refuses a suspended site', function () {
    seedHomepage(1, 'A home');
    DB::connection('pgsql_admin')->table('sites')->where('id', 2)->update(['status' => 'suspended']);

    $this->get('http://a.test/')->assertOk();
    $this->get('http://b.test/')->assertForbidden();
});

// ── Tenant admin ────────────────────────────────────────────────────────────────
it('redirects an unauthenticated visitor on the admin plane to the tenant login', function () {
    $this->get('http://a.test/admin')->assertRedirect('http://a.test/admin/login');
});

it('serves the admin shell to an authenticated user in tenant context', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('http://a.test/admin')
        ->assertOk()
        ->assertSee('Bảng điều khiển');
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
