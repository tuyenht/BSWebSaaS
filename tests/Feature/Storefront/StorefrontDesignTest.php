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
use Illuminate\Support\Facades\DB;

/*
 * Storefront visual design + SEO/a11y invariants:
 *   - head.blade.php references the storefront stylesheet
 *   - BreadcrumbList JSON-LD is valid JSON (moved to @php block; '@context'/'@type' safe)
 *   - 404 renders a branded Vietnamese page (no English dead-end)
 *   - every storefront page has exactly one <h1>
 *   - every storefront page has a <footer> contentinfo landmark
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

function designProvision(): void
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
    // (platform default is flat; these design tests model a category-preset site, like An Vinh).
    $site = Site::query()->where('name', 'An Vinh')->firstOrFail();
    app(Tenancy::class)->enter($site->id, function (): void {
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'permalinks', 'key' => 'posts'],
            ['value' => ['pattern' => 'category']],
        );
    });
}

function designPublishPost(string $title, string $slug): void
{
    $site = Site::query()->firstWhere('name', 'An Vinh');

    app(Tenancy::class)->enter($site->id, function () use ($title, $slug): void {
        $category = Taxonomy::query()->where('vocabulary', 'post_cat')->first();
        $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $owner->id]);
        $post->translations()->create([
            'locale' => 'vi', 'title' => $title, 'slug' => $slug,
            'status' => 'draft', 'body' => ['blocks' => []],
        ]);
        $post->assignCategories([$category->id]);
        app(PublishEntry::class)->handle($post->fresh(), 'vi', $owner->fresh());
    });
}

// ─── Stylesheet linkage ───────────────────────────────────────────────────────

it('head.blade.php references the storefront stylesheet via @vite', function (): void {
    // Template-level assertion: the @vite directive is wired correctly.
    // Rendering tests use withoutVite() (TestCase), so we check the source template
    // rather than the compiled HTML output.
    $head = file_get_contents(base_path('themes/default/partials/head.blade.php'));

    expect($head)->toContain('resources/css/storefront.css');
});

it('vite.config.js includes storefront.css as a build input', function (): void {
    $config = file_get_contents(base_path('vite.config.js'));

    expect($config)->toContain('resources/css/storefront.css');
});

// ─── BreadcrumbList JSON-LD validity ─────────────────────────────────────────

it('BreadcrumbList JSON-LD is valid decodable JSON on a post page', function (): void {
    designProvision();
    designPublishPost('Bài kiểm tra JSON-LD', 'bai-kiem-tra-json-ld');

    $html = $this->get('http://anvinh.platform.test/tin-tuc/bai-kiem-tra-json-ld')->assertOk()->getContent();

    // Extract the first application/ld+json script block.
    $matched = preg_match(
        '/<script type="application\/ld\+json">(.*?)<\/script>/s',
        (string) $html,
        $m,
    );

    expect($matched)->toBe(1, 'No application/ld+json script block found');

    $decoded = json_decode($m[1]);

    expect($decoded)->not->toBeNull('JSON-LD failed to decode: '.json_last_error_msg());
    expect($decoded->{'@type'})->toBe('BreadcrumbList');
    expect($decoded->{'@context'})->toBe('https://schema.org');

    // Confirm the breadcrumb trail: Home → category → post
    $items = $decoded->itemListElement;
    expect(count($items))->toBe(3);
    expect($items[0]->position)->toBe(1);
    expect($items[2]->name)->toBe('Bài kiểm tra JSON-LD');
});

// ─── Branded Vietnamese 404 ───────────────────────────────────────────────────

it('renders a branded Vietnamese 404 page (no English dead-end)', function (): void {
    designProvision();

    $response = $this->get('http://anvinh.platform.test/khong-ton-tai-xyz');
    $response->assertNotFound();

    $html = $response->getContent();

    // Must have Vietnamese content and a Trang chủ link — never an English dead-end.
    expect($html)
        ->toContain('404')
        ->toContain('Trang chủ')
        ->toContain('Không tìm thấy')
        ->toContain('href="/"');
});

// ─── Single h1 per page ───────────────────────────────────────────────────────

it('every storefront page has exactly one h1 element', function (): void {
    designProvision();
    designPublishPost('Bài đầu tiên', 'bai-dau-tien');

    $pages = [
        'home page' => 'http://anvinh.platform.test/',
        'contact page' => 'http://anvinh.platform.test/lien-he',
        'category list' => 'http://anvinh.platform.test/tin-tuc',
        'post detail' => 'http://anvinh.platform.test/tin-tuc/bai-dau-tien',
    ];

    foreach ($pages as $label => $url) {
        $html = $this->get($url)->assertOk()->getContent();
        $count = preg_match_all('/<h1[\s>\/]/', (string) $html);

        expect($count)->toBe(1, "Expected exactly 1 <h1> on {$label}, found {$count}");
    }
});

// ─── Footer presence ─────────────────────────────────────────────────────────

it('every storefront layout renders a footer contentinfo landmark', function (): void {
    designProvision();
    designPublishPost('Post footer test', 'post-footer-test');

    $urls = [
        'http://anvinh.platform.test/',
        'http://anvinh.platform.test/lien-he',
        'http://anvinh.platform.test/tin-tuc',
        'http://anvinh.platform.test/tin-tuc/post-footer-test',
    ];

    foreach ($urls as $url) {
        $html = $this->get($url)->assertOk()->getContent();

        expect(str_contains((string) $html, '<footer'))->toBeTrue("Missing <footer on {$url}");
        expect(str_contains((string) $html, 'site-footer'))->toBeTrue("Missing .site-footer class on {$url}");
    }
});

// ─── og:image per-entry ───────────────────────────────────────────────────────

it('emits og:image for a post that has a featured image in meta', function (): void {
    designProvision();

    $site = Site::query()->firstWhere('name', 'An Vinh');

    app(Tenancy::class)->enter($site->id, function (): void {
        $category = Taxonomy::query()->where('vocabulary', 'post_cat')->first();
        $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

        // Post with a featured image key in meta — mirrors SeedAnVinhContent::firstOrCreatePost.
        $post = Entry::query()->create([
            'type' => 'post',
            'status' => 'draft',
            'author_id' => $owner->id,
            'meta' => ['image_url' => 'an-vinh/tin-tuc/tt-team-building-2024-5a967c5f98dd.jpg'],
        ]);
        $post->translations()->create([
            'locale' => 'vi', 'title' => 'Post with image', 'slug' => 'post-with-image',
            'status' => 'draft', 'body' => ['blocks' => []],
        ]);
        $post->assignCategories([$category->id]);
        app(PublishEntry::class)->handle($post->fresh(), 'vi', $owner->fresh());
    });

    $html = $this->get('http://anvinh.platform.test/tin-tuc/post-with-image')->assertOk()->getContent();

    // The og:image should point at the R2 key resolved via MediaUrlResolver (any non-empty URL).
    expect($html)
        ->toContain('property="og:image"')
        ->toContain('tt-team-building-2024');
});
