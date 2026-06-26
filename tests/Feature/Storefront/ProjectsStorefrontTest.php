<?php

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Actions\SeedAnVinhContent;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\DB;

/*
 * The seeded An Vinh "Dự án" collection is reachable on the storefront: a paginated list at
 * /du-an and each project at /du-an/{slug}, via the generic renderer (no entry_grid needed for
 * these pages — that block drives the homepage). Project detail inherits the BreadcrumbList
 * JSON-LD from the shared SEO head.
 */

beforeEach(function () {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, routes, entry_translations, entries,
         site_profile_translations, site_profiles, site_settings, site_locales,
         site_modules, content_sections, domains, sites, tenants, tenant_users, users
         restart identity cascade'
    );
    truncateRbac();
});

function seedAnVinhStorefront(): void
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'An Vinh', siteName: 'An Vinh', hostname: 'anvinh.test',
        ownerEmail: 'owner@anvinh.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));
    $site = Site::query()->firstWhere('name', 'An Vinh');
    app(Tenancy::class)->enter($site->id, fn () => app(SeedAnVinhContent::class)->handle());
}

it('lists the projects at /du-an within budget', function () {
    seedAnVinhStorefront();

    $count = countQueries(function () {
        $html = $this->get('http://anvinh.test/du-an')->assertOk()->getContent();

        expect($html)
            ->toContain('class="category__title">Dự án')
            ->toContain('Formosa Hà Tĩnh')
            ->toContain('href="/du-an/formosa-ha-tinh"');
    });

    // 17 = the tin-tuc list baseline (16) + 1 fixed query for the richer An Vinh nav (3 items
    // vs 2). NOT an N+1: MenuResolver batches route/title lookups and renderCategory eager-loads
    // translations, so 9 projects add the same +1 that 2 would (an N+1 would add ~9).
    expect($count)->toBeLessThanOrEqual(17);
});

it('renders a project detail with breadcrumb JSON-LD', function () {
    seedAnVinhStorefront();

    $html = $this->get('http://anvinh.test/du-an/formosa-ha-tinh')->assertOk()->getContent();

    expect($html)
        ->toContain('<title>Formosa Hà Tĩnh</title>')
        ->toContain('class="post__breadcrumb"')
        ->toContain('href="/du-an"')                 // breadcrumb back to the category
        ->toContain('"@type":"BreadcrumbList"')      // inherited from the PR-A shared head
        ->toContain('"name":"Dự án"');
});

it('lists the services at /dich-vu within budget', function () {
    seedAnVinhStorefront();

    $count = countQueries(function () {
        $html = $this->get('http://anvinh.test/dich-vu')->assertOk()->getContent();

        expect($html)
            ->toContain('class="category__title">Dịch vụ')
            ->toContain('Cho thuê xe nâng')
            ->toContain('href="/dich-vu/cho-thue-xe-nang"');
    });

    expect($count)->toBeLessThanOrEqual(17);   // same shape as /du-an; nav batched, not per-item
});

it('renders a service detail page (click → detail) with breadcrumb', function () {
    seedAnVinhStorefront();

    $html = $this->get('http://anvinh.test/dich-vu/cho-thue-xe-nang')->assertOk()->getContent();

    expect($html)
        ->toContain('<title>Cho thuê xe nâng</title>')
        ->toContain('class="post__breadcrumb"')
        ->toContain('href="/dich-vu"')               // breadcrumb back to the services category
        ->toContain('"@type":"BreadcrumbList"')
        ->toContain('"name":"Dịch vụ"');
});
