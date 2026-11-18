<?php

use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Support\EntryViewCounter;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Shared\Contracts\EdgeCache;
use App\Modules\Storefront\EdgeCache\CloudflareEdgeCache;
use App\Modules\Storefront\EdgeCache\EdgeCachePurges;
use App\Modules\Storefront\EdgeCache\EdgePurgeRefused;
use App\Modules\Storefront\EdgeCache\NullEdgeCache;
use App\Modules\Storefront\EdgeCache\PurgeSiteEdgeCache;
use App\Modules\Storefront\Http\Middleware\EdgeCacheHeaders;
use App\Modules\Tenancy\Context\SiteDirectory;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Theming\StorefrontSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;

/*
 * Public pages leave the origin with no session and no cookie, marked for a shared edge; anything
 * personal (a session cookie, a preview, an unknown query key, an error) stays private. A committed
 * change to what a site shows purges that site's pages at the edge, once, after the commit.
 */

beforeEach(function (): void {
    seedTenancy(); // sites 1 (a.test) / 2 (b.test)
    seedHomepage(1, 'Mở đầu Ân Vĩnh');
    seedHomepage(2, 'Mở đầu Bình Định');
    Cache::flush();
    // midday on the sites' clock (GMT+7): the site's midnight is twelve hours away
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'Asia/Ho_Chi_Minh'));
});

const EDGE_PUBLIC = 'max-age=0, public, s-maxage=300, stale-while-revalidate=60';

it('lets the edge keep a page for a visitor with no session: no cookie, a public s-maxage', function (string $path): void {
    $response = $this->get('http://a.test'.$path)->assertOk();

    expect($response->headers->get('Cache-Control'))->toBe(EDGE_PUBLIC)
        ->and($response->headers->getCookies())->toBe([])
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();
})->with(['/', '/robots.txt', '/sitemap.xml']);

it('keeps the public header on a 304 and on HEAD, still with no cookie', function (): void {
    $etag = $this->get('http://a.test/')->assertOk()->headers->get('ETag');

    $notModified = $this->get('http://a.test/', ['If-None-Match' => $etag])->assertStatus(304);
    $head = $this->call('HEAD', 'http://a.test/')->assertOk();

    expect($notModified->headers->get('Cache-Control'))->toBe(EDGE_PUBLIC)
        ->and($notModified->headers->getCookies())->toBe([])
        ->and($head->headers->get('Cache-Control'))->toBe(EDGE_PUBLIC)
        ->and($head->headers->getCookies())->toBe([]);
});

it('answers private, no-store to a visitor who brings the session cookie (a signed-in admin, a sent form)', function (): void {
    $response = $this->withCookie((string) config('session.cookie'), 'visitor')->get('http://a.test/')->assertOk();

    expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and(collect($response->headers->getCookies())->map->getName()->all())->toContain((string) config('session.cookie'));
});

it('keeps a page private when its query string has a key outside the allow-list', function (string $query, bool $shared): void {
    $cache = (string) $this->get('http://a.test/'.$query)->assertOk()->headers->get('Cache-Control');

    expect($cache)->toBe($shared ? EDGE_PUBLIC : 'no-store, private');
})->with([
    'tracking key' => ['?utm_source=zalo', true],
    'unknown key' => ['?giá=rẻ', false],
    'cache buster' => ['?v=1', false],
]);

it('lets a route name its own edge rules, and shares nothing under a name with no rules', function (): void {
    config(['storefront.edge_cache.probe' => ['s_maxage' => 7, 'stale_while_revalidate' => 3, 'query_allow' => ['q']]]);
    Route::get('/_edge-probe', fn () => response('x'))->middleware(EdgeCacheHeaders::class.':probe');
    Route::get('/_edge-unknown', fn () => response('x'))->middleware(EdgeCacheHeaders::class.':nothing');

    expect($this->get('/_edge-probe?q=a')->headers->get('Cache-Control'))->toBe('max-age=0, public, s-maxage=7, stale-while-revalidate=3')
        // the pages' own keys are not this route's
        ->and($this->get('/_edge-probe?page=2')->headers->get('Cache-Control'))->toContain('no-store')
        ->and($this->get('/_edge-unknown')->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('answers private, no-store with no cookie on a page that does not exist', function (): void {
    $response = $this->get('http://a.test/khong-co-trang-nay')->assertNotFound();

    expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($response->headers->getCookies())->toBe([]);
});

it('sends every visitor the same bytes, and each site only its own', function (): void {
    $first = $this->get('http://a.test/')->assertOk();
    $second = $this->withHeaders(['User-Agent' => 'Khách thứ nhì', 'Accept-Language' => 'en'])->get('http://a.test/')->assertOk();
    $other = (string) $this->get('http://b.test/')->assertOk()->getContent();

    expect($second->getContent())->toBe($first->getContent())
        ->and($second->headers->get('ETag'))->toBe($first->headers->get('ETag'))
        ->and($first->getContent())->toContain('Mở đầu Ân Vĩnh')->not->toContain('Mở đầu Bình Định')
        ->and($other)->toContain('Mở đầu Bình Định')->not->toContain('Mở đầu Ân Vĩnh');
});

it('never lets a page without a session see an error bag shared by an earlier request', function (): void {
    View::share('errors', (new ViewErrorBag)->put('default', new MessageBag(['name' => 'Lỗi của người khác ở trang trước'])));

    $this->get('http://a.test/')->assertOk();

    expect(View::shared('errors'))->toBeInstanceOf(ViewErrorBag::class)
        ->and(View::shared('errors')->any())->toBeFalse();
});

it('allows the Cloudflare speed beacon in the storefront CSP, and only there', function (): void {
    $csp = (string) $this->get('http://a.test/')->headers->get('Content-Security-Policy');

    expect($csp)->toMatch('~script-src [^;]*https://static\\.cloudflareinsights\\.com~')
        ->toContain("connect-src 'self' https://cloudflareinsights.com");
    $this->get('http://a.test/admin/login')->assertHeaderMissing('Content-Security-Policy');
});

it('purges the site once its write commits, never before, for each kind of change', function (Closure $write): void {
    $edge = edgeCacheRecorder();

    app(Tenancy::class)->enter(1, function () use ($write, $edge): void {
        $write();
        expect($edge->calls)->toBe([]); // still inside the transaction
    });

    expect($edge->calls)->toBe([['hosts', ['a.test']]]);
})->with([
    'a page' => [fn () => EntryTranslation::query()->firstOrFail()->update(['title' => 'Mở đầu mới của Ân Vĩnh'])],
    'a product' => [fn () => Product::query()->create(['status' => 'published', 'published_at' => now()])],
    'a menu' => [function (): void {
        $menu = Menu::query()->create(['name' => 'Trình đơn chính', 'handle' => 'header']);
        DB::table('menus')->where('id', $menu->id)->update(['name' => 'Trình đơn đầu']); // a query-builder write
    }],
    'the floating contact' => [fn () => app(SettingsRepository::class)->put('storefront', 'floating_contact', ['enabled' => true])],
    'the appearance' => [fn () => app(SettingsRepository::class)->put('theme', 'config', ['accent' => '#0a7d3b'])],
    'an address' => [fn () => SiteRoute::query()->where('path', '/')->update(['path' => '/trang-chu'])],
]);

it('purges once for many writes in one transaction, and again for the next one', function (): void {
    $edge = edgeCacheRecorder();

    app(Tenancy::class)->enter(1, function (): void {
        $menu = Menu::query()->create(['name' => 'Trình đơn chân', 'handle' => 'footer']);
        DB::table('menus')->where('id', $menu->id)->update(['name' => 'Trình đơn cuối']);
        Product::query()->create(['status' => 'draft']);
    });
    app(Tenancy::class)->enter(2, fn () => Product::query()->create(['status' => 'draft']));

    expect($edge->calls)->toBe([['hosts', ['a.test']], ['hosts', ['b.test']]]);
});

it('purges nothing for a rolled-back write, and still purges a write committed after it', function (): void {
    $edge = edgeCacheRecorder();

    app(Tenancy::class)->enter(1, function (): void {
        try {
            DB::transaction(function (): void {
                Product::query()->create(['status' => 'draft']);
                throw new RuntimeException('Hủy giữa chừng');
            });
        } catch (RuntimeException) {
        }
    });
    expect($edge->calls)->toBe([]);

    app(Tenancy::class)->enter(1, function (): void {
        try {
            DB::transaction(function (): void {
                Product::query()->create(['status' => 'draft']);
                throw new RuntimeException('Hủy lần nữa');
            });
        } catch (RuntimeException) {
        }
        Product::query()->create(['status' => 'draft']);
    });
    expect($edge->calls)->toBe([['hosts', ['a.test']]]);
});

it('purges nothing for a page view, a lead from a visitor or a write outside any site', function (): void {
    config(['services.turnstile.secret_key' => '']);
    Notification::fake();
    app(Tenancy::class)->enter(1, fn () => Form::query()->create([
        'key' => 'contact', 'name' => ['vi' => 'Liên hệ'], 'notify_emails' => ['sales@anvinh.test'], 'status' => 'active',
    ]));
    $edge = edgeCacheRecorder();

    $this->get('http://a.test/')->assertOk();
    $this->post('http://a.test/forms/contact', ['name' => 'Khách Ân', 'email' => 'an@example.test', 'message' => 'Cần báo giá đồ gá'])->assertRedirect();
    DB::connection('pgsql_admin')->table('site_locales')->where('site_id', 1)->update(['updated_at' => now()]);

    expect($edge->calls)->toBe([])
        ->and(app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->count()))->toBe(1);
});

it('falls back to the home pages by URL when the edge refuses a host purge', function (): void {
    $edge = edgeCacheRecorder(hostsAccepted: false);

    app(Tenancy::class)->enter(1, fn () => Product::query()->create(['status' => 'draft']));

    expect($edge->calls)->toBe([['hosts', ['a.test']], ['urls', ['https://a.test/']]]);
});

it('purges nothing until both the zone and the purge token are configured', function (?string $zone, ?string $token, string $driver): void {
    config(['services.cloudflare.zone_id' => $zone, 'services.cloudflare.purge_token' => $token]);
    app()->forgetInstance(EdgeCache::class);

    expect(app(EdgeCache::class))->toBeInstanceOf($driver);
})->with([
    'none' => [null, null, NullEdgeCache::class],
    'zone only' => ['zone-ân', null, NullEdgeCache::class],
    'token only' => [null, 'token-ân', NullEdgeCache::class],
    'both' => ['zone-ân', 'token-ân', CloudflareEdgeCache::class],
]);

it('asks Cloudflare to purge by host, 100 names a call, and reports a refusal', function (): void {
    Http::fake([
        'api.cloudflare.com/client/v4/zones/zone-1/purge_cache' => Http::sequence()
            ->push(['success' => true])
            ->push(['success' => true])
            ->push(['success' => false, 'errors' => [['code' => 1134, 'message' => 'rate limited']]], 429),
    ]);
    $edge = new CloudflareEdgeCache('zone-1', 'secret-purge-token');
    $hosts = array_map(static fn (int $n): string => "site-{$n}.platform.test", range(1, 101));

    expect($edge->purgeHosts($hosts))->toBeTrue()
        ->and($edge->purgeUrls(['https://a.test/']))->toBeFalse();

    Http::assertSentCount(3);
    Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer secret-purge-token')
        && $request->method() === 'POST' && count($request['hosts'] ?? []) === 100);
    Http::assertSent(fn (HttpRequest $request): bool => ($request['hosts'] ?? null) === ['site-101.platform.test']);
    Http::assertSent(fn (HttpRequest $request): bool => ($request['files'] ?? null) === ['https://a.test/']);
});

it('keeps a page private when a proxy header names another host, port or path prefix', function (array $server, bool $shared): void {
    $response = $this->call('GET', 'http://a.test/', [], [], [], ['REMOTE_ADDR' => '172.16.0.5'] + $server)->assertOk();

    expect($response->headers->get('Cache-Control'))->toBe($shared ? EDGE_PUBLIC : 'no-store, private');
})->with([
    'the same host, as Caddy sends it' => [['HTTP_X_FORWARDED_HOST' => 'a.test'], true],
    'another site' => [['HTTP_X_FORWARDED_HOST' => 'b.test'], false],
    'another port' => [['HTTP_X_FORWARDED_PORT' => '8443'], false],
    'the default port' => [['HTTP_X_FORWARDED_PORT' => '80'], true],
    'a path prefix' => [['HTTP_X_FORWARDED_PREFIX' => '/lua-dao'], false],
]);

it('never adds Livewire assets to a public page after the worker drew an admin screen', function (): void {
    SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = true; // left over from an earlier request

    $html = (string) $this->get('http://a.test/')->assertOk()->getContent();

    expect($html)->not->toContain('livewire')->not->toContain('wire:loading');
});

it('purges nothing when the view tally is written, and once for a real edit after it', function (): void {
    $edge = edgeCacheRecorder();
    $entryId = app(Tenancy::class)->enter(1, fn (): int => Entry::query()->firstOrFail()->id);
    app(EntryViewCounter::class)->drain(1);
    app(EntryViewCounter::class)->record(1, $entryId);
    app(EntryViewCounter::class)->record(1, $entryId);

    $this->artisan('entries:flush-views')->assertSuccessful();

    expect($edge->calls)->toBe([])
        ->and(app(Tenancy::class)->enter(1, fn (): int => Entry::query()->findOrFail($entryId)->view_count))->toBe(2);

    app(Tenancy::class)->enter(1, fn () => EntryTranslation::query()->firstOrFail()->update(['title' => 'Mở đầu sửa lại của Ân Vĩnh']));

    expect($edge->calls)->toBe([['hosts', ['a.test']]]);
});

it('sees a write behind a WITH clause, and only a write to a public table', function (string $sql, bool $writes): void {
    expect(EdgeCachePurges::writesPublicTable($sql))->toBe($writes);
})->with([
    'the category tree rewrite' => ["WITH RECURSIVE tree (id, path) AS (SELECT id FROM taxonomies) CYCLE id SET looped USING trail\nUPDATE taxonomies SET path = tree.path FROM tree", true],
    'a write inside the CTE' => ['with moved as (delete from menu_items where id = ? returning id) select count(*) from moved', true],
    'a read behind WITH' => ['with t as (select id from taxonomies) select * from t', false],
    'a write to another table' => ['with t as (select 1) update users set name = ?', false],
    'leading space' => ['  update "menus" set "name" = ?', true],
    'a new upload' => ['insert into "media" ("site_id") values (?)', false],
]);

it('purges the site after the category tree is rebuilt', function (): void {
    $edge = edgeCacheRecorder();

    app(Tenancy::class)->enter(1, fn (): int => app(TaxonomyPaths::class)->rebuild());

    expect($edge->calls)->toBe([['hosts', ['a.test']]]);
});

it('purges only the hosts inside the platform zone, never a customer domain', function (): void {
    $edge = edgeCacheRecorder();
    DB::connection('pgsql_admin')->table('domains')->insert([
        ['site_id' => 1, 'hostname' => 'congty-an.vn', 'kind' => 'custom', 'is_primary' => false, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]);
    config(['tenancy.base_domains' => ['test']]);

    expect(PurgeSiteEdgeCache::inZone(['a.test', 'congty-an.vn', 'www.a.test', 'test', 'nottest']))->toBe(['a.test', 'www.a.test', 'test']);

    app(Tenancy::class)->enter(1, fn () => Product::query()->create(['status' => 'draft']));
    expect($edge->calls)->toBe([['hosts', ['a.test']]]);

    config(['tenancy.base_domains' => ['baoson.net']]);
    $edge->calls = [];
    app(Tenancy::class)->enter(1, fn () => Product::query()->create(['status' => 'draft']));
    expect($edge->calls)->toBe([]); // a site with no host in the zone sends nothing
});

it('keeps a lead whose Turnstile token fails out of the inbox, with no notice sent', function (): void {
    config(['services.turnstile.secret_key' => 'turnstile-secret']);
    Http::fake(['*siteverify*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);
    Notification::fake();
    app(Tenancy::class)->enter(1, fn () => Form::query()->create([
        'key' => 'contact', 'name' => ['vi' => 'Liên hệ'], 'notify_emails' => ['sales@anvinh.test'], 'status' => 'active',
    ]));

    $this->post('http://a.test/forms/contact', [
        'name' => 'Khách giả mạo', 'email' => 'bot@example.test', 'message' => 'Gửi thẳng không kiểm',
        'cf-turnstile-response' => 'token-sai',
    ])->assertRedirect();

    $lead = app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->sole());
    expect($lead->status)->toBe('spam')->and($lead->is_spam)->toBeTrue();
    Notification::assertNothingSent();
});

it('keeps a page of a host inside the zone a day, cut at the site\'s midnight, never under a minute', function (string $at, int $seconds): void {
    config(['tenancy.base_domains' => ['test']]);
    $this->travelTo(CarbonImmutable::parse($at, 'Asia/Ho_Chi_Minh'));

    expect($this->get('http://a.test/')->assertOk()->headers->get('Cache-Control'))
        ->toBe("max-age=0, public, s-maxage={$seconds}, stale-while-revalidate=60");
})->with([
    'midday: twelve hours to midnight' => ['2026-11-18 12:00:00', 43200],
    'just after midnight: a whole day, no more' => ['2026-11-18 00:00:00', 86400],
    'thirty seconds before midnight: the one-minute floor' => ['2026-11-18 23:59:30', 60],
]);

it('reads the midnight on the site\'s own clock', function (): void {
    config(['tenancy.base_domains' => ['test']]);
    DB::connection('pgsql_admin')->table('site_settings')->insert([
        'site_id' => 1, 'group_name' => 'general', 'key' => 'timezone', 'value' => json_encode(['tz' => 'Asia/Tokyo']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // 12:00 in Hồ Chí Minh City is 14:00 in Tokyo: ten hours to site A's midnight; B keeps GMT+7
    expect($this->get('http://a.test/')->headers->get('Cache-Control'))->toContain('s-maxage=36000,')
        ->and($this->get('http://b.test/')->headers->get('Cache-Control'))->toContain('s-maxage=43200,');
});

it('reads the zone in the page\'s one settings read, and no other general setting', function (): void {
    DB::connection('pgsql_admin')->table('site_settings')->insert([
        ['site_id' => 1, 'group_name' => 'general', 'key' => 'timezone', 'value' => json_encode(['tz' => 'Asia/Tokyo']), 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 1, 'group_name' => 'general', 'key' => 'brand', 'value' => json_encode(['name' => 'An']), 'created_at' => now(), 'updated_at' => now()],
    ]);

    [$zone, $brand] = app(Tenancy::class)->enter(1, fn (): array => [
        app(StorefrontSettings::class)->get('general', 'timezone'),
        app(StorefrontSettings::class)->get('general', 'brand'),
    ]);

    expect($zone)->toBe(['tz' => 'Asia/Tokyo'])->and($brand)->toBeNull();
});

it('keeps a page of a host outside the zone (a customer\'s own domain) only the unpurged lifetime', function (): void {
    config(['tenancy.base_domains' => ['baoson.net'], 'storefront.edge_cache.unpurged_s_maxage' => 240]);

    expect($this->get('http://a.test/')->assertOk()->headers->get('Cache-Control'))
        ->toBe('max-age=0, public, s-maxage=240, stale-while-revalidate=60');

    $this->travelTo(CarbonImmutable::parse('2026-11-18 23:59:00', 'Asia/Ho_Chi_Minh'));
    expect($this->get('http://a.test/')->headers->get('Cache-Control'))->toContain('s-maxage=60,');
});

it('leaves a route\'s own edge rules uncut by the midnight or the zone', function (): void {
    config(['storefront.edge_cache.probe' => ['s_maxage' => 600, 'stale_while_revalidate' => 3, 'query_allow' => []]]);
    Route::get('/_edge-probe-late', fn () => response('x'))->middleware(EdgeCacheHeaders::class.':probe');
    $this->travelTo(CarbonImmutable::parse('2026-11-18 23:59:30', 'Asia/Ho_Chi_Minh'));

    expect($this->get('/_edge-probe-late')->headers->get('Cache-Control'))->toBe('max-age=0, public, s-maxage=600, stale-while-revalidate=3');
});

it('purges the homes at once when the edge refuses a host purge, then tries the hosts again later, a minute more each time', function (): void {
    $edge = edgeCacheRecorder(hostsAccepted: false);
    Log::spy();

    $first = (new PurgeSiteEdgeCache(1))->withFakeQueueInteractions();
    $first->handle($edge, app(SiteDirectory::class));
    $first->assertReleased();
    expect($first->job->releaseDelay)->toBeGreaterThanOrEqual(60)->toBeLessThanOrEqual(90);

    $third = (new PurgeSiteEdgeCache(1))->withFakeQueueInteractions();
    $third->job->attempts = 3;
    $third->handle($edge, app(SiteDirectory::class));
    $third->assertReleased();
    expect($third->job->releaseDelay)->toBeGreaterThanOrEqual(180)->toBeLessThanOrEqual(210);

    expect($first->tries)->toBe(5)
        ->and($edge->calls)->toBe([['hosts', ['a.test']], ['urls', ['https://a.test/']], ['hosts', ['a.test']], ['urls', ['https://a.test/']]]);
    Log::shouldNotHaveReceived('error');
});

it('logs an error naming the site and its hosts when the last try is still refused', function (): void {
    $edge = edgeCacheRecorder(hostsAccepted: false);
    Exceptions::fake(); // the report would add a log line of its own
    Log::spy();

    $last = (new PurgeSiteEdgeCache(1))->withFakeQueueInteractions();
    $last->job->attempts = 5;
    $last->handle($edge, app(SiteDirectory::class));

    $last->assertNotReleased();
    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context['site_id'] === 1 && $context['hosts'] === ['a.test']);
});

it('reports the refusal to the exception reporting after the last try, and the job still ends', function (): void {
    Exceptions::fake();
    $edge = edgeCacheRecorder(hostsAccepted: false);

    $last = (new PurgeSiteEdgeCache(1))->withFakeQueueInteractions();
    $last->job->attempts = 5;
    $last->handle($edge, app(SiteDirectory::class));

    $last->assertNotReleased();
    Exceptions::assertReported(fn (EdgePurgeRefused $e): bool => $e->hosts === ['a.test'] && str_contains($e->getMessage(), 'a.test'));

    $early = (new PurgeSiteEdgeCache(1))->withFakeQueueInteractions();
    $early->handle($edge, app(SiteDirectory::class));
    Exceptions::assertReportedCount(1);
});

it('settles once the host purge goes through', function (): void {
    $edge = edgeCacheRecorder();

    $job = (new PurgeSiteEdgeCache(1))->withFakeQueueInteractions();
    $job->handle($edge, app(SiteDirectory::class));

    $job->assertNotReleased();
    expect($edge->calls)->toBe([['hosts', ['a.test']]]);
});

it('purges the hosts a site no longer has with its own, and never merges such a purge with a plain one', function (): void {
    $edge = edgeCacheRecorder();

    (new PurgeSiteEdgeCache(1, ['cu.a.test', 'congty-cu.vn']))->handle($edge, app(SiteDirectory::class));

    expect($edge->calls)->toBe([['hosts', ['a.test', 'cu.a.test']]])
        ->and((new PurgeSiteEdgeCache(1))->uniqueId())->toBe('1')
        ->and((new PurgeSiteEdgeCache(1, ['cu.a.test']))->uniqueId())->not->toBe('1')->toStartWith('1:')
        ->and((new PurgeSiteEdgeCache(1, ['x.test', 'CU.a.test']))->uniqueId())->toBe((new PurgeSiteEdgeCache(1, ['cu.a.test', 'x.test']))->uniqueId())
        ->and((new PurgeSiteEdgeCache(1, ['x.test']))->uniqueId())->not->toBe((new PurgeSiteEdgeCache(1, ['y.test']))->uniqueId());
});

it('classifies every tenant table: what a public page reads purges, the rest is named as not shown', function (): void {
    $tenantTables = array_map(fn (object $row): string => $row->relname, DB::select(<<<'SQL'
        select c.relname
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public'
          and c.relkind in ('r', 'p')
          and not c.relispartition
          and c.relrowsecurity
          and exists (
            select 1 from information_schema.columns col
            where col.table_schema = 'public' and col.table_name = c.relname and col.column_name = 'site_id'
          )
        order by c.relname
    SQL));

    expect($tenantTables)->not->toBeEmpty()
        // a new tenant table: add it to EdgeCachePurges::TABLES when a public page reads it, else to NOT_SHOWN
        ->and(array_values(array_diff($tenantTables, EdgeCachePurges::TABLES, EdgeCachePurges::NOT_SHOWN)))->toBe([])
        // a name in neither the schema nor a list (a dropped or renamed table) is stale
        ->and(array_values(array_diff([...EdgeCachePurges::TABLES, ...EdgeCachePurges::NOT_SHOWN], $tenantTables)))->toBe([])
        ->and(array_values(array_intersect(EdgeCachePurges::TABLES, EdgeCachePurges::NOT_SHOWN)))->toBe([]);
});
