<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Actions\SeedAnVinhContent;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteLocale;
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

it('renders a small site byte for byte as before the index existed', function (): void {
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Other Co', siteName: 'Other Co', hostname: 'other.test',
        ownerEmail: 'owner@other.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));
    DB::connection('pgsql_admin')->statement("update routes set updated_at = '2026-01-02 03:04:05+00'");

    // Golden output of the pre-index controller (81a0d4d) for the same fixture.
    expect($this->get('http://other.test/sitemap.xml')->assertOk()->getContent())->toBe(
        '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        .'<url><loc>http://other.test/</loc><lastmod>2026-01-02T03:04:05+00:00</lastmod></url>'
        .'<url><loc>http://other.test/lien-he</loc><lastmod>2026-01-02T03:04:05+00:00</lastmod></url>'
        .'<url><loc>http://other.test/tin-tuc</loc><lastmod>2026-01-02T03:04:05+00:00</lastmod></url>'
        .'</urlset>'
    );
});

it('keeps a site at or under the limit on a single urlset with no pages', function (): void {
    sitemapSeedAnVinh();

    $xml = $this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent();
    $count = count(sitemapUrlBlocks($xml));

    // Exactly at the limit is still one file.
    config(['cms.sitemap.max_urls' => $count]);
    $atLimit = $this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent();

    expect($atLimit)->toBe($xml)
        ->and($xml)->not->toContain('<sitemapindex');

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

it('never lists a URL twice across pages when content is unpublished while the page plan is cached', function (): void {
    sitemapSeedAnVinh();
    $full = sitemapUrlBlocks($this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent());
    $max = 3;
    config(['cms.sitemap.max_urls' => $max]);
    $pages = count(sitemapIndexLocs($this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent()));
    expect($pages)->toBe((int) ceil(count($full) / $max));

    // Only a route before the last page's start shifts a later page's first URL back across
    // its upper bound; on the last page this test would prove nothing.
    $position = array_search(true, array_map(fn (string $block): bool => str_contains($block, '<loc>http://anvinh.test/gioi-thieu</loc>'), $full), true);
    expect($position)->toBeInt()->toBeLessThan(($pages - 1) * $max);

    // The plan is cached; unpublishing a page shifts every later route one slot to the left.
    $site = Site::query()->firstWhere('name', 'An Vinh');
    app(Tenancy::class)->enter($site->id, fn () => Entry::query()
        ->whereHas('translations', fn ($translation) => $translation->where('slug', 'gioi-thieu'))
        ->update(['status' => 'draft']));

    $locs = [];
    foreach (range(1, $pages) as $n) {
        $xml = $this->get("http://anvinh.test/sitemap-{$n}.xml")->assertOk()->getContent();
        expect(count(sitemapUrlBlocks($xml)))->toBeLessThanOrEqual($max);
        preg_match_all('#<loc>([^<]*)</loc>#', $xml, $matches);
        $locs = [...$locs, ...$matches[1]];
    }

    expect($locs)->not->toContain('http://anvinh.test/gioi-thieu')
        ->and(array_unique($locs))->toHaveCount(count($locs));
});

it('rebuilds a cached one-page plan once the site outgrows a single file', function (): void {
    sitemapSeedAnVinh();
    $count = count(sitemapUrlBlocks($this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent()));
    config(['cms.sitemap.max_urls' => $count]);

    // A probe of a page that does not exist yet caches the one-page plan of the small site.
    $this->get('http://anvinh.test/sitemap-1.xml')->assertNotFound();

    // One more listed route takes the site over the limit while that plan is still cached.
    $site = Site::query()->firstWhere('name', 'An Vinh');
    app(Tenancy::class)->enter($site->id, function (): void {
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'permalinks', 'key' => 'products'],
            ['value' => ['pattern' => 'base', 'base' => 'san-pham']],
        );
        $product = Product::query()->create(['status' => 'published', 'published_at' => now()]);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Mới', 'slug' => 'san-pham-moi']);
        app(RouteSync::class)->syncProduct($product->id, null, 'vi', 'san-pham-moi');
    });

    $index = $this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent();
    expect(sitemapIndexLocs($index))->toBe(['http://anvinh.test/sitemap-1.xml', 'http://anvinh.test/sitemap-2.xml']);

    $blocks = [
        ...sitemapUrlBlocks($this->get('http://anvinh.test/sitemap-1.xml')->assertOk()->getContent()),
        ...sitemapUrlBlocks($this->get('http://anvinh.test/sitemap-2.xml')->assertOk()->getContent()),
    ];
    expect($blocks)->toHaveCount($count + 1)
        ->and(implode('', $blocks))->toContain('san-pham-moi');
});

it('breaks ties on the route id when two languages share a path', function (): void {
    sitemapSeedAnVinh();
    $site = Site::query()->firstWhere('name', 'An Vinh');

    // A second public language whose product route has the same path as the Vietnamese one.
    app(Tenancy::class)->enter($site->id, function (): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'permalinks', 'key' => 'products'],
            ['value' => ['pattern' => 'base', 'base' => 'san-pham']],
        );
        $product = Product::query()->create(['status' => 'published', 'published_at' => now()]);
        foreach (['vi', 'en'] as $locale) {
            $product->translations()->create(['locale' => $locale, 'name' => 'Trục', 'slug' => 'truc-chung']);
            app(RouteSync::class)->syncProduct($product->id, null, $locale, 'truc-chung');
        }
    });
    expect(DB::connection('pgsql_admin')->table('routes')->where('site_id', $site->id)
        ->groupBy('path')->havingRaw('count(*) > 1')->pluck('path'))->not->toBeEmpty();

    $full = sitemapUrlBlocks($this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent());

    // One URL per page puts a page boundary between the routes that share a path: a cursor on
    // the path alone would list one of them twice and drop the other.
    config(['cms.sitemap.max_urls' => 1]);
    expect(sitemapIndexLocs($this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent()))->toHaveCount(count($full));

    $seen = [];
    foreach (range(1, count($full)) as $n) {
        $seen = [...$seen, ...sitemapUrlBlocks($this->get("http://anvinh.test/sitemap-{$n}.xml")->assertOk()->getContent())];
    }
    expect($seen)->toBe($full);
});

it('reads pages by keyset without OFFSET or COUNT, and serves a warm index without touching routes', function (): void {
    sitemapSeedAnVinh();
    config(['cms.sitemap.max_urls' => 3]);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $pages = count(sitemapIndexLocs($this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent()));
    foreach (range(1, $pages) as $n) {
        $this->get("http://anvinh.test/sitemap-{$n}.xml")->assertOk();
    }
    expect($queries)->not->toBeEmpty()
        ->each(fn ($sql) => $sql->not->toContain(' offset ')->not->toContain('count('));

    // With the page plan cached, the index is built from the plan alone.
    $queries = [];
    $this->get('http://anvinh.test/sitemap.xml')->assertOk();
    expect(array_filter($queries, fn (string $sql): bool => str_contains($sql, '"routes"')))->toBeEmpty();
});

it('serves a numbered page requested before the index', function (): void {
    sitemapSeedAnVinh();
    $full = sitemapUrlBlocks($this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent());
    config(['cms.sitemap.max_urls' => 3]);

    // No plan is cached yet: the page builds it.
    expect(sitemapUrlBlocks($this->get('http://anvinh.test/sitemap-2.xml')->assertOk()->getContent()))
        ->toBe(array_slice($full, 3, 3));
});

it('returns 404 rather than an empty urlset for a page the site no longer fills', function (): void {
    sitemapSeedAnVinh();
    config(['cms.sitemap.max_urls' => 3]);
    $pages = count(sitemapIndexLocs($this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent()));
    expect($pages)->toBeGreaterThan(1);

    // Every route goes while the page plan is still cached.
    $site = Site::query()->firstWhere('name', 'An Vinh');
    DB::connection('pgsql_admin')->table('routes')->where('site_id', $site->id)->delete();

    foreach (range(1, $pages) as $n) {
        $this->get("http://anvinh.test/sitemap-{$n}.xml")->assertNotFound();
    }
});

it('rebuilds the page plan when the cached value is malformed', function (): void {
    sitemapSeedAnVinh();
    $full = sitemapUrlBlocks($this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent());
    config(['cms.sitemap.max_urls' => 3]);
    $site = Site::query()->firstWhere('name', 'An Vinh');
    cache()->put('sitemap-plan:v1:'.$site->id.':3', [['/', 'not-an-id'], 'junk'], 3600);

    expect(sitemapIndexLocs($this->get('http://anvinh.test/sitemap.xml')->assertOk()->getContent()))
        ->toHaveCount((int) ceil(count($full) / 3));
    expect(sitemapUrlBlocks($this->get('http://anvinh.test/sitemap-1.xml')->assertOk()->getContent()))
        ->toBe(array_slice($full, 0, 3));
});
