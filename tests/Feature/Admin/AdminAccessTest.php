<?php

use App\Modules\Admin\Livewire\Login;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Http\Middleware\SetCurrentSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
 * W1/W2 — admin shell + login. A fully provisioned An Vinh (owner + roles + a "Trang"
 * content_section) exercises the real defense chain: auth (login) → entitlement +
 * RBAC (the dynamic nav) → tenant-isolated Livewire updates. The admin lives on the
 * tenant's own host under /admin (P0.8).
 */

beforeEach(function () {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();

    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'An Vinh',
        siteName: 'An Vinh',
        hostname: 'anvinh.test',
        ownerEmail: 'owner@anvinh.test',
        ownerName: 'An Vinh Owner',
        ownerPassword: 'a-strong-password',
        locales: ['vi'],
        modules: ['cms', 'media'],
    ));
});

it('redirects an admin-plane guest to the tenant login', function () {
    $this->get('http://anvinh.test/admin')->assertRedirect('http://anvinh.test/admin/login');
});

it('logs the owner in through the Livewire login', function () {
    Livewire::test(Login::class)
        ->set('email', 'owner@anvinh.test')
        ->set('password', 'a-strong-password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticated();
});

it('rejects bad credentials without authenticating', function () {
    Livewire::test(Login::class)
        ->set('email', 'owner@anvinh.test')
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('shows the dashboard shell and the dynamic "Trang" nav to the owner', function () {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    $this->actingAs($owner)->get('http://anvinh.test/admin')
        ->assertOk()
        ->assertSee('Bảng điều khiển')
        ->assertSee('Trang');
});

it('hides the "Trang" nav from a user without entries.view (entitlement ∩ RBAC)', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get('http://anvinh.test/admin')
        ->assertOk()
        ->assertSee('Bảng điều khiển')
        ->assertDontSee('Trang');
});

it('routes Livewire updates through the tenant-context middleware', function () {
    $route = Route::getRoutes()->getByName('livewire.update');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain(SetCurrentSite::class)
        ->and(app('livewire')->getUpdateUri())->toBe('/livewire/update');
});

it('logs out and returns to the tenant login', function () {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    // Real logout with CSRF on: seed the session token and post it back.
    $this->actingAs($owner)
        ->withSession(['_token' => 'admin-logout-token'])
        ->post('http://anvinh.test/admin/logout', ['_token' => 'admin-logout-token'])
        ->assertRedirect('http://anvinh.test/admin/login');

    $this->assertGuest();
});

it('rate-limits repeated failed logins (brute-force guard)', function () {
    foreach (range(1, 5) as $ignored) {
        Livewire::test(Login::class)
            ->set('email', 'owner@anvinh.test')->set('password', 'wrong-password')
            ->call('login')->assertHasErrors('email');
    }

    // 6th attempt is throttled — blocked even with the CORRECT password.
    Livewire::test(Login::class)
        ->set('email', 'owner@anvinh.test')->set('password', 'a-strong-password')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});
