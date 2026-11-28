<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\Permalinks\ReflowSitePermalinks;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TransactionProbe;

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
    $post = app(Tenancy::class)->enter(1, function (): Entry {
        $post = pinnedPost('projects', 'du-an', 'day-chuyen', 'category');
        expect(SiteRoute::query()->where('path', '/du-an/day-chuyen')->where('entry_id', $post->id)->exists())->toBeTrue();

        return $post;
    });

    $summary = app(ReflowSitePermalinks::class)->handle(1, dryRun: false);

    app(Tenancy::class)->enter(1, function () use ($post, $summary): void {
        expect(SiteRoute::query()->where('entry_id', $post->id)->where('locale', 'vi')->value('path'))->toBe('/day-chuyen')
            ->and(Redirect::query()->where('from_path', '/du-an/day-chuyen')->where('to_path', '/day-chuyen')->where('status_code', 301)->exists())->toBeTrue()
            ->and(SiteSetting::query()->where('group_name', 'permalinks')->where('key', 'projects')->first()?->value)->toBe(['pattern' => 'flat'])
            ->and(collect($summary)->firstWhere('title', 'day-chuyen')['status'] ?? null)->toBe('moved');
    });
});

it('flattens a base product route to /{slug} and records a 301', function (): void {
    $product = app(Tenancy::class)->enter(1, function (): Product {
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

        return $product;
    });

    app(ReflowSitePermalinks::class)->handle(1, dryRun: false);

    app(Tenancy::class)->enter(1, function () use ($product): void {
        expect(SiteRoute::query()->where('product_id', $product->id)->where('locale', 'vi')->value('path'))->toBe('/may-cnc')
            ->and(Redirect::query()->where('from_path', '/san-pham/may-cnc')->where('to_path', '/may-cnc')->where('status_code', 301)->exists())->toBeTrue();
    });
});

it('writes nothing on a dry run but reports the would-be moves', function (): void {
    app(Tenancy::class)->enter(1, fn (): Entry => pinnedPost('projects', 'du-an', 'day-chuyen', 'category'));

    $probe = TransactionProbe::start();
    $summary = app(ReflowSitePermalinks::class)->handle(1, dryRun: true);
    expect($probe->savepoints)->toBe(0);

    app(Tenancy::class)->enter(1, function () use ($summary): void {
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
    });

    $summary = app(ReflowSitePermalinks::class)->handle(1, dryRun: false);

    app(Tenancy::class)->enter(1, function () use ($summary): void {
        $statuses = collect($summary)->pluck('status');
        expect($statuses->filter(fn (string $s): bool => $s === 'moved')->count())->toBe(1)
            ->and($statuses->filter(fn (string $s): bool => $s === 'conflict')->count())->toBe(1)
            ->and(SiteRoute::query()->where('path', '/foo')->count())->toBe(1);
    });
});

it('flattens chunk by chunk in short transactions with no savepoint, past an address that fails, and previews the same', function (): void {
    $posts = app(Tenancy::class)->enter(1, fn (): array => [
        pinnedPost('sec-a', 'cat-a', 'mot', 'category'),
        pinnedPost('sec-b', 'cat-b', 'hai', 'category'),
        pinnedPost('sec-c', 'cat-c', 'ba', 'category'),
    ]);
    // one address breaks as its route moves, after its 301 is written: a real database error, which
    // aborts the whole transaction
    SiteRoute::updating(function (SiteRoute $route): void {
        if ($route->path === '/hai') {
            DB::select('select 1/0');
        }
    });
    $routes = fn (): array => app(Tenancy::class)->enter(1, fn (): array => SiteRoute::query()->whereIn('entry_id', array_map(fn (Entry $post): int => $post->id, $posts))
        ->orderBy('entry_id')->pluck('path')->all());
    $probe = TransactionProbe::start();

    $preview = collect(app(ReflowSitePermalinks::class)->handle(1, dryRun: true, size: 1));

    expect($preview->pluck('status', 'title')->all())->toBe(['mot' => 'moved', 'hai' => 'failed', 'ba' => 'moved'])
        ->and($preview->firstWhere('title', 'hai')['reason'] ?? null)->toBe(ReflowSitePermalinks::ERROR)
        ->and($routes())->toBe(['/cat-a/mot', '/cat-b/hai', '/cat-c/ba'])
        ->and($probe->savepoints)->toBe(0);

    $begun = $probe->transactions;
    $summary = collect(app(ReflowSitePermalinks::class)->handle(1, dryRun: false, size: 1));

    expect($summary->pluck('status', 'title')->all())->toBe(['mot' => 'moved', 'hai' => 'failed', 'ba' => 'moved'])
        ->and($routes())->toBe(['/mot', '/cat-b/hai', '/ba'])
        ->and(app(Tenancy::class)->enter(1, fn (): array => Redirect::query()->orderBy('from_path')->pluck('to_path', 'from_path')->all()))
        ->toBe(['/cat-a/mot' => '/mot', '/cat-c/ba' => '/ba'])
        // the presets, then one chunk of one item at a time (the one that failed twice: rolled back, then skipped)
        ->and($probe->transactions - $begun)->toBeGreaterThanOrEqual(6)
        ->and($probe->savepoints)->toBe(0)->and($probe->subtransactions)->toBe(0);
});

it('refuses to run inside a transaction, where its chunks would be savepoints of one', function (): void {
    expect(fn () => app(Tenancy::class)->enter(1, fn (): array => app(ReflowSitePermalinks::class)->handle(1)))
        ->toThrow(LogicException::class);
});
