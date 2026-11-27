<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\Permalinks\ChangeSitePermalinks;
use App\Modules\Cms\Actions\Permalinks\ReflowSitePermalinks;
use App\Modules\Cms\Exceptions\PermalinkChangeRejectedException;
use App\Modules\Cms\Jobs\ReflowPermalinks;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * A change of address format: ChangeSitePermalinks checks the choice and stages it, the
 * ReflowPermalinks run moves every route of the changed sections in chunks (each in its own tenant
 * transaction), leaves a 301 from every old address, gives a clashing item the next free «-2» slug,
 * then makes the staged format live. Repeating a run changes nothing; another website is never
 * touched; a website whose plan may not choose keeps what it stored.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

function plIn(Closure $step, int $site = 1): mixed
{
    return app(Tenancy::class)->enter($site, $step);
}

/** A post section filing under a fresh root category (inside plIn). */
function plSection(string $key, string $catSlug): Taxonomy
{
    $root = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $root->translations()->create(['locale' => 'vi', 'name' => $catSlug, 'slug' => $catSlug]);
    ContentSection::query()->create([
        'key' => $key, 'label' => ucfirst($key), 'entry_type' => 'post',
        'taxonomy_id' => $root->id, 'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);

    return $root;
}

/** A published post or page with its live route (inside plIn). */
function plEntry(string $type, string $slug, ?Taxonomy $category = null, ?Entry $parent = null): Entry
{
    $entry = Entry::query()->create(['type' => $type, 'status' => 'published', 'parent_id' => $parent?->id]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $slug, 'slug' => $slug, 'status' => 'published', 'body' => ['blocks' => []]]);
    if ($category !== null) {
        $entry->assignCategories([$category->id]);
    }
    app(PermalinkConfig::class)->flush();
    app(RouteSync::class)->sync($entry->fresh() ?? $entry, 'vi', $slug);

    return $entry;
}

/** A published product filed under $category with its live route (inside plIn). */
function plProduct(string $slug, Taxonomy $category): Product
{
    $product = Product::query()->create(['status' => 'published', 'sku' => 'SKU-'.$slug, 'price' => 1000000, 'published_at' => now()]);
    $product->translations()->create(['locale' => 'vi', 'name' => $slug, 'slug' => $slug]);
    $product->assignCategories([$category->id]);
    app(PermalinkConfig::class)->flush();
    app(RouteSync::class)->syncProduct($product->id, $category, 'vi', $slug);

    return $product;
}

/** Run the move with $size items per chunk, as the queue would (outside any site context). */
function plRun(int $site = 1, int $size = 500, int $seconds = 30): bool
{
    $job = new ReflowPermalinks($site, size: $size, seconds: $seconds);

    return (bool) app()->call([$job, 'handle']);
}

/** Run a queued hand-over as a worker would: free its unique lock first, then handle it. */
function plStart(ReflowPermalinks $job): bool
{
    (new UniqueLock(app(Cache::class)))->release($job);

    return (bool) app()->call([$job, 'handle']);
}

/** @return array<string, string> the site's live routes path => owner, read past RLS */
function plRoutes(int $site): array
{
    return DB::connection('pgsql_admin')->table('routes')->where('site_id', $site)->orderBy('path')
        ->get(['path', 'entry_id', 'product_id', 'taxonomy_id'])
        ->mapWithKeys(fn (object $r): array => [(string) $r->path => $r->entry_id !== null ? 'e'.$r->entry_id : ($r->product_id !== null ? 'p'.$r->product_id : 't'.$r->taxonomy_id)])
        ->all();
}

/** @return list<string> the site's redirects «from → to (code)», read past RLS */
function plRedirects(int $site): array
{
    return DB::connection('pgsql_admin')->table('redirects')->where('site_id', $site)->whereNull('deleted_at')->orderBy('from_path')
        ->get(['from_path', 'to_path', 'status_code'])
        ->map(fn (object $r): string => $r->from_path.' → '.$r->to_path.' ('.$r->status_code.')')->all();
}

it('moves pages, posts and products to the staged format in chunks, each with a 301, then makes it live', function (): void {
    Queue::fake();
    $ids = plIn(function (): array {
        $news = plSection('news', 'tin-tuc');
        $machines = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 0]);
        $machines->translations()->create(['locale' => 'vi', 'name' => 'May', 'slug' => 'may']);

        return [
            'pages' => [plEntry('page', 'gioi-thieu')->id, plEntry('page', 'lien-he')->id, plEntry('page', 'bang-gia')->id],
            'posts' => [plEntry('post', 'khai-truong', $news)->id, plEntry('post', 'xuong-moi', $news)->id],
            'products' => [plProduct('may-cnc', $machines)->id, plProduct('may-tien', $machines)->id],
        ];
    });

    $staged = plIn(fn (): array => app(ChangeSitePermalinks::class)->handle([
        'pages' => ['pattern' => 'base', 'base' => 'trang'],
        'news' => ['pattern' => 'category', 'base' => null],
        'products' => ['pattern' => 'category', 'base' => null],
    ]));
    expect($staged)->toBe(['pages', 'news', 'products']);
    Queue::assertPushed(ReflowPermalinks::class, fn (ReflowPermalinks $job): bool => $job->siteId === 1 && $job->phase === ReflowPermalinks::MOVE);
    // staged, not live: nothing moved yet and the stored format still serves
    expect(plRoutes(1))->toHaveKey('/gioi-thieu')
        ->and(plIn(fn (): string => app(PermalinkConfig::class)->pattern('pages', 'pages')))->toBe('flat');

    expect(plRun(size: 2))->toBeTrue();

    [$a, $b, $c] = $ids['pages'];
    [$p1, $p2] = $ids['posts'];
    [$m1, $m2] = $ids['products'];
    expect(plRoutes(1))->toMatchArray([
        '/trang/gioi-thieu' => 'e'.$a, '/trang/lien-he' => 'e'.$b, '/trang/bang-gia' => 'e'.$c,
        '/tin-tuc/khai-truong' => 'e'.$p1, '/tin-tuc/xuong-moi' => 'e'.$p2,
        '/may/may-cnc' => 'p'.$m1, '/may/may-tien' => 'p'.$m2,
    ])->and(plRoutes(1))->not->toHaveKeys(['/gioi-thieu', '/khai-truong', '/may-cnc'])
        ->and(plRedirects(1))->toContain(
            '/gioi-thieu → /trang/gioi-thieu (301)', '/lien-he → /trang/lien-he (301)', '/bang-gia → /trang/bang-gia (301)',
            '/khai-truong → /tin-tuc/khai-truong (301)', '/xuong-moi → /tin-tuc/xuong-moi (301)',
            '/may-cnc → /may/may-cnc (301)', '/may-tien → /may/may-tien (301)',
        );

    $stored = plIn(fn (): array => SiteSetting::query()->where('group_name', 'permalinks')->orderBy('key')->pluck('value', 'key')->all());
    expect($stored)->toEqual([ // jsonb keeps no key order
        'news' => ['pattern' => 'category'],
        'pages' => ['pattern' => 'base', 'base' => 'trang'],
        'products' => ['pattern' => 'category'],
    ]);
});

it('hands over to a fresh run between chunks and the next run carries on from its cursor', function (): void {
    plIn(function (): void {
        foreach (['mot', 'hai', 'ba', 'bon', 'nam'] as $slug) {
            plEntry('page', $slug);
        }
        app(PermalinkConfig::class)->stage(['pages' => ['pattern' => 'base', 'base' => 'trang']], now()->utc()->toIso8601String());
    });

    Queue::fake();
    expect(plRun(size: 2, seconds: 0))->toBeFalse();
    $next = null;
    Queue::assertPushed(ReflowPermalinks::class, function (ReflowPermalinks $job) use (&$next): bool {
        $next = $job;

        return true;
    });
    expect($next->after)->toBeGreaterThan(0)->and($next->phase)->toBe(ReflowPermalinks::MOVE)
        ->and(array_keys(array_filter(plRoutes(1), fn (string $owner, string $path): bool => str_starts_with($path, '/trang/'), ARRAY_FILTER_USE_BOTH)))->toHaveCount(2);

    // the rest of the run, one chunk per hand-over, until it reports done
    // (a worker frees the run's unique lock when it starts it; handle() called here does not, so free it as the worker would)
    $guard = 0;
    $job = $next;
    while (! plStart($job) && $guard++ < 20) {
        $pushed = Queue::pushed(ReflowPermalinks::class);
        $job = $pushed->last();
    }
    expect(array_keys(plRoutes(1)))->toBe(['/trang/ba', '/trang/bon', '/trang/hai', '/trang/mot', '/trang/nam'])
        ->and(plIn(fn (): array => app(PermalinkConfig::class)->pending()))->toBe([]);
});

it('keeps every old address one hop from the item and leaves no loop when a format is changed back', function (): void {
    Queue::fake();
    $id = plIn(fn (): int => plEntry('page', 'gioi-thieu')->id);

    plIn(fn () => app(ChangeSitePermalinks::class)->handle(['pages' => ['pattern' => 'base', 'base' => 'trang']]));
    plRun();
    plIn(fn () => app(ChangeSitePermalinks::class)->handle(['pages' => ['pattern' => 'base', 'base' => 've-chung-toi']]));
    plRun();
    expect(plRedirects(1))->toBe([
        '/gioi-thieu → /ve-chung-toi/gioi-thieu (301)',
        '/trang/gioi-thieu → /ve-chung-toi/gioi-thieu (301)',
    ]);

    plIn(fn () => app(ChangeSitePermalinks::class)->handle(['pages' => ['pattern' => 'flat', 'base' => null]]));
    plRun();
    expect(plRoutes(1))->toBe(['/gioi-thieu' => 'e'.$id])
        ->and(plRedirects(1))->toBe([
            '/trang/gioi-thieu → /gioi-thieu (301)',
            '/ve-chung-toi/gioi-thieu → /gioi-thieu (301)',
        ]);

    // a visitor of an old address reaches the item in one hop
    $this->get('http://a.test/trang/gioi-thieu')->assertStatus(301)->assertRedirect('http://a.test/gioi-thieu');
});

it('gives an item whose new address another item holds the next free slug, with its 301', function (): void {
    Queue::fake();
    [$page, $post] = plIn(function (): array {
        $news = plSection('news', 'tin-tuc');
        SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'news', 'value' => ['pattern' => 'category']]);

        return [plEntry('page', 'bao-gia'), plEntry('post', 'bao-gia', $news)];
    });
    expect(plRoutes(1))->toBe(['/bao-gia' => 'e'.$page->id, '/tin-tuc/bao-gia' => 'e'.$post->id]);

    plIn(fn () => app(ChangeSitePermalinks::class)->handle(['news' => ['pattern' => 'flat', 'base' => null]]));
    plRun();

    expect(plRoutes(1))->toBe(['/bao-gia' => 'e'.$page->id, '/bao-gia-2' => 'e'.$post->id])
        ->and(plRedirects(1))->toBe(['/tin-tuc/bao-gia → /bao-gia-2 (301)'])
        ->and(plIn(fn () => $post->translations()->where('locale', 'vi')->value('slug')))->toBe('bao-gia-2')
        ->and(plIn(fn () => $page->translations()->where('locale', 'vi')->value('slug')))->toBe('bao-gia');
});

it('changes nothing when a run is repeated', function (): void {
    Queue::fake();
    plIn(function (): void {
        $news = plSection('news', 'tin-tuc');
        plEntry('page', 'gioi-thieu');
        plEntry('post', 'khai-truong', $news);
    });
    plIn(fn () => app(ChangeSitePermalinks::class)->handle(['pages' => ['pattern' => 'base', 'base' => 'trang'], 'news' => ['pattern' => 'base', 'base' => 'bai-viet']]));
    plRun();
    $routes = plRoutes(1);
    $redirects = plRedirects(1);
    $redirectIds = DB::connection('pgsql_admin')->table('redirects')->where('site_id', 1)->orderBy('id')->pluck('id')->all();

    // a run started again (a retry, a lost run resumed) finds nothing staged and does nothing
    expect(plRun())->toBeTrue();
    // and walking every item once more on the live format moves nothing
    $rows = plIn(fn (): array => app(ReflowSitePermalinks::class)->chunk(ReflowSitePermalinks::ENTRIES, null, [], 0)['rows']);

    expect(array_unique(array_column($rows, 'status')))->toBe(['unchanged'])
        ->and(plRoutes(1))->toBe($routes)
        ->and(plRedirects(1))->toBe($redirects)
        ->and(DB::connection('pgsql_admin')->table('redirects')->where('site_id', 1)->orderBy('id')->pluck('id')->all())->toBe($redirectIds);
});

it('never touches another website', function (): void {
    Queue::fake();
    plIn(fn () => plEntry('page', 'gioi-thieu'));
    plIn(function (): void {
        plEntry('page', 'gioi-thieu');
        plEntry('page', 'lien-he');
    }, 2);
    $before = plRoutes(2);

    plIn(fn () => app(ChangeSitePermalinks::class)->handle(['pages' => ['pattern' => 'base', 'base' => 'trang']]));
    plRun();

    expect(array_keys(plRoutes(1)))->toBe(['/trang/gioi-thieu'])
        ->and(plRoutes(2))->toBe($before)
        ->and(plRedirects(2))->toBe([])
        ->and(DB::connection('pgsql_admin')->table('site_settings')->where('site_id', 2)->where('group_name', 'permalinks')->count())->toBe(0);
});

it('refuses a second change while one is still being applied', function (): void {
    Queue::fake();
    plIn(fn () => app(ChangeSitePermalinks::class)->handle(['pages' => ['pattern' => 'base', 'base' => 'trang']]));

    $reason = plIn(function (): ?string {
        try {
            app(ChangeSitePermalinks::class)->handle(['pages' => ['pattern' => 'nested', 'base' => null]]);
        } catch (PermalinkChangeRejectedException $e) {
            return $e->reason;
        }

        return null;
    });

    expect($reason)->toBe('pending')
        ->and(plIn(fn (): array => app(PermalinkConfig::class)->pending()))->toBe(['pages' => ['pattern' => 'base', 'base' => 'trang']]);
    Queue::assertPushed(ReflowPermalinks::class, 1);
});

it('refuses a change when the plan may not choose, and keeps the stored format serving', function (): void {
    Queue::fake();
    plIn(function (): void {
        SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'pages', 'value' => ['pattern' => 'base', 'base' => 'trang']]);
        SiteFeature::query()->create(['feature_key' => ChangeSitePermalinks::FEATURE, 'value' => false]);
    });

    $reason = plIn(function (): ?string {
        try {
            app(ChangeSitePermalinks::class)->handle(['pages' => ['pattern' => 'flat', 'base' => null]]);
        } catch (PermalinkChangeRejectedException $e) {
            return $e->reason;
        }

        return null;
    });

    expect($reason)->toBe('off')
        ->and(plIn(fn (): string => app(PermalinkConfig::class)->pattern('pages', 'pages')))->toBe('base')
        ->and(plIn(fn (): array => app(PermalinkConfig::class)->pending()))->toBe([]);
    Queue::assertNotPushed(ReflowPermalinks::class);
});

it('checks every prefix: filled, plain letters, not a platform address, not another section’s', function (): void {
    Queue::fake();
    plIn(fn () => plSection('news', 'tin-tuc'));

    $problems = plIn(fn (): array => app(ChangeSitePermalinks::class)->problems([
        'pages' => ['pattern' => 'base', 'base' => ''],
        'news' => ['pattern' => 'base', 'base' => 'Tin Tức'],
        'products' => ['pattern' => 'nested', 'base' => null],
    ]));
    expect($problems)->toBe([
        'pages' => ['code' => 'base_empty', 'other' => null],
        'news' => ['code' => 'base_format', 'other' => null],
        'products' => ['code' => 'pattern', 'other' => null],
    ]);

    foreach (['sys', 'en', 'tim-kiem', 'the', 'admin'] as $reserved) {
        expect(plIn(fn (): array => app(ChangeSitePermalinks::class)->problems(['pages' => ['pattern' => 'base', 'base' => $reserved]])))
            ->toBe(['pages' => ['code' => 'base_reserved', 'other' => null]]);
    }

    expect(plIn(fn (): array => app(ChangeSitePermalinks::class)->problems([
        'pages' => ['pattern' => 'base', 'base' => 'muc'],
        'news' => ['pattern' => 'base', 'base' => 'muc'],
    ])))->toBe([
        'pages' => ['code' => 'base_taken', 'other' => 'news'],
        'news' => ['code' => 'base_taken', 'other' => 'pages'],
    ]);

    $reason = plIn(function (): ?string {
        try {
            app(ChangeSitePermalinks::class)->handle(['pages' => ['pattern' => 'base', 'base' => 'sys']]);
        } catch (PermalinkChangeRejectedException $e) {
            return $e->reason;
        }

        return null;
    });
    expect($reason)->toBe('invalid');
    Queue::assertNotPushed(ReflowPermalinks::class);
});
