<?php

use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Shared\Contracts\EdgeCache;
use App\Modules\Storefront\EdgeCache\CloudflareEdgeCache;
use App\Modules\Storefront\EdgeCache\EdgeCachePurges;
use App\Modules\Storefront\EdgeCache\NullEdgeCache;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/*
 * Public pages leave the origin with no session and no cookie, marked for a shared edge; anything
 * personal (a session cookie, a preview, an unknown query key, an error) stays private. A committed
 * change to what a site shows purges that site's pages at the edge, once, after the commit.
 */

beforeEach(function (): void {
    seedTenancy(); // sites 1 (a.test) / 2 (b.test)
    seedHomepage(1, 'Trang chủ Ân Vĩnh');
    seedHomepage(2, 'Trang chủ Bình Định');
    Cache::flush();
});

/** Records every purge instead of calling an edge. */
function edgeCacheRecorder(bool $hostsAccepted = true): object
{
    $fake = new class($hostsAccepted) implements EdgeCache
    {
        /** @var list<array{0: string, 1: list<string>}> */
        public array $calls = [];

        public function __construct(private bool $hostsAccepted) {}

        public function purgeHosts(array $hosts): bool
        {
            $this->calls[] = ['hosts', $hosts];

            return $this->hostsAccepted;
        }

        public function purgeUrls(array $urls): bool
        {
            $this->calls[] = ['urls', $urls];

            return true;
        }
    };
    app()->instance(EdgeCache::class, $fake);
    app()->forgetInstance(EdgeCachePurges::class); // built with the null driver during the seeding

    return $fake;
}

const EDGE_PUBLIC = 'max-age=0, public, s-maxage=300, stale-while-revalidate=86400';

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

it('answers private, no-store with no cookie on a page that does not exist', function (): void {
    $response = $this->get('http://a.test/khong-co-trang-nay')->assertNotFound();

    expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($response->headers->getCookies())->toBe([]);
});

it('sends every visitor the same bytes, and each site only its own', function (): void {
    $first = $this->get('http://a.test/')->assertOk();
    $second = $this->withHeaders(['User-Agent' => 'Khách thứ hai', 'Accept-Language' => 'en'])->get('http://a.test/')->assertOk();
    $other = (string) $this->get('http://b.test/')->assertOk()->getContent();

    expect($second->getContent())->toBe($first->getContent())
        ->and($second->headers->get('ETag'))->toBe($first->headers->get('ETag'))
        ->and($first->getContent())->toContain('Trang chủ Ân Vĩnh')->not->toContain('Trang chủ Bình Định')
        ->and($other)->toContain('Trang chủ Bình Định')->not->toContain('Trang chủ Ân Vĩnh');
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
    'a page' => [fn () => EntryTranslation::query()->firstOrFail()->update(['title' => 'Trang chủ mới của Ân Vĩnh'])],
    'a product' => [fn () => Product::query()->create(['status' => 'published', 'published_at' => now()])],
    'a menu' => [function (): void {
        $menu = Menu::query()->create(['name' => 'Trình đơn chính', 'handle' => 'header']);
        DB::table('menus')->where('id', $menu->id)->update(['name' => 'Trình đơn đầu trang']); // a query-builder write
    }],
    'the floating contact' => [fn () => app(SettingsRepository::class)->put('storefront', 'floating_contact', ['enabled' => true])],
    'the appearance' => [fn () => app(SettingsRepository::class)->put('theme', 'config', ['accent' => '#0a7d3b'])],
    'an address' => [fn () => SiteRoute::query()->where('path', '/')->update(['path' => '/trang-chu'])],
]);

it('purges once for many writes in one transaction, and again for the next one', function (): void {
    $edge = edgeCacheRecorder();

    app(Tenancy::class)->enter(1, function (): void {
        $menu = Menu::query()->create(['name' => 'Trình đơn chân trang', 'handle' => 'footer']);
        DB::table('menus')->where('id', $menu->id)->update(['name' => 'Trình đơn cuối trang']);
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

it('asks Cloudflare to purge by host, 30 names a call, and reports a refusal', function (): void {
    Http::fake([
        'api.cloudflare.com/client/v4/zones/zone-1/purge_cache' => Http::sequence()
            ->push(['success' => true])
            ->push(['success' => true])
            ->push(['success' => false, 'errors' => [['code' => 1134, 'message' => 'rate limited']]], 429),
    ]);
    $edge = new CloudflareEdgeCache('zone-1', 'secret-purge-token');
    $hosts = array_map(static fn (int $n): string => "site-{$n}.platform.test", range(1, 31));

    expect($edge->purgeHosts($hosts))->toBeTrue()
        ->and($edge->purgeUrls(['https://a.test/']))->toBeFalse();

    Http::assertSentCount(3);
    Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer secret-purge-token')
        && $request->method() === 'POST' && count($request['hosts'] ?? []) === 30);
    Http::assertSent(fn (HttpRequest $request): bool => ($request['hosts'] ?? null) === ['site-31.platform.test']);
    Http::assertSent(fn (HttpRequest $request): bool => ($request['files'] ?? null) === ['https://a.test/']);
});

it('keeps a lead whose Turnstile token fails out of the inbox, with no notice sent', function (): void {
    config(['services.turnstile.secret_key' => 'turnstile-secret']);
    Http::fake(['*siteverify*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);
    Notification::fake();
    app(Tenancy::class)->enter(1, fn () => Form::query()->create([
        'key' => 'contact', 'name' => ['vi' => 'Liên hệ'], 'notify_emails' => ['sales@anvinh.test'], 'status' => 'active',
    ]));

    $this->post('http://a.test/forms/contact', [
        'name' => 'Khách giả mạo', 'email' => 'bot@example.test', 'message' => 'Gửi không qua trang',
        'cf-turnstile-response' => 'token-sai',
    ])->assertRedirect();

    $lead = app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->sole());
    expect($lead->status)->toBe('spam')->and($lead->is_spam)->toBeTrue();
    Notification::assertNothingSent();
});
