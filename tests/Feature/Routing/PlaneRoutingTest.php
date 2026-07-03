<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;

/*
 * P0.8 — two-plane routing skeleton. Three planes with distinct middleware stacks:
 *   • storefront — public, tenant context
 *   • admin      — tenant context + auth (on the tenant's own domain, /admin)
 *   • platform   — operator host, auth + platform role
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

// ── Storefront ────────────────────────────────────────────────────────────────
it('serves the storefront on a tenant host within its context', function (): void {
    seedHomepage(1, 'A home');
    seedHomepage(2, 'B home');

    expect($this->get('http://a.test/')->assertOk()->getContent())->toContain('A home');
    expect($this->get('http://b.test/')->assertOk()->getContent())->toContain('B home');
});

it('404s the storefront on an unknown host', function (): void {
    $this->get('http://nope.test/')->assertNotFound();
});

it('refuses a suspended site', function (): void {
    seedHomepage(1, 'A home');
    DB::connection('pgsql_admin')->table('sites')->where('id', 2)->update(['status' => 'suspended']);

    $this->get('http://a.test/')->assertOk();
    $this->get('http://b.test/')->assertForbidden();
});

// ── Tenant admin ────────────────────────────────────────────────────────────────
it('redirects an unauthenticated visitor on the admin plane to the tenant login', function (): void {
    $this->get('http://a.test/admin')->assertRedirect('http://a.test/admin/login');
});

it('serves the admin shell to a site member in tenant context', function (): void {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    $this->actingAs($user)->get('http://a.test/admin')
        ->assertOk()
        ->assertSee(__('admin.dashboard'));
});

// ── Platform ────────────────────────────────────────────────────────────────────
it('redirects an unauthenticated visitor on the platform plane to login', function (): void {
    $this->get('http://platform.bswebsaas.test/')->assertRedirect('http://platform.bswebsaas.test/login');
});

it('forbids a non-operator on the platform plane', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('http://platform.bswebsaas.test/')->assertForbidden();
});

it('lands an authenticated platform operator on the Tenants console', function (): void {
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('super_admin'));

    // 2FA is enforced at login (TOTP or email-OTP), not by a console middleware gate; the
    // platform root now lands the operator on the Tenants list (S0 — the operator console's
    // home), replacing the earlier System-Email redirect.
    $this->actingAs($user)->get('http://platform.bswebsaas.test/')
        ->assertRedirect(route('platform.tenants.index'));
});

it('serves the System Email console to a super_admin operator', function (): void {
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('super_admin'));

    $this->actingAs($user)->get(route('platform.settings.email'))
        ->assertOk()
        ->assertSee(__('admin.system_email'));
});
