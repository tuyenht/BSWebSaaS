<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Provisioning\Actions\ReflowSitePermalinks;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;

/*
 * Reflow a site's opted-in permalink presets back to flat: every published post + product route
 * moves to /{slug} and records a 301 from its old prefixed path. Same code path for dry-run
 * (rolled back, writes nothing) and real; a genuine slug collision is reported, never double-booked.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
});

/** A published post filed under a fresh category, its section pinned to $pattern, with a live route. */
function pinnedPost(string $sectionKey, string $catSlug, string $postSlug, string $pattern): Entry
{
    $taxonomy = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $taxonomy->translations()->create(['locale' => 'vi', 'name' => $catSlug, 'slug' => $catSlug]);

    ContentSection::query()->create([
        'key' => $sectionKey, 'label' => $sectionKey, 'entry_type' => 'post',
        'taxonomy_id' => $taxonomy->id, 'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);
    SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => $sectionKey, 'value' => ['pattern' => $pattern]]);

    $post = Entry::query()->create(['type' => 'post', 'status' => 'published']);
    $post->translations()->create(['locale' => 'vi', 'title' => $postSlug, 'slug' => $postSlug, 'status' => 'published', 'body' => ['blocks' => []]]);
    $post->assignCategories([$taxonomy->id]);

    app(PermalinkConfig::class)->flush();
    app(RouteSync::class)->sync($post->fresh() ?? $post, 'vi', $postSlug);

    return $post;
}

it('flattens a category post route to /{slug} and records a 301', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $post = pinnedPost('projects', 'du-an', 'day-chuyen', 'category');
        expect(SiteRoute::query()->where('path', '/du-an/day-chuyen')->where('entry_id', $post->id)->exists())->toBeTrue();

        $summary = app(ReflowSitePermalinks::class)->handle(dryRun: false);

        expect(SiteRoute::query()->where('entry_id', $post->id)->where('locale', 'vi')->value('path'))->toBe('/day-chuyen')
            ->and(Redirect::query()->where('from_path', '/du-an/day-chuyen')->where('to_path', '/day-chuyen')->where('status_code', 301)->exists())->toBeTrue()
            ->and(SiteSetting::query()->where('group_name', 'permalinks')->where('key', 'projects')->first()?->value)->toBe(['pattern' => 'flat'])
            ->and(collect($summary)->firstWhere('title', 'day-chuyen')['status'] ?? null)->toBe('moved');
    });
});

it('flattens a base product route to /{slug} and records a 301', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $taxonomy = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 0]);
        $taxonomy->translations()->create(['locale' => 'vi', 'name' => 'San pham', 'slug' => 'san-pham']);
        ContentSection::query()->create([
            'key' => 'products', 'label' => 'Products', 'entry_type' => 'post',
            'taxonomy_id' => $taxonomy->id, 'permission_prefix' => 'entries', 'sort_order' => 3,
        ]);
        SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'products', 'value' => ['pattern' => 'base', 'base' => 'san-pham']]);

        $product = Product::query()->create(['status' => 'published', 'sku' => 'SKU-1', 'price' => 1000000, 'published_at' => now()]);
        $product->translations()->create(['locale' => 'vi', 'name' => 'May CNC', 'slug' => 'may-cnc']);
        $product->assignCategories([$taxonomy->id]);

        app(PermalinkConfig::class)->flush();
        app(RouteSync::class)->syncProduct($product->id, $product->taxonomies()->first(), 'vi', 'may-cnc');
        expect(SiteRoute::query()->where('path', '/san-pham/may-cnc')->where('product_id', $product->id)->exists())->toBeTrue();

        app(ReflowSitePermalinks::class)->handle(dryRun: false);

        expect(SiteRoute::query()->where('product_id', $product->id)->where('locale', 'vi')->value('path'))->toBe('/may-cnc')
            ->and(Redirect::query()->where('from_path', '/san-pham/may-cnc')->where('to_path', '/may-cnc')->where('status_code', 301)->exists())->toBeTrue();
    });
});

it('writes nothing on a dry run but reports the would-be moves', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        pinnedPost('projects', 'du-an', 'day-chuyen', 'category');

        $summary = app(ReflowSitePermalinks::class)->handle(dryRun: true);

        expect(SiteRoute::query()->where('path', '/du-an/day-chuyen')->exists())->toBeTrue()
            ->and(SiteRoute::query()->where('path', '/day-chuyen')->exists())->toBeFalse()
            ->and(SiteSetting::query()->where('group_name', 'permalinks')->where('key', 'projects')->first()?->value)->toBe(['pattern' => 'category'])
            ->and(collect($summary)->firstWhere('title', 'day-chuyen')['status'] ?? null)->toBe('moved');
    });
});

it('reports a slug collision instead of double-booking a flat path', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        pinnedPost('sec-a', 'cat-a', 'foo', 'category');
        pinnedPost('sec-b', 'cat-b', 'foo', 'category');

        $summary = app(ReflowSitePermalinks::class)->handle(dryRun: false);

        $statuses = collect($summary)->pluck('status');
        expect($statuses->filter(fn (string $s): bool => $s === 'moved')->count())->toBe(1)
            ->and($statuses->filter(fn (string $s): bool => $s === 'conflict')->count())->toBe(1)
            ->and(SiteRoute::query()->where('path', '/foo')->count())->toBe(1);
    });
});
