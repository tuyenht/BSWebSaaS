<?php

use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Chrome\StorefrontChrome;
use Illuminate\Support\Facades\DB;

/*
 * SEO head baseline (PR-A): every storefront page emits canonical + Open Graph through the
 * shared theme::partials.head (no per-layout drift); content pages with a trail emit a
 * BreadcrumbList JSON-LD. Tags are built from already-resolved request data, so the head adds
 * no queries (budget unchanged). JSON-LD is encoded server-side with tag-hex escaping.
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

function seoProvision(): void
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'An Vinh',
        siteName: 'An Vinh',
        hostname: 'anvinh.platform.test',
        ownerEmail: 'owner@anvinh.test',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-password',
    ));

    // Pin the `posts` section to the category preset so provisioned posts route /{category}/{slug}
    // (platform default is flat; these SEO tests model a category-preset site, like An Vinh).
    $site = Site::query()->where('name', 'An Vinh')->firstOrFail();
    app(Tenancy::class)->enter($site->id, function (): void {
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'permalinks', 'key' => 'posts'],
            ['value' => ['pattern' => 'category']],
        );
    });
}

function seoPublishPost(string $title, string $slug): void
{
    $site = Site::query()->firstWhere('name', 'An Vinh');

    app(Tenancy::class)->enter($site->id, function () use ($title, $slug): void {
        $category = Taxonomy::query()->where('vocabulary', 'post_cat')->first();
        $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $owner->id]);
        $post->translations()->create([
            'locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []],
        ]);
        $post->assignCategories([$category->id]);
        app(PublishEntry::class)->handle($post->fresh(), 'vi', $owner->fresh());
    });
}

it('emits canonical + Open Graph (website) on the home page', function (): void {
    seoProvision();

    $html = $this->get('http://anvinh.platform.test/')->assertOk()->getContent();

    expect($html)
        ->toContain('<link rel="canonical"')
        ->toContain('property="og:title"')
        ->toContain('property="og:type" content="website"')
        ->toContain('property="og:url"')
        ->toContain('<title>An Vinh</title>');   // no regression
});

it('emits canonical + OG article + BreadcrumbList on a post, no extra queries', function (): void {
    seoProvision();
    seoPublishPost('Bài viết đầu tiên', 'bai-viet-dau-tien');

    $count = countQueries(function (): void {
        $html = $this->get('http://anvinh.platform.test/tin-tuc/bai-viet-dau-tien')->assertOk()->getContent();

        expect($html)
            ->toContain('<link rel="canonical" href="http://anvinh.platform.test/tin-tuc/bai-viet-dau-tien">')
            ->toContain('property="og:type" content="article"')
            ->toContain('application/ld+json')
            ->toContain('"@type":"BreadcrumbList"')
            ->toContain('"name":"Bài viết đầu tiên"')
            ->toContain('"name":"Tin tức"')          // category crumb
            ->toContain('<title>Bài viết đầu tiên</title>')  // no regression
            ->toContain('class="post__breadcrumb"');         // existing HTML breadcrumb kept
    });

    expect($count)->toBeLessThanOrEqual(16);   // SEO reuses resolved data → no new queries
});

it('emits canonical + OG website + BreadcrumbList on a category list', function (): void {
    seoProvision();
    seoPublishPost('Bài một', 'bai-mot');

    $html = $this->get('http://anvinh.platform.test/tin-tuc')->assertOk()->getContent();

    expect($html)
        ->toContain('<link rel="canonical" href="http://anvinh.platform.test/tin-tuc">')
        ->toContain('property="og:type" content="website"')
        ->toContain('"@type":"BreadcrumbList"')
        ->toContain('"name":"Tin tức"');
});

it('self-canonicals a paginated category page with ?page=N', function (): void {
    seoProvision();
    $site = Site::query()->firstWhere('name', 'An Vinh');

    app(Tenancy::class)->enter($site->id, function (): void {
        $category = Taxonomy::query()->where('vocabulary', 'post_cat')->first();
        $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

        // 13 posts → 2 pages at POSTS_PER_PAGE=12, so page 2 exists.
        for ($i = 1; $i <= 13; $i++) {
            $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $owner->id]);
            $post->translations()->create([
                'locale' => 'vi', 'title' => "Bài {$i}", 'slug' => "bai-{$i}", 'status' => 'draft', 'body' => ['blocks' => []],
            ]);
            $post->assignCategories([$category->id]);
            app(PublishEntry::class)->handle($post->fresh(), 'vi', $owner->fresh());
        }
    });

    // Page 2 self-canonicals to itself (not page 1) so the deeper page stays indexable.
    $html = $this->get('http://anvinh.platform.test/tin-tuc?page=2')->assertOk()->getContent();

    expect($html)->toContain('<link rel="canonical" href="http://anvinh.platform.test/tin-tuc?page=2">');
});

it('uses the tenant brand favicon when set, and the platform file only as fallback', function (): void {
    seoProvision();

    // No brand favicon yet → platform fallback.
    $html = $this->get('http://anvinh.platform.test/')->assertOk()->getContent();
    expect($html)->toContain('<link rel="icon" href="/favicon.ico"');

    // A tenant that sets brand.favicon gets its own icon — no platform branding leak.
    $site = Site::query()->firstWhere('name', 'An Vinh');
    app(Tenancy::class)->enter($site->id, function (): void {
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'general', 'key' => 'brand'],
            ['value' => ['name' => 'An Vinh', 'favicon' => 'https://files.example.test/an-vinh/brand/logo-64.png']],
        );
    });

    $html = $this->get('http://anvinh.platform.test/')->assertOk()->getContent();
    expect($html)->toContain('<link rel="icon" href="https://files.example.test/an-vinh/brand/logo-64.png"');
    expect($html)->not->toContain('<link rel="icon" href="/favicon.ico"');
});

it('preloads the one Inter Latin and Vietnamese file only for a site whose font is Inter', function (): void {
    seoProvision();
    $site = Site::query()->firstWhere('name', 'An Vinh');
    $preload = '<link rel="preload" href="/fonts/inter-latin-vi.woff2" as="font" type="font/woff2" crossorigin>';

    expect($this->get('http://anvinh.platform.test/')->assertOk()->getContent())->not->toContain('rel="preload"');

    app(Tenancy::class)->enter($site->id, fn () => SiteSetting::query()->create(
        ['group_name' => 'theme', 'key' => 'tokens', 'value' => ['font' => 'Inter, Inter-Fallback, system-ui, sans-serif']],
    ));

    $html = $this->get('http://anvinh.platform.test/')->assertOk()->getContent();
    expect($html)->toContain($preload)
        ->and(substr_count($html, 'as="font"'))->toBe(1);
});

it('inlines the text-size script only when the header offers the button, and every inline script is allowed by its CSP hash', function (): void {
    seoProvision();
    $site = Site::query()->firstWhere('name', 'An Vinh');
    $setHeader = fn (array $header) => app(Tenancy::class)->enter($site->id, fn () => SiteSetting::query()->updateOrCreate(
        ['group_name' => 'storefront', 'key' => 'header'], ['value' => $header],
    ));

    $setHeader(['text_size' => false]);
    $without = $this->get('http://anvinh.platform.test/')->assertOk();
    $setHeader(['text_size' => true]);
    $with = $this->get('http://anvinh.platform.test/')->assertOk();

    expect($without->getContent())->not->toContain(StorefrontChrome::TEXT_SIZE_BOOT)
        ->and($with->getContent())->toMatch('#<head>.*<script>'.preg_quote(StorefrontChrome::TEXT_SIZE_BOOT, '#').'</script>.*</head>#s');

    // Every <script> loads a file, is JSON-LD, or is inline text whose sha256 the page's CSP lists
    // (an edit to the boot script that forgets the hash fails here, not silently in browsers).
    $inlineCount = [];
    foreach (['without' => $without, 'with' => $with] as $case => $response) {
        $csp = (string) $response->headers->get('Content-Security-Policy');
        preg_match_all('/<script(?![^>]*\ssrc=)(?![^>]*\stype="application\/ld\+json")[^>]*>(.*?)<\/script>/s', (string) $response->getContent(), $inline);
        foreach ($inline[1] as $code) {
            expect($csp)->toContain("'sha256-".base64_encode(hash('sha256', $code, true))."'");
        }
        $inlineCount[$case] = count($inline[1]);
    }
    expect($inlineCount)->toBe(['without' => 0, 'with' => 1]);
});
