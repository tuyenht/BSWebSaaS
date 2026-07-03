<?php

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Actions\SeedAnVinhContent;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\DB;

/*
 * SitemapController + robots.txt dynamic route (§5 SEO bundle).
 * Verifies: GET /sitemap.xml returns valid XML urlset scoped to the active tenant (RLS),
 * contains the expected paths from seeded An Vinh content, and GET /robots.txt includes
 * the per-host Sitemap: directive.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, routes, entry_translations, entries,
         site_profile_translations, site_profiles, site_settings, site_locales,
         site_modules, content_sections, domains, sites, tenants, tenant_users, users
         restart identity cascade'
    );
    truncateRbac();
});

/** Provision + fully seed the An Vinh site (same steps as seedAnVinhStorefront in ProjectsStorefrontTest). */
function sitemapSeedAnVinh(): void
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'An Vinh', siteName: 'An Vinh', hostname: 'anvinh.test',
        ownerEmail: 'owner@anvinh.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));
    $site = Site::query()->firstWhere('name', 'An Vinh');
    app(Tenancy::class)->enter($site->id, fn () => app(SeedAnVinhContent::class)->handle());
}

it('GET /sitemap.xml returns 200 application/xml with a valid urlset', function (): void {
    sitemapSeedAnVinh();

    $response = $this->get('http://anvinh.test/sitemap.xml')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/xml');

    $xml = $response->getContent();
    expect($xml)
        ->toContain('<?xml version="1.0" encoding="UTF-8"?>')
        ->toContain('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">')
        ->toContain('</urlset>');
});

it('sitemap contains expected An Vinh paths as absolute URLs', function (): void {
    sitemapSeedAnVinh();

    $xml = $this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent();

    // Top-level pages and taxonomy list routes seeded by ProvisionSite + SeedAnVinhContent
    expect($xml)
        ->toContain('<loc>http://anvinh.test/</loc>')
        ->toContain('<loc>http://anvinh.test/gioi-thieu</loc>')
        ->toContain('<loc>http://anvinh.test/san-pham</loc>')
        ->toContain('<loc>http://anvinh.test/du-an</loc>')
        ->toContain('<loc>http://anvinh.test/dich-vu</loc>')
        ->toContain('<loc>http://anvinh.test/tin-tuc</loc>')
        ->toContain('<loc>http://anvinh.test/lien-he</loc>')
        ->toContain('<loc>http://anvinh.test/doi-tac</loc>');

    // At least one detail URL (post routes under a category prefix)
    expect($xml)->toContain('<loc>http://anvinh.test/du-an/day-chuyen-lap-rap-tu-dong</loc>');
});

it('sitemap is RLS-scoped: a second site does not see the first site routes', function (): void {
    sitemapSeedAnVinh();

    // Provision a second tenant — default ProvisionSite only (no An Vinh content),
    // so it has / and /lien-he and /tin-tuc but NOT /san-pham, /du-an, /dich-vu, etc.
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Other Co', siteName: 'Other Co', hostname: 'other.test',
        ownerEmail: 'owner@other.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));

    // An Vinh's sitemap has /san-pham (seeded only for site 1)
    $anVinhXml = $this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent();
    expect($anVinhXml)->toContain('/san-pham');

    // Other Co's sitemap must NOT contain /san-pham (RLS prevents cross-tenant leak)
    $otherXml = $this->get('http://other.test/sitemap.xml')->assertOk()->getContent();
    expect($otherXml)->not->toContain('/san-pham');
});

it('GET /robots.txt returns 200 with a per-host Sitemap: directive', function (): void {
    sitemapSeedAnVinh();

    $body = $this->get('http://anvinh.test/robots.txt')->assertOk()->getContent();

    expect($body)
        ->toContain('User-agent: *')
        ->toContain('Disallow:')
        ->toContain('Sitemap: http://anvinh.test/sitemap.xml');
});
