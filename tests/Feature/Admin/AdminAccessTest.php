<?php

use App\Modules\Admin\Http\Middleware\ResolveLivewireContext;
use App\Modules\Admin\Livewire\Login;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
 * W1/W2 — admin shell + login. A fully provisioned An Vinh (owner + roles + a "Trang"
 * content_section) exercises the real defense chain: auth (login) → entitlement +
 * RBAC (the dynamic nav) → tenant-isolated Livewire updates. The admin lives on the
 * tenant's own host under /admin (P0.8).
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         platform_settings, site_locales, site_modules, content_sections, domains, sites, tenants,
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

it('redirects an admin-plane guest to the tenant login', function (): void {
    $this->get('http://anvinh.test/admin')->assertRedirect('http://anvinh.test/admin/login');
});

it('keeps the crawler-reachable login page out of search indexes', function (): void {
    $this->get('http://anvinh.test/admin/login')
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

it('accepts the owner credentials and challenges for the mandatory second factor', function (): void {
    Livewire::test(Login::class)
        ->set('email', 'owner@anvinh.test')
        ->set('password', 'a-strong-password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.otp.challenge'));

    // Mandatory second factor: not authenticated until the emailed OTP is verified.
    $this->assertGuest();
    expect(session('2fa.user_id'))->not->toBeNull();
});

it('rejects bad credentials without authenticating', function (): void {
    Livewire::test(Login::class)
        ->set('email', 'owner@anvinh.test')
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('shows the dashboard shell and the dynamic "Trang" nav to the owner', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    $this->actingAs($owner)->get('http://anvinh.test/admin')
        ->assertOk()
        ->assertSee(__('admin.dashboard'))
        ->assertSee(__('section.pages')); // the Pages section label, in the request's locale
});

it('hides the "Trang" nav from a member without entries.view (entitlement ∩ RBAC)', function (): void {
    $member = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($member, 'media-only', ['media.view']));

    $this->actingAs($member)->get('http://anvinh.test/admin')
        ->assertOk()
        ->assertSee(__('admin.dashboard'))
        // No entries.view → the Pages section is filtered out. Assert its section-scoped links are
        // gone (locale-independent + precise; the localized label "Trang" would collide with words
        // like "Trang chủ").
        ->assertDontSee('section=pages');
});

it('routes Livewire updates through the plane-aware tenant-context middleware', function (): void {
    $route = Route::getRoutes()->getByName('livewire.update');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain(ResolveLivewireContext::class)
        ->and(app('livewire')->getUpdateUri())->toBe('/livewire/update');
});

it('logs out and returns to the tenant login', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    // Real logout with CSRF on: seed the session token and post it back.
    $this->actingAs($owner)
        ->withSession(['_token' => 'admin-logout-token'])
        ->post('http://anvinh.test/admin/logout', ['_token' => 'admin-logout-token'])
        ->assertRedirect('http://anvinh.test/admin/login');

    $this->assertGuest();
});

it('rate-limits repeated failed logins (brute-force guard)', function (): void {
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
