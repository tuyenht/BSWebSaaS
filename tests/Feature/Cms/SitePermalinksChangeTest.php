<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\Permalinks\ChangeSitePermalinks;
use App\Modules\Cms\Actions\Permalinks\ReflowSitePermalinks;
use App\Modules\Cms\Exceptions\PermalinkChangeRejectedException;
use App\Modules\Cms\Jobs\ReflowPermalinks;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Tenancy\Models\SiteModule;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\TransactionProbe;

/*
 * A change of address format: ChangeSitePermalinks checks the choice and stages it, the
 * ReflowPermalinks run moves every route of the changed sections in chunks (each in its own tenant
 * transaction, its cursor and heartbeat stored with it), leaves a 301 from every old address, makes
 * the staged format live, then settles: puts right what was saved meanwhile and gives an address
 * still taken the next free «-2» slug. The website takes no other change until settle ends. An
 * address that cannot move is skipped and kept for the screen. Repeating a run changes nothing; a
 * lost run resumes from its cursor; another website is never touched; a website whose plan may not
 * choose keeps what it stored.
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

/** The run's stored state (PermalinkConfig::run). */
function plState(int $site = 1): ?array
{
    return plIn(function (): ?array {
        app(PermalinkConfig::class)->flush();

        return app(PermalinkConfig::class)->run();
    }, $site);
}

/** Why a change was refused, null when it was taken. */
function plRefusal(array $wanted, int $site = 1): ?string
{
    return plIn(function () use ($wanted): ?string {
        try {
            app(ChangeSitePermalinks::class)->handle($wanted);
        } catch (PermalinkChangeRejectedException $e) {
            return $e->reason;
        }

        return null;
    }, $site);
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
        SiteModule::query()->create(['module_key' => 'catalog', 'enabled' => true]);
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
    Queue::assertPushed(ReflowPermalinks::class, fn (ReflowPermalinks $job): bool => $job->siteId === 1);
    // staged, not live: nothing moved yet and the stored format still serves
    expect(plRoutes(1))->toHaveKey('/gioi-thieu')
        ->and(plIn(fn (): string => app(PermalinkConfig::class)->pattern('pages', 'pages')))->toBe('flat');

    expect(plRun(size: 2))->toBeTrue()
        ->and(plState())->toBeNull();

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
    // the cursor is the run's stored place, not the job's: the hand-over carries only the website
    expect(plState())->toMatchArray(['phase' => ReflowPermalinks::MOVE, 'kind' => 0])
        ->and(plState()['after'])->toBeGreaterThan(0)
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

/** Free the site's place in the queue, as it frees itself after uniqueFor (a queued run that was lost). */
function plFreeQueue(int $site = 1): void
{
    (new UniqueLock(app(Cache::class)))->release(new ReflowPermalinks($site));
}

it('stays busy until the settle walk ends and puts right an item saved during the move', function (): void {
    Queue::fake();
    $ids = plIn(fn (): array => array_map(fn (string $slug): int => plEntry('page', $slug)->id, ['gioi-thieu', 'lien-he', 'bang-gia', 'tuyen-dung']));
    plIn(fn () => app(ChangeSitePermalinks::class)->handle(['pages' => ['pattern' => 'base', 'base' => 'trang']]));

    expect(plRun(size: 2, seconds: 0))->toBeFalse();
    expect(plRoutes(1))->toHaveKey('/trang/gioi-thieu');
    // a member renames a page the move has already passed: the save composes from the live, still flat format
    plIn(function () use ($ids): void {
        $page = Entry::query()->findOrFail($ids[0]);
        $page->translations()->where('locale', 'vi')->update(['slug' => 've-chung-toi']);
        app(RouteSync::class)->sync($page->fresh() ?? $page, 'vi', 've-chung-toi');
    });
    expect(plRoutes(1))->toHaveKey('/ve-chung-toi');

    // the rest of the move, one step per run, until the new format is live and settle begins
    $guard = 0;
    while ((plState()['phase'] ?? null) !== ReflowPermalinks::SETTLE && $guard++ < 10) {
        plRun(size: 2, seconds: 0);
    }
    expect(plState()['phase'] ?? null)->toBe(ReflowPermalinks::SETTLE)
        ->and(plIn(fn (): array => app(PermalinkConfig::class)->pending()))->toBe([])
        ->and(plIn(fn (): string => app(PermalinkConfig::class)->pattern('pages', 'pages')))->toBe('base');

    // a new change while settle walks is refused in plain words, and the settle run is not dropped
    expect(plRefusal(['pages' => ['pattern' => 'flat', 'base' => null]]))->toBe('pending')
        ->and(plIn(fn (): bool => app(PermalinkConfig::class)->busy()))->toBeTrue();

    // whichever run comes next carries settle on from the stored cursor
    expect(plRun())->toBeTrue()
        ->and(plState())->toBeNull()
        ->and(plRoutes(1))->toHaveKey('/trang/ve-chung-toi')
        ->and(plRoutes(1))->not->toHaveKey('/ve-chung-toi')
        ->and(plRedirects(1))->toContain('/ve-chung-toi → /trang/ve-chung-toi (301)', '/gioi-thieu → /trang/ve-chung-toi (301)');

    // after settle the website takes the next change
    expect(plRefusal(['pages' => ['pattern' => 'flat', 'base' => null]]))->toBeNull();
});

it('moves an item into an address that another item of the same run leaves, without «-2»', function (): void {
    Queue::fake();
    [$page, $post] = plIn(function (): array {
        $news = plSection('news', 'tin-tuc');
        $page = plEntry('page', 'gioi-thieu');                   // moves first: /trang/gioi-thieu is still the post's
        SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'news', 'value' => ['pattern' => 'base', 'base' => 'trang']]);

        return [$page, plEntry('post', 'gioi-thieu', $news)];
    });
    expect(plRoutes(1))->toBe(['/gioi-thieu' => 'e'.$page->id, '/trang/gioi-thieu' => 'e'.$post->id]);

    plIn(fn () => app(ChangeSitePermalinks::class)->handle([
        'pages' => ['pattern' => 'base', 'base' => 'trang'],
        'news' => ['pattern' => 'base', 'base' => 'tin-tuc'],
    ]));
    expect(plRun())->toBeTrue();

    expect(plRoutes(1))->toBe(['/tin-tuc/gioi-thieu' => 'e'.$post->id, '/trang/gioi-thieu' => 'e'.$page->id])
        ->and(plIn(fn () => $page->translations()->where('locale', 'vi')->value('slug')))->toBe('gioi-thieu')
        ->and(plRedirects(1))->toBe(['/gioi-thieu → /trang/gioi-thieu (301)'])
        ->and(plState())->toBeNull();
});

it('skips an address that cannot move, finishes the run, and keeps it for the screen', function (): void {
    Queue::fake();
    $long = str_repeat('a', 250);
    [$short, $tooLong] = plIn(fn (): array => [plEntry('page', 'gioi-thieu'), plEntry('page', $long)]);
    plIn(fn () => app(ChangeSitePermalinks::class)->handle(['pages' => ['pattern' => 'base', 'base' => 'trang']]));

    // '/trang/' + 250 letters is past what a route stores: that one page stays, the run goes on and ends
    expect(plRun())->toBeTrue();

    expect(plRoutes(1))->toBe(['/'.$long => 'e'.$tooLong->id, '/trang/gioi-thieu' => 'e'.$short->id])
        ->and(plIn(fn (): string => app(PermalinkConfig::class)->pattern('pages', 'pages')))->toBe('base')
        ->and(plIn(fn (): bool => app(PermalinkConfig::class)->busy()))->toBeFalse();
    $state = plState();
    expect($state['phase'])->toBe('done')->and($state['failed'])->toBe(1)
        ->and($state['failures'][0])->toMatchArray(['section' => 'pages', 'locale' => 'vi', 'old' => '/'.$long, 'reason' => ReflowSitePermalinks::TOO_LONG]);

    // shortened by the member, «Thử lại» walks the section again and the page follows the format
    plIn(function () use ($tooLong): void {
        $tooLong->translations()->where('locale', 'vi')->update(['slug' => 'trang-dai']);
        app(RouteSync::class)->sync($tooLong->fresh() ?? $tooLong, 'vi', 'trang-dai');
    });
    plFreeQueue();
    expect(plIn(fn (): bool => app(ChangeSitePermalinks::class)->retryFailed()))->toBeTrue()
        ->and(plState()['phase'])->toBe(ReflowPermalinks::SETTLE);
    Queue::assertPushed(ReflowPermalinks::class, 2);
    expect(plRun())->toBeTrue()
        ->and(plState())->toBeNull()
        ->and(plRoutes(1))->toHaveKey('/trang/trang-dai');
});

it('lets a sub-page follow a parent page that takes «-2» in settle, in the same chunk and outside it', function (): void {
    Queue::fake();
    $ids = plIn(function (): array {
        SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'pages', 'value' => ['pattern' => 'base', 'base' => 'trang']]);
        $history = plEntry('page', 'lich-su');                            // a sub-page older than its parent
        $about = plEntry('page', 'gioi-thieu');
        $team = plEntry('page', 'doi-ngu', parent: $about);
        Entry::query()->whereKey($history->id)->update(['parent_id' => $about->id]);
        plEntry('post', 'gioi-thieu', plSection('news', 'tin-tuc'));      // holds /gioi-thieu once pages nest
        SiteSetting::query()->where('group_name', 'permalinks')->where('key', 'pages')->update(['value' => ['pattern' => 'nested']]);
        app(PermalinkConfig::class)->flush();

        return ['history' => $history->id, 'about' => $about->id, 'team' => $team->id];
    });

    // one settle chunk over the pages on the live (nested) format
    plIn(fn (): array => app(ReflowSitePermalinks::class)->chunk(ReflowSitePermalinks::ENTRIES, ['pages'], [], 0, 500, suffix: true));

    expect(plRoutes(1))->toMatchArray([
        '/gioi-thieu-2' => 'e'.$ids['about'],
        '/gioi-thieu-2/doi-ngu' => 'e'.$ids['team'],
        '/gioi-thieu-2/lich-su' => 'e'.$ids['history'],
    ])->and(plRoutes(1))->not->toHaveKeys(['/gioi-thieu/doi-ngu', '/gioi-thieu/lich-su']);
});

it('resumes a lost run from its cursor, judged by its last progress, never from the start', function (): void {
    Queue::fake();
    plIn(function (): void {
        foreach (['mot', 'hai', 'ba', 'bon', 'nam'] as $slug) {
            plEntry('page', $slug);
        }
    });
    plIn(fn () => app(ChangeSitePermalinks::class)->handle(['pages' => ['pattern' => 'base', 'base' => 'trang']]));
    $resume = fn (): bool => plIn(fn (): bool => app(ChangeSitePermalinks::class)->resumeIfStale());

    // a long run: the change was staged 70 minutes ago, but its last chunk ran just now
    $this->travel(70)->minutes();
    plFreeQueue();
    expect(plRun(size: 2, seconds: 0))->toBeFalse();
    $cursor = plState()['after'];
    $this->travel(5)->minutes();
    expect($resume())->toBeFalse();
    Queue::assertPushed(ReflowPermalinks::class, 2); // the staging's and the hand-over's

    // a chunk replayed after a crash (its cursor never stored) moves nothing twice; its hand-over is
    // dropped beside the one still queued, which would carry on from the same stored cursor
    $redirectIds = DB::connection('pgsql_admin')->table('redirects')->where('site_id', 1)->orderBy('id')->pluck('id')->all();
    $state = plState();
    plIn(fn () => app(PermalinkConfig::class)->saveRun([...$state, 'after' => 0]));
    plRun(size: 2, seconds: 0);
    expect(DB::connection('pgsql_admin')->table('redirects')->where('site_id', 1)->orderBy('id')->pluck('id')->all())->toBe($redirectIds)
        ->and(plState()['after'])->toBe($cursor);

    // its hand-over lost, no progress for 20 minutes: a page open starts it again, from the stored cursor
    $this->travel(20)->minutes();
    plFreeQueue();
    expect($resume())->toBeTrue();
    Queue::assertPushed(ReflowPermalinks::class, 3);
    $resumed = Queue::pushed(ReflowPermalinks::class)->last();
    expect($resumed->siteId)->toBe(1);
    $guard = 0;
    while (! plStart($resumed) && $guard++ < 10) {
        $resumed = Queue::pushed(ReflowPermalinks::class)->last();
    }

    expect(array_keys(plRoutes(1)))->toBe(['/trang/ba', '/trang/bon', '/trang/hai', '/trang/mot', '/trang/nam'])
        ->and(plRedirects(1))->toHaveCount(5)
        ->and(array_slice(DB::connection('pgsql_admin')->table('redirects')->where('site_id', 1)->orderBy('id')->pluck('id')->all(), 0, count($redirectIds)))->toBe($redirectIds)
        ->and(plState())->toBeNull();
});

it('walks only the kinds the changed sections live in', function (): void {
    Queue::fake();
    plIn(function (): void {
        SiteModule::query()->create(['module_key' => 'catalog', 'enabled' => true]);
        $machines = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 0]);
        $machines->translations()->create(['locale' => 'vi', 'name' => 'May', 'slug' => 'may']);
        plEntry('page', 'gioi-thieu');
        plProduct('may-cnc', $machines);
    });
    plIn(fn () => app(ChangeSitePermalinks::class)->handle(['products' => ['pattern' => 'base', 'base' => 'san-pham']]));

    $entryReads = 0;
    DB::listen(function ($query) use (&$entryReads): void {
        if (preg_match('/from "entries"|join "entries"|"entries"\."/', $query->sql) === 1) {
            $entryReads++;
        }
    });
    expect(plRun())->toBeTrue();

    expect($entryReads)->toBe(0)
        ->and(plRoutes(1))->toHaveKeys(['/gioi-thieu', '/san-pham/may-cnc']);
});

it('offers the products format only to a website that has the catalog', function (): void {
    Queue::fake();
    $products = ['products' => ['pattern' => 'base', 'base' => 'san-pham']];

    expect(plIn(fn (): array => app(ChangeSitePermalinks::class)->problems($products)))->toBe(['products' => ['code' => 'pattern', 'other' => null]])
        ->and(plRefusal($products))->toBe('invalid');

    plIn(fn () => SiteModule::query()->create(['module_key' => 'catalog', 'enabled' => true]));
    app()->forgetScopedInstances(); // the website's modules are read once per request
    expect(plIn(fn (): array => app(ChangeSitePermalinks::class)->problems($products)))->toBe([]);
});

it('moves the rest of a chunk past an address that fails, keeps that one for the screen, and opens no savepoint', function (): void {
    Queue::fake();
    $ids = plIn(fn (): array => array_map(fn (string $slug): int => plEntry('page', $slug)->id, ['gioi-thieu', 'lien-he', 'bang-gia', 'tuyen-dung']));
    plIn(fn () => app(ChangeSitePermalinks::class)->handle(['pages' => ['pattern' => 'base', 'base' => 'trang']]));
    // one address breaks as its route moves, after its 301 is written: a real database error, which
    // aborts the whole transaction
    SiteRoute::updating(function (SiteRoute $route): void {
        if ($route->path === '/trang/bang-gia') {
            DB::select('select 1/0');
        }
    });
    $probe = TransactionProbe::start();

    expect(plRun())->toBeTrue();

    expect(plRoutes(1))->toBe(['/bang-gia' => 'e'.$ids[2], '/trang/gioi-thieu' => 'e'.$ids[0], '/trang/lien-he' => 'e'.$ids[1], '/trang/tuyen-dung' => 'e'.$ids[3]])
        ->and(plRedirects(1))->toBe(['/gioi-thieu → /trang/gioi-thieu (301)', '/lien-he → /trang/lien-he (301)', '/tuyen-dung → /trang/tuyen-dung (301)']);
    $state = plState();
    expect($state['phase'])->toBe('done')->and($state['failed'])->toBe(1)
        ->and($state['failures'][0])->toMatchArray(['section' => 'pages', 'locale' => 'vi', 'old' => '/bang-gia', 'reason' => ReflowSitePermalinks::ERROR]);
    // the whole run, move and settle, with no savepoint and no subtransaction in any transaction
    expect($probe->savepoints)->toBe(0)->and($probe->subtransactions)->toBe(0)->and($probe->transactions)->toBeGreaterThan(2);

    // and the probe does see one: a savepoint that writes
    plIn(fn () => DB::transaction(fn () => Entry::query()->whereKey($ids[0])->update(['updated_at' => now()])));
    expect($probe->savepoints)->toBe(1)->and($probe->subtransactions)->toBe(1);
});

it('keeps an address that failed skipped across hand-overs, so a run that hands over after every step still ends', function (): void {
    Queue::fake();
    $ids = plIn(fn (): array => array_map(fn (string $slug): int => plEntry('page', $slug)->id, ['gioi-thieu', 'lien-he', 'bang-gia', 'tuyen-dung']));
    plIn(fn () => app(ChangeSitePermalinks::class)->handle(['pages' => ['pattern' => 'base', 'base' => 'trang']]));
    SiteRoute::updating(function (SiteRoute $route): void {
        if ($route->path === '/trang/bang-gia') {
            DB::select('select 1/0');
        }
    });
    $probe = TransactionProbe::start();

    // each run does one step and hands over (seconds 0); the chunk holding the bad address fails once
    // in move and once in settle, and its next attempt, a new run, skips it as stored
    $runs = 0;
    $skipped = [];
    do {
        $done = plRun(size: 2, seconds: 0);
        $routes = plState()['skip']['routes'] ?? [];
        if ($routes !== []) {
            $skipped = $routes;
        }
    } while (! $done && ++$runs < 20);

    expect($done)->toBeTrue()
        ->and($skipped)->toBe([ReflowSitePermalinks::route('entry_id', $ids[2], 'vi') => ReflowSitePermalinks::ERROR])
        ->and(plRoutes(1))->toBe(['/bang-gia' => 'e'.$ids[2], '/trang/gioi-thieu' => 'e'.$ids[0], '/trang/lien-he' => 'e'.$ids[1], '/trang/tuyen-dung' => 'e'.$ids[3]]);
    $state = plState();
    expect($state['failed'])->toBe(1)->and($state['failures'][0])->toMatchArray(['old' => '/bang-gia', 'reason' => ReflowSitePermalinks::ERROR])
        ->and($probe->savepoints)->toBe(0);
});

it('leaves a parent page whose «-2» cannot take its sub-pages along, and its sub-pages under its real slug', function (): void {
    Queue::fake();
    $ids = plIn(function (): array {
        SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'pages', 'value' => ['pattern' => 'base', 'base' => 'trang']]);
        $about = plEntry('page', 'gioi-thieu');
        $team = plEntry('page', 'doi-ngu', parent: $about);
        $history = plEntry('page', 'lich-su', parent: $about);
        plEntry('post', 'gioi-thieu', plSection('news', 'tin-tuc'));     // holds /gioi-thieu once pages nest
        $holder = Entry::query()->create(['type' => 'post', 'status' => 'published']);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/gioi-thieu-2/doi-ngu', 'entry_id' => $holder->id]);

        return ['about' => $about->id, 'team' => $team->id, 'history' => $history->id];
    });
    expect(plRoutes(1))->toMatchArray(['/trang/gioi-thieu' => 'e'.$ids['about'], '/trang/doi-ngu' => 'e'.$ids['team'], '/trang/lich-su' => 'e'.$ids['history']]);

    // move: the parent keeps its address (taken), its sub-pages nest under its slug; settle: the
    // parent's «-2» would put its first sub-page on an address another item holds, so it stays
    plIn(fn () => app(ChangeSitePermalinks::class)->handle(['pages' => ['pattern' => 'nested', 'base' => null]]));
    expect(plRun())->toBeTrue();

    expect(plRoutes(1))->toMatchArray([
        '/trang/gioi-thieu' => 'e'.$ids['about'],
        '/gioi-thieu/doi-ngu' => 'e'.$ids['team'],
        '/gioi-thieu/lich-su' => 'e'.$ids['history'],
    ])->and(plRoutes(1))->not->toHaveKeys(['/gioi-thieu-2', '/gioi-thieu-2/lich-su', '/gioi-thieu-2/doi-ngu-2'])
        ->and(plIn(fn (): array => DB::table('entry_translations')->whereIn('entry_id', array_values($ids))->orderBy('entry_id')->pluck('slug')->all()))
        ->toBe(['gioi-thieu', 'doi-ngu', 'lich-su']);
    $state = plState();
    expect($state['failed'])->toBe(1)
        ->and($state['failures'][0])->toMatchArray(['section' => 'pages', 'old' => '/trang/gioi-thieu', 'reason' => ReflowSitePermalinks::TAKEN]);
});
