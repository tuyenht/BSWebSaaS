<?php

use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use Illuminate\Support\Facades\DB;

/*
 * GĐ4 — dev-only design-system gallery + admin shell pass-2 (brand-from-config, topbar
 * search, footer). Structural / render / gating tests only; Velzon visual fidelity is
 * reviewed by eye at the gallery, not asserted here.
 */

beforeEach(function (): void {
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

it('shows the component gallery to an authed admin (non-production)', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    $this->actingAs($owner)->get('http://anvinh.test/admin/_gallery')
        ->assertOk()
        ->assertSee('Component Gallery')
        ->assertSee('Buttons')
        ->assertSee('Badges')
        ->assertSee('Inputs')
        ->assertSee('Hoạt động gần đây');
});

it('returns 404 for the gallery in production', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    $this->actingAs($owner)->get('http://anvinh.test/admin/_gallery')->assertNotFound();
});

it('renders the shell brand (from config), search and footer', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    $this->actingAs($owner)->get('http://anvinh.test/admin/_gallery')
        ->assertOk()
        ->assertSee(config('admin.brand.name'))
        ->assertSee(config('admin.brand.tagline'))
        ->assertSee(__('admin.search_placeholder'))
        ->assertSee(__('auth.rights'));
});

it('renders button + badge variants with Velzon-mirrored admin tokens', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    $this->actingAs($owner)->get('http://anvinh.test/admin/_gallery')
        ->assertOk()
        ->assertSee('bg-admin-primary')      // solid
        ->assertSee('bg-admin-danger')       // danger solid
        ->assertSee('bg-admin-success/10')   // soft (subtle bg)
        ->assertSee('border-admin-primary'); // outline
});

it('renders the new presentational primitives (alert, avatar, page-header, pagination)', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    $this->actingAs($owner)->get('http://anvinh.test/admin/_gallery')
        ->assertOk()
        ->assertSee('Alerts')
        ->assertSee('Avatars')
        ->assertSee('Pagination')
        ->assertSee('role="alert"', false)         // alert a11y role
        ->assertSee('aria-current="page"', false); // pagination current page
});

it('renders the interactive primitives (dropdown, tabs, tooltip, modal) with a11y roles', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    $this->actingAs($owner)->get('http://anvinh.test/admin/_gallery')
        ->assertOk()
        ->assertSee('Dropdown')
        ->assertSee('Tabs')
        ->assertSee('Modal')
        ->assertSee('aria-haspopup="true"', false) // dropdown
        ->assertSee('role="tablist"', false)      // tabs
        ->assertSee('role="dialog"', false)       // modal
        ->assertSee('aria-modal="true"', false);  // modal
});
