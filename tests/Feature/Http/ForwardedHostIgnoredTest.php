<?php

declare(strict_types=1);

use App\Modules\Rbac\Context\TeamContext;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/*
 * The edge Caddy is a trusted proxy (private bridge IP) and forwards the visitor's own Host header.
 * Through Cloudflare it keeps whatever X-Forwarded-Host the visitor wrote, so the app must take the
 * host from Host alone: a forged X-Forwarded-Host may never choose the site a request resolves to,
 * and X-Forwarded-Port / X-Forwarded-Prefix may never reach generated URLs. The visitor's IP
 * (X-Forwarded-For) and the scheme (X-Forwarded-Proto) are still read from the proxy.
 */

const FORWARDED_TEST_EDGE_PEER = ['REMOTE_ADDR' => '172.16.0.5'];

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    app(TeamContext::class)->forget();
});

it('resolves the site from Host, not from a forged X-Forwarded-Host', function (): void {
    /** @var TestCase $this */
    seedHomepage(1, 'Nhà của Ân');
    seedHomepage(2, 'Nhà của Bình');

    $body = $this->call('GET', 'http://a.test/', [], [], [], FORWARDED_TEST_EDGE_PEER + [
        'HTTP_X_FORWARDED_HOST' => 'b.test',
    ])->assertOk()->getContent();

    expect($body)->toContain('Nhà của Ân')->not->toContain('Nhà của Bình');
});

it('does not let X-Forwarded-Host turn a tenant host into the platform host', function (): void {
    /** @var TestCase $this */
    seedHomepage(1, 'Nhà của Ân');

    $this->call('GET', 'http://a.test/', [], [], [], FORWARDED_TEST_EDGE_PEER + [
        'HTTP_X_FORWARDED_HOST' => (string) config('tenancy.platform_host'),
    ])->assertOk()->assertSee('Nhà của Ân');
});

it('answers 404 for an unknown Host even when X-Forwarded-Host names a real site', function (): void {
    /** @var TestCase $this */
    seedHomepage(1, 'Nhà của Ân');

    $this->call('GET', 'http://nope.test/', [], [], [], FORWARDED_TEST_EDGE_PEER + [
        'HTTP_X_FORWARDED_HOST' => 'a.test',
    ])->assertNotFound();
});

it('keeps forwarded port and prefix out of generated URLs but still reads scheme and visitor IP', function (): void {
    /** @var TestCase $this */
    Route::get('/__forwarded_probe', fn () => response()->json([
        'secure' => request()->isSecure(),
        'host' => request()->getHttpHost(),
        'url' => url('/lien-he'),
        'ip' => request()->ip(),
    ]));

    $this->call('GET', 'http://a.test/__forwarded_probe', [], [], [], FORWARDED_TEST_EDGE_PEER + [
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'b.test',
        'HTTP_X_FORWARDED_PORT' => '8443',
        'HTTP_X_FORWARDED_PREFIX' => '/lua-dao',
    ])->assertOk()->assertExactJson([
        'secure' => true,
        'host' => 'a.test',
        'url' => 'https://a.test/lien-he',
        'ip' => '203.0.113.7',
    ]);
});

it('still validates a signed https link behind the edge proxy', function (): void {
    /** @var TestCase $this */
    Route::get('/__signed_probe', fn () => request()->hasValidSignature() ? 'hợp lệ' : 'không hợp lệ')
        ->name('forwarded.signed-probe');
    app('router')->getRoutes()->refreshNameLookups();

    URL::forceScheme('https');
    URL::forceRootUrl('https://a.test');
    $signed = URL::signedRoute('forwarded.signed-probe');
    URL::forceRootUrl(null);

    expect($signed)->toStartWith('https://a.test/__signed_probe?');

    // Caddy talks plain http to the app and says https in X-Forwarded-Proto.
    $response = $this->call('GET', str_replace('https://', 'http://', $signed), [], [], [], FORWARDED_TEST_EDGE_PEER + [
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_PORT' => '8443',
    ])->assertOk();

    expect($response->getContent())->toBe('hợp lệ');
});
