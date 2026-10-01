<?php

use App\Modules\Tenancy\Context\SiteResolver;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * SiteResolver is the one place a hostname becomes a site (design.md §5 layer #1, §28). Only a
 * verified domain resolves; the answer is remembered in the shared cache — hits and misses — and
 * forgotten on demand; junk hosts never reach the cache or the database; a broken cache falls
 * back to the database instead of taking the site down.
 */

beforeEach(function (): void {
    seedTenancy();
    Cache::flush();
});

/** @param array<string, mixed> $overrides */
function attachDomain(array $overrides): void
{
    DB::connection('pgsql_admin')->table('domains')->insert($overrides + [
        'site_id' => 1, 'kind' => 'custom', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function domainQueries(callable $run): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $run();
    DB::disableQueryLog();

    return count(array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], '"domains"')));
}

it('resolves a platform subdomain to its site', function (): void {
    $resolved = app(SiteResolver::class)->resolve('b.test');

    expect($resolved?->siteId)->toBe(2)
        ->and($resolved?->isPlatformSubdomain)->toBeTrue()
        ->and($resolved?->mayHaveCertificate())->toBeTrue();
});

it('resolves a verified customer domain, and tells whether HTTPS is on', function (?string $sslStatus, bool $certificate): void {
    attachDomain(['hostname' => 'shop.example', 'verified_at' => now(), 'ssl_status' => $sslStatus]);

    $resolved = app(SiteResolver::class)->resolve('shop.example');

    expect($resolved?->siteId)->toBe(1)
        ->and($resolved?->isPlatformSubdomain)->toBeFalse()
        ->and($resolved?->mayHaveCertificate())->toBe($certificate);
})->with([
    'HTTPS not turned on' => [null, false],
    'HTTPS being issued' => ['pending', true],
    'HTTPS on' => ['active', true],
    'HTTPS failed, may retry' => ['failed', true],
]);

it('does not resolve a domain that is attached but not verified', function (): void {
    attachDomain(['hostname' => 'waiting.example']);

    expect(app(SiteResolver::class)->resolve('waiting.example'))->toBeNull();
});

it('does not resolve a host nobody attached', function (): void {
    expect(app(SiteResolver::class)->resolve('unknown.example'))->toBeNull();
});

it('matches the host whatever its case or trailing dot', function (): void {
    expect(app(SiteResolver::class)->resolve('A.Test.')?->siteId)->toBe(1);
});

it('remembers a hit: the second lookup does not query', function (): void {
    $sites = app(SiteResolver::class);

    expect(domainQueries(fn () => $sites->resolve('a.test')))->toBe(1)
        ->and(domainQueries(fn () => $sites->resolve('a.test')))->toBe(0)
        ->and($sites->resolve('a.test')?->siteId)->toBe(1);
});

it('remembers a miss: an unknown host is queried once', function (): void {
    $sites = app(SiteResolver::class);

    expect(domainQueries(fn () => $sites->resolve('unknown.example')))->toBe(1)
        ->and(domainQueries(fn () => $sites->resolve('unknown.example')))->toBe(0)
        ->and($sites->resolve('unknown.example'))->toBeNull();
});

it('sees a domain verified after a remembered miss once the host is forgotten', function (): void {
    $sites = app(SiteResolver::class);
    $sites->resolve('shop.example');

    attachDomain(['hostname' => 'shop.example', 'verified_at' => now()]);

    expect($sites->resolve('shop.example'))->toBeNull();   // still the remembered miss

    $sites->forget('shop.example');

    expect($sites->resolve('shop.example')?->siteId)->toBe(1);
});

it('stops resolving a removed domain once the host is forgotten', function (): void {
    attachDomain(['hostname' => 'shop.example', 'verified_at' => now()]);
    $sites = app(SiteResolver::class);
    $sites->resolve('shop.example');

    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')->delete();
    $sites->forget('SHOP.example');

    expect($sites->resolve('shop.example'))->toBeNull();
});

it('refuses a host that is not shaped like a hostname without querying or caching', function (string $host): void {
    $sites = app(SiteResolver::class);

    expect(domainQueries(fn () => $sites->resolve($host)))->toBe(0)
        ->and($sites->resolve($host))->toBeNull()
        ->and(SiteResolver::normalize($host))->toBeNull();
})->with([
    'empty' => [''],
    'single label' => ['localhost'],
    'IPv4' => ['203.0.113.7'],
    'underscore' => ['bad_host.example'],
    'space' => ['a b.example'],
    'leading hyphen' => ['-shop.example'],
    'wildcard' => ['*.example.com'],
    'path glued on' => ['shop.example/admin'],
    'too long' => [str_repeat('a', 250).'.example'],
]);

it('accepts punycode names', function (): void {
    expect(SiteResolver::normalize('xn--cng-ty-an-vinh-9ib.xn--vitnam-jk8b'))->toBe('xn--cng-ty-an-vinh-9ib.xn--vitnam-jk8b');
});

it('answers from the database when the cache is down', function (): void {
    $broken = Mockery::mock(Repository::class);
    $broken->shouldReceive('get')->andThrow(new RuntimeException('cache down'));
    $broken->shouldReceive('put')->andThrow(new RuntimeException('cache down'));
    $broken->shouldReceive('forget')->andThrow(new RuntimeException('cache down'));

    $sites = new SiteResolver($broken);

    expect($sites->resolve('a.test')?->siteId)->toBe(1)
        ->and($sites->resolve('unknown.example'))->toBeNull();

    $sites->forget('a.test');   // must not throw either
});
