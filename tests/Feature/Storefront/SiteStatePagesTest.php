<?php

use App\Modules\Storefront\Http\Middleware\ShowSiteStatePage;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * A site that is not open to visitors (still being set up, suspended, closed) answers its
 * storefront pages with one polite page in the site's own header and footer, never the bare 403
 * it used to get: the right status for search engines (503 + Retry-After while it is temporary,
 * 410 once closed), kept out of search results, one h1, no reason given, no exception text, and
 * none of the site's pages. A host that no site owns gets a neutral page that names neither a
 * brand nor the host.
 */

beforeEach(function (): void {
    seedTenancy();
    seedHomepage(1, 'Trang chủ Ánh Kim Hà');
    seedHomepage(2, 'Trang chủ Ước Mơ Sứ');
    app(Tenancy::class)->enter(1, fn () => SiteProfile::query()->create(['brand_name' => 'Ánh Kim Hà', 'email' => 'lienhe@anhkimha.test']));
    app(Tenancy::class)->enter(2, fn () => SiteProfile::query()->create(['brand_name' => 'Ước Mơ Sứ', 'email' => 'lienhe@uocmosu.test']));
});

function siteStateSet(int $siteId, string $status): void
{
    DB::connection('pgsql_admin')->table('sites')->where('id', $siteId)->update(['status' => $status]);
}

/** The text of every h1 on the page. */
function siteStateH1s(string $html): array
{
    preg_match_all('~<h1\b[^>]*>(.*?)</h1>~s', $html, $m);

    return array_map(fn (string $text): string => trim(strip_tags($text)), $m[1]);
}

it('answers each state with its own status, heading and way on', function (string $status, int $code, ?string $retryAfter, string $heading, string $action, string $href): void {
    siteStateSet(2, $status);

    $response = $this->get('http://b.test/');
    $response->assertStatus($code);
    expect($response->headers->get('Retry-After'))->toBe($retryAfter);
    // Never kept by the edge or a shared cache: the site may open again at any moment.
    $cacheControl = (string) $response->headers->get('Cache-Control');
    expect($cacheControl)->toContain('private')->toContain('no-store');

    $html = (string) $response->getContent();
    expect(siteStateH1s($html))->toBe([$heading])
        ->and($html)->toContain('class="sf-404')
        ->toContain('class="site-footer"')
        ->toContain('Ước Mơ Sứ')
        ->toContain($action)
        ->toContain('href="'.$href.'"');
})->with([
    'being set up' => ['provisioning', 503, '300', 'Website sắp ra mắt', 'Tải lại trang', '/'],
    'suspended' => ['suspended', 503, '3600', 'Website tạm thời chưa mở được', 'Gửi email cho chúng tôi', 'mailto:lienhe@uocmosu.test'],
    'closed' => ['archived', 410, null, 'Website đã ngừng hoạt động', 'Gửi email cho chúng tôi', 'mailto:lienhe@uocmosu.test'],
]);

it('keeps every state page out of search engines, with no address or share tags of its own', function (string $status): void {
    siteStateSet(2, $status);

    $html = (string) $this->get('http://b.test/gioi-thieu')->getContent();

    expect($html)
        ->toContain('<meta name="robots" content="noindex">')
        ->not->toContain('rel="canonical"')
        ->not->toContain('og:title')
        ->not->toContain('hreflang=')
        ->not->toContain('application/ld+json');
})->with(['provisioning', 'suspended', 'archived']);

it('never shows the site’s pages, another site, a reason or exception text', function (string $status): void {
    siteStateSet(2, $status);

    $html = (string) $this->get('http://b.test/')->getContent();

    expect($html)
        ->not->toContain('Trang chủ Ước Mơ Sứ')
        ->not->toContain('Ánh Kim Hà')
        ->not->toContain('anhkimha')
        ->not->toContain('not active')
        ->not->toContain('Forbidden')
        ->not->toContain('Exception')
        ->not->toContain($status)
        ->not->toContain('SITEB0');
})->with(['provisioning', 'suspended', 'archived']);

it('leaves out the email button and its sentence when the site has no public email', function (): void {
    app(Tenancy::class)->enter(2, fn () => SiteProfile::query()->update(['email' => null]));
    siteStateSet(2, 'suspended');

    $html = (string) $this->get('http://b.test/')->assertStatus(503)->getContent();

    expect($html)
        ->toContain('Xin lỗi vì sự bất tiện. Mời bạn quay lại sau.</p>')
        ->not->toContain('gửi email nếu cần liên hệ gấp')
        ->not->toContain('mailto:')
        ->not->toContain('Gửi email cho chúng tôi');
});

it('reloads the address the visitor opened, never a link off the site', function (): void {
    siteStateSet(2, 'provisioning');

    expect($this->get('http://b.test/san-pham/may-cnc?x=1')->getContent())->toContain('href="/san-pham/may-cnc"')
        ->and($this->get('http://b.test//evil.example/x')->getContent())->not->toContain('href="//evil.example');
});

it('speaks the language of the address the visitor opened', function (): void {
    DB::connection('pgsql_admin')->table('site_locales')->insert(['site_id' => 2, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]);
    siteStateSet(2, 'archived');

    $html = (string) $this->get('http://b.test/en/about')->assertStatus(410)->getContent();

    expect(siteStateH1s($html))->toBe(['This website is no longer active'])
        ->and($html)->toContain('<html lang="en"');
});

it('still serves an open site next to a closed one', function (): void {
    siteStateSet(2, 'archived');

    expect($this->get('http://a.test/')->assertOk()->getContent())->toContain('Trang chủ Ánh Kim Hà');
});

it('keeps refusing writes and the admin of a site that is not open', function (): void {
    siteStateSet(2, 'suspended');

    $this->post('http://b.test/forms/lien-he', [])->assertForbidden();
    $this->get('http://b.test/admin/login')->assertForbidden();
});

it('refuses with a 403 when there is no site status to read (fail-closed)', function (): void {
    // The sites table's CHECK allows only the four known words, so the reachable unknown is none at
    // all: no resolved site, or no row. The gate reads that as closed, never as open.
    app(CurrentSite::class)->clear();
    $reached = false;

    try {
        app(ShowSiteStatePage::class)->handle(Request::create('http://b.test/'), function () use (&$reached): Response {
            $reached = true;

            return response('open');
        });
        $status = null;
    } catch (HttpException $e) {
        $status = $e->getStatusCode();
    }

    expect($status)->toBe(403)->and($reached)->toBeFalse();
});

it('gives a host no site owns a neutral page: no brand, no host, a 404 kept out of search engines', function (): void {
    $response = $this->get('http://ten-mien-la.test/trang-cu');
    $response->assertNotFound();
    $html = (string) $response->getContent();

    expect(siteStateH1s($html))->toBe(['Không tìm thấy website'])
        ->and($html)
        ->toContain('Địa chỉ này chưa gắn với website nào. Kiểm tra lại địa chỉ bạn đã gõ.')
        ->toContain('href="/trang-cu"')
        ->toContain('Tải lại trang')
        ->toContain('<meta name="robots" content="noindex">')
        ->not->toContain('ten-mien-la')
        ->not->toContain('rel="canonical"')
        ->not->toContain('site-footer')
        ->not->toContain('Ánh Kim Hà')
        ->not->toContain('Ước Mơ Sứ');
});

it('speaks English on a host no site owns when the address starts with /en', function (): void {
    $html = (string) $this->get('http://ten-mien-la.test/en/old-page')->assertNotFound()->getContent();

    expect(siteStateH1s($html))->toBe(['Website not found'])
        ->and($html)->toContain('<html lang="en"');
});
