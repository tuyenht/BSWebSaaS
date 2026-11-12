<?php

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Chrome\WebAnalytics;

/*
 * The site's own Cloudflare Web Analytics token (`storefront.web_analytics`): with a well-formed
 * one, every storefront page closes with Cloudflare's manual beacon snippet, once, just before
 * </body>, so real-user data reports to cloudflareinsights.com. Anything else prints nothing, the
 * admin never carries it, and the storefront's Content-Security-Policy stays exactly as it is.
 */

const WA_TOKEN = '0123456789abcdef0123456789abcdef';

beforeEach(function (): void {
    seedTenancy();
    seedHomepage(1, 'Trang chủ');
});

function waSetting(mixed $value, int $site = 1): void
{
    app(Tenancy::class)->enter($site, fn () => SiteSetting::query()->updateOrCreate(
        ['group_name' => 'storefront', 'key' => 'web_analytics'],
        ['value' => $value],
    ));
}

function waSnippet(string $token): string
{
    return '<script defer src="https://static.cloudflareinsights.com/beacon.min.js" data-cf-beacon=\'{"token": "'.$token.'"}\'></script>';
}

it('closes every storefront page with Cloudflare’s beacon, once, just before </body>', function (string $url, int $status): void {
    waSetting(['cloudflare_token' => WA_TOKEN]);

    $html = (string) $this->get($url)->assertStatus($status)->getContent();

    expect(substr_count($html, 'beacon.min.js'))->toBe(1)
        ->and(substr_count($html, 'data-cf-beacon'))->toBe(1)
        ->and($html)->toMatch('~'.preg_quote(waSnippet(WA_TOKEN), '~').'\s*</body>~');
})->with([
    'the home page' => ['http://a.test/', 200],
    'the site’s own 404 page' => ['http://a.test/khong-co-trang-nay', 404],
]);

it('prints nothing without a well-formed token', function (mixed $value): void {
    if ($value !== null) {
        waSetting($value);
    }

    $html = (string) $this->get('http://a.test/')->assertOk()->getContent();

    expect($html)->not->toContain('cloudflareinsights')->not->toContain('data-cf-beacon');
})->with([
    'no setting' => [null],
    'an empty value' => [[]],
    'no token' => [['cloudflare_token' => '']],
    'upper-case hex' => [['cloudflare_token' => strtoupper(WA_TOKEN)]],
    'too short' => [['cloudflare_token' => substr(WA_TOKEN, 1)]],
    'too long' => [['cloudflare_token' => WA_TOKEN.'0']],
    'a trailing newline' => [['cloudflare_token' => WA_TOKEN."\n"]],
    'not a string' => [['cloudflare_token' => 12345678901234567890]],
    'an injection attempt' => [['cloudflare_token' => 'abc"></script><script>alert(1)</script>']],
    'an attribute break-out' => [['cloudflare_token' => "0123456789abcdef'onload='alert(1)"]],
    'another key' => [['token' => WA_TOKEN]],
]);

it('never prints one site’s token on another site', function (): void {
    seedHomepage(2, 'Trang B');
    waSetting(['cloudflare_token' => WA_TOKEN]);

    expect((string) $this->get('http://b.test/')->assertOk()->getContent())->not->toContain('data-cf-beacon');
});

it('never puts the beacon on an admin page', function (): void {
    waSetting(['cloudflare_token' => WA_TOKEN]);

    expect((string) $this->get('http://a.test/admin/login')->assertOk()->getContent())
        ->not->toContain('cloudflareinsights')->not->toContain('data-cf-beacon');
});

it('leaves the storefront Content-Security-Policy exactly as it is', function (): void {
    $before = (string) $this->get('http://a.test/')->headers->get('Content-Security-Policy');
    waSetting(['cloudflare_token' => WA_TOKEN]);
    app()->forgetScopedInstances();
    $after = (string) $this->get('http://a.test/')->headers->get('Content-Security-Policy');

    expect($after)->toBe($before)
        ->toMatch('~(?:^|; )script-src [^;]* https://static\.cloudflareinsights\.com(?:;|$)~')
        ->toMatch('~(?:^|; )connect-src \'self\' https://cloudflareinsights\.com(?:;|$)~');
});

it('builds the beacon attribute in Cloudflare’s shape with every quote escaped', function (): void {
    expect(WebAnalytics::beaconConfig(WA_TOKEN))->toBe('{"token": "'.WA_TOKEN.'"}')
        ->and(WebAnalytics::beaconConfig("a'b\"<c>&"))->toBe('{"token": "a\u0027b\u0022\u003Cc\u003E\u0026"}')
        ->and(WebAnalytics::token(['cloudflare_token' => WA_TOKEN]))->toBe(WA_TOKEN)
        ->and(WebAnalytics::token('0123456789abcdef0123456789abcdef'))->toBeNull()
        ->and(WebAnalytics::token(null))->toBeNull();
});
