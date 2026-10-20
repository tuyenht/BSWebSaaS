<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Actions\SeedAnVinhContent;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteSetting;
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

it('sitemap includes published catalog product routes and excludes drafts', function (): void {
    sitemapSeedAnVinh();
    $site = Site::query()->firstWhere('name', 'An Vinh');

    // Route a live + a draft product under the pinned catalog base (/san-pham/{slug}).
    app(Tenancy::class)->enter($site->id, function (): void {
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'permalinks', 'key' => 'products'],
            ['value' => ['pattern' => 'base', 'base' => 'san-pham']],
        );

        $live = Product::query()->create(['status' => 'published', 'published_at' => now()]);
        $live->translations()->create(['locale' => 'vi', 'name' => 'Trục CNC', 'slug' => 'truc-cnc-live']);
        app(RouteSync::class)->syncProduct($live->id, null, 'vi', 'truc-cnc-live');

        $draft = Product::query()->create(['status' => 'draft']);
        $draft->translations()->create(['locale' => 'vi', 'name' => 'Nháp', 'slug' => 'khuon-nhap']);
        app(RouteSync::class)->syncProduct($draft->id, null, 'vi', 'khuon-nhap');
    });

    $xml = $this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent();

    expect($xml)
        ->toContain('truc-cnc-live')    // published product route present in the sitemap
        ->not->toContain('khuon-nhap'); // draft product route excluded (status = draft)
});

/*
 * Sitemap index (sitemaps.org caps one file at 50,000 URLs). The threshold is lowered through
 * config so the split is proven without seeding 50,000 routes.
 */

/** @return list<string> every <url>…</url> block of a urlset, in document order */
function sitemapUrlBlocks(string $xml): array
{
    preg_match_all('#<url>.*?</url>#', $xml, $matches);

    return $matches[0];
}

/** @return list<string> every <loc> of a sitemapindex, in document order */
function sitemapIndexLocs(string $xml): array
{
    preg_match_all('#<sitemap><loc>(.*?)</loc></sitemap>#', $xml, $matches);

    return $matches[1];
}

it('keeps a site at or under the limit on a single urlset with no pages', function (): void {
    sitemapSeedAnVinh();

    $xml = $this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent();
    $count = count(sitemapUrlBlocks($xml));

    // Exactly at the limit is still one file.
    config(['cms.sitemap.max_urls' => $count]);
    $atLimit = $this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent();

    expect($atLimit)->toBe($xml)
        ->and($xml)->not->toContain('<sitemapindex')
        ->and($xml)->not->toContain('<id>');

    // A small site has no numbered pages: /sitemap.xml is the whole sitemap.
    $this->get('http://anvinh.test/sitemap-1.xml')->assertNotFound();
});

it('splits a site over the limit into a sitemapindex of keyset pages covering every URL once', function (): void {
    sitemapSeedAnVinh();

    $full = sitemapUrlBlocks($this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent());
    expect(count($full))->toBeGreaterThan(4);

    $max = 3;
    config(['cms.sitemap.max_urls' => $max]);
    $pages = (int) ceil(count($full) / $max);

    $response = $this->get('http://anvinh.test/sitemap.xml')->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/xml');

    $index = $response->getContent();
    expect($index)
        ->toStartWith('<?xml version="1.0" encoding="UTF-8"?>')
        ->toContain('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">')
        ->not->toContain('<urlset');

    $expectedLocs = array_map(fn (int $n): string => "http://anvinh.test/sitemap-{$n}.xml", range(1, $pages));
    expect(sitemapIndexLocs($index))->toBe($expectedLocs);

    $seen = [];
    foreach (range(1, $pages) as $n) {
        $page = $this->get("http://anvinh.test/sitemap-{$n}.xml")->assertOk();
        expect($page->headers->get('Content-Type'))->toContain('application/xml');
        $blocks = sitemapUrlBlocks($page->getContent());
        expect(count($blocks))->toBeGreaterThan(0)->toBeLessThanOrEqual($max);
        $seen = [...$seen, ...$blocks];
    }

    // Same URLs, same order, none twice: the pages are the small-site urlset cut into slices.
    expect($seen)->toBe($full);
});

it('returns 404 for a sitemap page outside the range', function (string $page): void {
    sitemapSeedAnVinh();
    config(['cms.sitemap.max_urls' => 3]);

    $pages = count(sitemapIndexLocs($this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent()));
    expect($pages)->toBeGreaterThan(1);

    $path = $page === 'past-the-end' ? 'sitemap-'.($pages + 1).'.xml' : $page;
    $this->get("http://anvinh.test/{$path}")->assertNotFound();
})->with(['past-the-end', 'sitemap-0.xml', 'sitemap-01.xml', 'sitemap-x.xml']);

it('keeps sitemap pages tenant-scoped: site B pages never list site A routes', function (): void {
    sitemapSeedAnVinh();
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Other Co', siteName: 'Other Co', hostname: 'other.test',
        ownerEmail: 'owner@other.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));

    $otherFull = sitemapUrlBlocks($this->get('http://other.test/sitemap.xml')->assertOk()->getContent());
    expect(count($otherFull))->toBeGreaterThan(1);

    config(['cms.sitemap.max_urls' => 1]);

    // Build site A's page plan first so a cache keyed wrongly would leak into site B.
    $this->get('http://anvinh.test/sitemap.xml')->assertOk();
    $this->get('http://anvinh.test/sitemap-1.xml')->assertOk();

    $otherLocs = sitemapIndexLocs($this->get('http://other.test/sitemap.xml')->assertOk()->getContent());
    expect($otherLocs)->toHaveCount(count($otherFull))
        ->each->toStartWith('http://other.test/sitemap-');

    $seen = [];
    foreach (range(1, count($otherLocs)) as $n) {
        $xml = $this->get("http://other.test/sitemap-{$n}.xml")->assertOk()->getContent();
        expect($xml)->not->toContain('anvinh.test')->not->toContain('/san-pham');
        $seen = [...$seen, ...sitemapUrlBlocks($xml)];
    }
    expect($seen)->toBe($otherFull);
});

it('robots.txt keeps pointing at /sitemap.xml when the site is split', function (): void {
    sitemapSeedAnVinh();
    config(['cms.sitemap.max_urls' => 1]);

    expect($this->get('http://anvinh.test/robots.txt')->assertOk()->getContent())
        ->toContain('Sitemap: http://anvinh.test/sitemap.xml');
});
