<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Storefront\Support\SiteEmbedCode;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Chrome\StorefrontChrome;
use App\Modules\Theme\Chrome\StorefrontCsp;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

/*
 * A website's extra embed code (Cài đặt › Bảo mật & Nâng cao, «Mã nhúng thêm») on its public pages:
 * printed raw in four places (in <head> after the site's own tags, right after <body>, after the main
 * content before the footer, last before </body>) for a visitor with no session, and only then is the
 * page's Content-Security-Policy loosened. A request that carries a session, a preview, the 404, the
 * maintenance page and every other website get no code and today's strict policy, byte for byte.
 * Switched off, the code is kept and never printed.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    seedHomepage(1, 'Trang chủ Ánh Kim Hà');
    seedHomepage(2, 'Trang chủ Ước Mơ Sứ');
    app(Tenancy::class)->enter(1, fn () => SiteProfile::query()->create(['brand_name' => 'Ánh Kim Hà']));
});

/** One recognisable snippet per place. */
const EC_CODE = [
    'head' => '<meta name="ec-head" content="1">',
    'body_top' => '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-EC" title="ec-body-top"></iframe></noscript>',
    'body_end' => '<div id="ec-body-end"></div>',
    'foot' => '<script>window.ecFoot = 1;</script>',
];

/** @param array<string, mixed> $value */
function ecSet(int $siteId, array $value): void
{
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->updateOrCreate(
        ['group_name' => 'storefront', 'key' => 'embed_code'],
        ['value' => $value],
    ));
}

/** @param array<string, string> $code */
function ecOn(int $siteId, array $code = EC_CODE, bool $on = true): void
{
    ecSet($siteId, ['on' => $on, 'code' => $code, 'acknowledged_at' => '2026-11-22T03:00:00Z', 'acknowledged_by' => null]);
}

/** Each request starts afresh, as Octane starts each one (the scoped embed-code state). */
function ecGet(object $test, string $url): TestResponse
{
    app()->forgetScopedInstances();

    return $test->get($url);
}

function ecCsp(TestResponse $response): string
{
    return (string) $response->headers->get('Content-Security-Policy', '');
}

function ecHasNone(string $html): void
{
    foreach (EC_CODE as $snippet) {
        expect($html)->not->toContain($snippet);
    }
}

it('prints the code in its four places for a visitor with no session, and loosens only that page’s policy', function (string $path): void {
    ecOn(1);

    $response = ecGet($this, 'http://a.test'.$path)->assertOk();
    $html = (string) $response->getContent();

    $at = fn (string $needle): int => (int) strpos($html, $needle);
    foreach (EC_CODE as $snippet) {
        expect(substr_count($html, $snippet))->toBe(1);
    }
    // in <head> after the site's own tags (the stylesheet and the title among them)
    expect($at(EC_CODE['head']))->toBeGreaterThan($at('<title>'))->toBeGreaterThan($at('rel="stylesheet"'))->toBeLessThan($at('</head>'))
        // right after <body>
        ->and(ltrim(substr($html, $at('<body>') + strlen('<body>'))))->toStartWith(EC_CODE['body_top'])
        // after the main content, before the footer
        ->and($at(EC_CODE['body_end']))->toBeGreaterThan(strrpos($html, '</main>'))->toBeLessThan($at('<footer'))
        // last before </body>
        ->and(rtrim(substr($html, 0, (int) strrpos($html, '</body>'))))->toEndWith(EC_CODE['foot'])
        ->and(ecCsp($response))->toBe(StorefrontCsp::withEmbedCode());
    if ($path === '/') {
        // the edge may still keep it (every visitor without a session gets these same bytes), but a
        // browser must ask again before showing its copy: no stale window
        expect((string) $response->headers->get('Cache-Control'))->toContain('public')->toContain('s-maxage')->toContain('must-revalidate')
            ->not->toContain('stale-while-revalidate');
    }
})->with(['the home page' => '/', 'search' => '/tim-kiem?q=may']);

it('loosens only scripts, pixels, connections, frames, styles and fonts to https, never eval, plain http, forms or base', function (): void {
    $csp = StorefrontCsp::withEmbedCode();

    expect($csp)
        ->toContain("script-src 'self' 'unsafe-inline' https:")
        ->toContain("img-src 'self' https://files.baoson.net data: https:")
        ->toContain("connect-src 'self' https: wss:")
        ->toContain('frame-src https:')
        ->toContain("style-src 'self' 'unsafe-inline' https:")
        ->toContain("font-src 'self' https: data:")
        ->toContain("media-src 'self' https://files.baoson.net https:")
        ->toContain("default-src 'self'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'")
        ->not->toContain('unsafe-eval')
        ->not->toMatch('#(?:\s)http:#')
        // a hash would make the browser ignore 'unsafe-inline', so the boot script's hash is left out
        ->not->toContain(StorefrontChrome::textSizeBootHash());
});

it('gives a request carrying a session none of the code and the strict policy', function (?string $role): void {
    ecOn(1);
    if ($role !== null) {
        $this->actingAs(blockLibraryUser(1, $role));
    }

    $response = ecGet($this->withCookie((string) config('session.cookie'), 'member'), 'http://a.test/')->assertOk();

    ecHasNone((string) $response->getContent());
    expect(ecCsp($response))->toBe(StorefrontCsp::header())
        ->and((string) $response->headers->get('Cache-Control'))->toContain('private');
})->with([
    'a signed-in owner' => ['owner'],
    'a signed-in editor' => ['editor'],
    'a session cookie with no one signed in (a sent form)' => [null],
]);

it('keeps the code but prints none of it while the switch is off', function (): void {
    ecOn(1, on: false);

    $response = ecGet($this, 'http://a.test/')->assertOk();

    ecHasNone((string) $response->getContent());
    expect(ecCsp($response))->toBe(StorefrontCsp::header())
        ->and(app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->where('key', 'embed_code')->value('value'))['code'])->toEqual(EC_CODE);
});

it('touches no other website: its pages keep no code and the strict policy, byte for byte', function (): void {
    ecOn(1);

    $response = ecGet($this, 'http://b.test/')->assertOk();

    ecHasNone((string) $response->getContent());
    // the edge and the browser keep it as before
    expect((string) $response->headers->get('Cache-Control'))->toContain('stale-while-revalidate')->not->toContain('must-revalidate');
    // today's policy, written out: a site without the code must never get a different one
    expect(ecCsp($response))->toBe(
        "default-src 'self'; img-src 'self' https://files.baoson.net data:; media-src 'self' https://files.baoson.net; style-src 'self' 'unsafe-inline'; "
        ."script-src 'self' ".StorefrontChrome::textSizeBootHash().' https://challenges.cloudflare.com https://static.cloudflareinsights.com; '
        ."connect-src 'self' https://cloudflareinsights.com; frame-src https://www.google.com https://challenges.cloudflare.com https://www.youtube-nocookie.com https://player.vimeo.com; "
        ."font-src 'self'; base-uri 'self'; form-action 'self'"
    )->and(ecCsp($response))->toBe(StorefrontCsp::header());
});

it('prints nothing, with the strict policy, when the switch is on but every box is empty', function (): void {
    ecOn(1, ['head' => '', 'body_top' => '  ', 'body_end' => '', 'foot' => '']);

    expect(ecCsp(ecGet($this, 'http://a.test/')->assertOk()))->toBe(StorefrontCsp::header());
});

it('never prints code on the 404, the maintenance page or the owner’s maintenance preview', function (): void {
    ecOn(1);

    $missing = ecGet($this, 'http://a.test/khong-co-trang-nay')->assertNotFound();
    ecHasNone((string) $missing->getContent());
    expect(ecCsp($missing))->toBe(StorefrontCsp::header());

    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'maintenance', 'value' => ['on' => true, 'messages' => ['vi' => 'Đang nâng cấp.'], 'reopens_at' => null]]));
    $maintenance = ecGet($this, 'http://a.test/')->assertStatus(503)->assertSee('Đang nâng cấp.');
    ecHasNone((string) $maintenance->getContent());
    expect(ecCsp($maintenance))->toBe(StorefrontCsp::header());

    app()->forgetScopedInstances();
    $preview = $this->actingAs(blockLibraryUser(1, 'owner'))->get('http://a.test/admin/settings/advanced/preview')->assertOk();
    ecHasNone((string) $preview->getContent());
});

it('drops a stored code over the size limit instead of cutting it', function (): void {
    ecOn(1, ['head' => '', 'body_top' => '', 'body_end' => '', 'foot' => '<script>'.str_repeat('a', SiteEmbedCode::MAX_BYTES).'</script>']);

    $response = ecGet($this, 'http://a.test/')->assertOk();

    expect((string) $response->getContent())->not->toContain('aaaaaaaaaaaaaaaaaaaa')
        ->and(ecCsp($response))->toBe(StorefrontCsp::header())
        ->and(SiteEmbedCode::shape(['on' => true, 'code' => ['foot' => str_repeat('a', SiteEmbedCode::MAX_BYTES)]])['code']['foot'])->toHaveLength(SiteEmbedCode::MAX_BYTES);
});

it('answers HEAD and a matching 304 like the page it stands for, and never matches the copy without code', function (): void {
    ecOn(1);

    $page = ecGet($this, 'http://a.test/')->assertOk();
    $etag = (string) $page->headers->get('ETag');
    expect($etag)->not->toBe('');

    $same = $this->get('http://a.test/', ['If-None-Match' => $etag])->assertStatus(304);
    expect(ecCsp($same))->toBe(StorefrontCsp::withEmbedCode());

    app()->forgetScopedInstances();
    $head = $this->call('HEAD', 'http://a.test/')->assertOk();
    expect(ecCsp($head))->toBe(StorefrontCsp::withEmbedCode());

    // signed in since: the same tag no longer matches, the page comes back without the code
    app()->forgetScopedInstances();
    $member = $this->actingAs(blockLibraryUser(1, 'owner'))->withCookie((string) config('session.cookie'), 'member')
        ->get('http://a.test/', ['If-None-Match' => $etag])->assertOk();
    ecHasNone((string) $member->getContent());
    expect(ecCsp($member))->toBe(StorefrontCsp::header())
        ->and((string) $member->headers->get('ETag'))->not->toBe($etag);
});

it('keeps the strict policy on an error answered after the code was printed', function (int $status): void {
    $embed = app(SiteEmbedCode::class);
    (fn () => $this->printed = true)->call($embed);
    expect($embed->printed())->toBeTrue();

    $answer = app(AddSecurityHeaders::class)->handle(Request::create('http://a.test/'), fn () => new Response('x', $status));

    expect((string) $answer->headers->get('Content-Security-Policy'))->toBe($status === 200 ? StorefrontCsp::withEmbedCode() : StorefrontCsp::header());
})->with([200, 404, 500, 503]);
