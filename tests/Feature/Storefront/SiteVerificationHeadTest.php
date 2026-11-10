<?php

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;

/*
 * The ownership meta tags (`storefront.verification`): printed in the head of every page, escaped,
 * only for providers switched on with a well-formed code, and read with the settings the page
 * already loads, so a site without them renders the same bytes and makes the same queries.
 */

beforeEach(function (): void {
    seedTenancy();
    seedHomepage(1, 'An Vinh');
    seedHomepage(2, 'Site B');
});

/** @param  array<string, mixed>  $value */
function svhSetting(int $siteId, array $value): void
{
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->updateOrCreate(
        ['group_name' => 'storefront', 'key' => 'verification'],
        ['value' => $value],
    ));
}

const SVH_GOOGLE = 'abcDEF1234567890abcdEF-_xyz';
const SVH_BING = 'ABCDEF1234567890';
const SVH_FACEBOOK = 'abcdef1234567890abcdef12';

it('prints a meta tag for each provider that is switched on with a valid code', function (): void {
    svhSetting(1, [
        'google' => ['on' => true, 'token' => SVH_GOOGLE],
        'bing' => ['on' => true, 'token' => SVH_BING],
        'facebook' => ['on' => true, 'token' => SVH_FACEBOOK],
    ]);

    $html = $this->get('http://a.test/')->assertOk()->getContent();

    expect($html)
        ->toContain('<meta name="google-site-verification" content="'.SVH_GOOGLE.'">')
        ->toContain('<meta name="msvalidate.01" content="'.SVH_BING.'">')
        ->toContain('<meta name="facebook-domain-verification" content="'.SVH_FACEBOOK.'">');
});

it('prints nothing for a provider that is off, empty or holds anything but a plain code', function (): void {
    svhSetting(1, [
        'google' => ['on' => false, 'token' => SVH_GOOGLE],
        'bing' => ['on' => true, 'token' => ''],
        'facebook' => ['on' => true, 'token' => '"><script>alert(1)</script>aaaaaaaaaaaaaaaaaaaa'],
    ]);

    $html = $this->get('http://a.test/')->assertOk()->getContent();

    expect($html)->not->toContain('site-verification')->not->toContain('msvalidate')->not->toContain('<script>alert');
});

it('shows only its own site’s codes', function (): void {
    svhSetting(2, ['google' => ['on' => true, 'token' => SVH_GOOGLE]]);

    $this->get('http://a.test/')->assertOk()->assertDontSee('google-site-verification', false);
    $this->get('http://b.test/')->assertOk()->assertSee('<meta name="google-site-verification" content="'.SVH_GOOGLE.'">', false);
});

it('renders the same bytes as before for a site without codes, and the tags are the only difference', function (): void {
    $without = $this->get('http://a.test/')->assertOk()->getContent();

    svhSetting(1, ['google' => ['on' => false, 'token' => SVH_GOOGLE]]);
    expect($this->get('http://a.test/')->getContent())->toBe($without);

    svhSetting(1, ['google' => ['on' => true, 'token' => SVH_GOOGLE]]);
    $with = $this->get('http://a.test/')->getContent();
    $tag = '<meta name="google-site-verification" content="'.SVH_GOOGLE.'">'."\n";

    expect(str_replace($tag, '', $with))->toBe($without);
});

it('adds no query to the page', function (): void {
    // A setting row that prints nothing is the baseline: only the tags differ from the next run.
    svhSetting(1, ['google' => ['on' => false, 'token' => SVH_GOOGLE]]);
    $this->get('http://a.test/')->assertOk();
    $plain = countQueries(fn () => $this->get('http://a.test/')->assertOk());

    svhSetting(1, [
        'google' => ['on' => true, 'token' => SVH_GOOGLE],
        'bing' => ['on' => true, 'token' => SVH_BING],
        'facebook' => ['on' => true, 'token' => SVH_FACEBOOK],
    ]);
    $this->get('http://a.test/')->assertOk();   // the first run after a save may refill a cache
    $with = countQueries(fn () => $this->get('http://a.test/')->assertOk());

    expect($with)->toBe($plain);
});
