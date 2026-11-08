<?php

use App\Modules\Shared\Http\SafeHttp;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Jobs\CheckAiCrawlers;
use App\Modules\Theme\Theming\AiCrawlerCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/*
 * "Do AI bots reach the website?": the check looks at the site's primary address over https, makes
 * at most six requests through the shared guard (robots.txt, the home page as a browser and as four
 * AI crawlers), and reports each finding as information, a warning or "could not check" — never a
 * false alarm for what the site or the platform itself chose. Everything is faked: the resolver
 * answers DNS, Http::fake answers the requests.
 */

const AI_PUBLIC_IP = '93.184.216.34';

beforeEach(function (): void {
    seedTenancy();
    config(['tenancy.noindex_platform_subdomains' => false]);
    app()->instance(SafeHttp::class, new SafeHttp(static fn (string $host): array => [AI_PUBLIC_IP]));
    Http::preventStrayRequests();
    RateLimiter::clear(CheckAiCrawlers::LIMITER);
});

/** @param  array<string, mixed>  $seo */
function aiSeo(array $seo, int $siteId = 1): void
{
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'seo', 'key' => 'config'], ['value' => $seo]));
}

/**
 * Answer the site's requests. Each answer is [status, body, headers]: robots.txt, the home page
 * for the browser, the home page for a probe bot (by name; 200 unless named), and headers every
 * answer carries.
 *
 * @param  array{0: int, 1?: string, 2?: array<string, string>}|null  $robots
 * @param  array{0: int, 1?: string, 2?: array<string, string>}  $home
 * @param  array<string, array{0: int, 1?: string, 2?: array<string, string>}>  $bots
 * @param  array<string, string>  $all
 */
function aiFakeSite(string $host = 'a.test', ?array $robots = null, array $home = [200], array $bots = [], array $all = []): void
{
    $robots ??= [200, "User-agent: *\nDisallow:\nSitemap: https://{$host}/sitemap.xml"];
    $names = array_flip((array) config('seo.ai_crawlers.probes'));

    Http::fake(function (Request $request) use ($host, $robots, $home, $bots, $all, $names) {
        $bot = $names[$request->header('User-Agent')[0] ?? ''] ?? null;
        $answer = match (true) {
            $request->url() === "https://{$host}/robots.txt" => $robots,
            $request->url() === "https://{$host}/" && $bot !== null => $bots[$bot] ?? [200],
            $request->url() === "https://{$host}/" => $home,
            default => [404],
        };

        return Http::response($answer[1] ?? '<!doctype html><title>Trang chủ</title>', $answer[0], [...$all, ...($answer[2] ?? [])]);
    });
}

/** Run the check for the site as the queue would, and read back what it kept. */
function aiRun(int $siteId = 1): ?array
{
    CheckAiCrawlers::dispatchSync($siteId);

    $host = app(SiteResolver::class)->primaryHostFor($siteId);

    return $host === null ? null : app(AiCrawlerCheck::class)->result($siteId, $host);
}

/** @return list<string> "level:code" of each finding */
function aiCodes(?array $result): array
{
    return array_map(static fn (array $f): string => $f['level'].':'.$f['code'], $result['findings'] ?? []);
}

it('finds nothing in the way on an ordinary site, in exactly six requests over https', function (): void {
    aiFakeSite();

    $result = aiRun();

    expect($result['level'])->toBe('ok')->and($result['findings'])->toBe([])->and($result['host'])->toBe('a.test');
    Http::assertSentCount(6);
    Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://a.test/'));
    Http::assertNotSent(fn (Request $r): bool => ! str_starts_with($r->url(), 'https://a.test/'));
});

it('says the site is hidden from every search engine, not that AI bots are blocked', function (): void {
    aiSeo(['indexable' => false]);
    aiFakeSite(robots: [200, "User-agent: *\nDisallow: /"]);

    expect(aiCodes(aiRun()))->toBe(['info:hidden']);
});

it('names the bots the site’s own robots.txt text blocks, fully or in part, as information only', function (): void {
    $own = "User-agent: GPTBot\nUser-agent: Google-Extended\nDisallow: /\n\nUser-agent: CCBot\nDisallow: /sản-phẩm\n\nUser-agent: *\nDisallow:";
    aiSeo(['robots' => $own]);
    aiFakeSite(robots: [200, $own."\nSitemap: https://a.test/sitemap.xml"]);

    $result = aiRun();

    expect(aiCodes($result))->toBe(['info:own_blocks', 'info:own_partial'])
        ->and($result['level'])->toBe('info')
        ->and($result['findings'][0]['params']['bots'])->toBe(['GPTBot', 'Google-Extended'])
        ->and($result['findings'][1]['params']['bots'])->toBe(['CCBot']);
});

it('warns when the robots.txt served adds a block on AI bots the site never wrote', function (): void {
    $cloudflare = (string) file_get_contents(base_path('tests/Fixtures/robots-cloudflare-managed.txt'));
    aiFakeSite(robots: [200, $cloudflare."\nUser-agent: GPTBot\nDisallow: /\n\nUser-agent: ClaudeBot\nDisallow: /\n\nUser-agent: *\nContent-Signal: search=yes, ai-train=no\nDisallow:\n"]);

    $result = aiRun();

    expect(aiCodes($result))->toBe(['warning:proxy_blocks', 'info:content_signal'])
        ->and($result['level'])->toBe('warning')
        ->and($result['findings'][0]['params']['bots'])->toBe(['GPTBot', 'ClaudeBot'])
        ->and($result['findings'][1]['params']['signals'])->toBe(['search=yes, ai-train=no']);
});

it('reads Cloudflare’s managed robots.txt (comments only) in front of the site’s own as no block', function (): void {
    $cloudflare = (string) file_get_contents(base_path('tests/Fixtures/robots-cloudflare-managed.txt'));
    aiFakeSite(robots: [200, $cloudflare."\nUser-agent: *\nDisallow:\nSitemap: https://a.test/sitemap.xml"]);

    expect(aiRun()['findings'])->toBe([]);
});

it('only names a Content-Signal line — it blocks nothing', function (): void {
    aiFakeSite(robots: [200, "User-agent: *\nContent-Signal: ai-train=no\nDisallow:"]);

    expect(aiCodes(aiRun()))->toBe(['info:content_signal']);
});

it('ignores the Sitemap line, comments and line endings when comparing robots.txt', function (): void {
    aiFakeSite(robots: [200, "# Tệp của máy chủ khác\r\nUser-agent: *\r\nDisallow:\r\nSitemap: https://www.khác.vn/sitemap.xml\r\n"]);

    expect(aiRun()['findings'])->toBe([]);
});

it('warns when bots are refused while the browser gets in, and only names odd answers', function (): void {
    aiFakeSite(bots: ['GPTBot' => [403], 'ClaudeBot' => [429], 'PerplexityBot' => [404]]);

    $result = aiRun();

    expect(aiCodes($result))->toBe(['warning:bot_refused', 'info:bot_odd'])
        ->and($result['findings'][0]['params']['bots'])->toBe(['GPTBot:403', 'ClaudeBot:429'])
        ->and($result['findings'][1]['params']['bots'])->toBe(['PerplexityBot:404']);
});

it('only informs behind Cloudflare, which treats a bot name from a server as an impostor', function (): void {
    aiFakeSite(bots: ['GPTBot' => [403, 'Đang kiểm tra', ['cf-mitigated' => 'challenge']], 'OAI-SearchBot' => [200, 'ổn', ['cf-mitigated' => 'challenge']]], all: ['Server' => 'cloudflare']);

    $result = aiRun();

    expect(aiCodes($result))->toBe(['info:cloudflare_challenge'])
        ->and($result['level'])->toBe('info')
        ->and($result['findings'][0]['params']['bots'])->toBe(['GPTBot:403', 'OAI-SearchBot:200']);
});

it('cannot check when the browser itself does not get 200, and compares nothing further', function (): void {
    aiFakeSite(home: [503]);

    $result = aiRun();

    expect(aiCodes($result))->toBe(['unchecked:site_status'])
        ->and($result['findings'][0]['params']['status'])->toBe(503)
        ->and($result['level'])->toBe('unchecked');
    Http::assertSentCount(1);
});

it('stops at a redirect to another host and names it', function (): void {
    aiFakeSite(home: [301, '', ['Location' => 'https://www.khác.test/']]);

    $result = aiRun();

    expect(aiCodes($result))->toBe(['unchecked:redirect_off_host'])
        ->and($result['findings'][0]['params']['host'])->toBe('www.khác.test');
    Http::assertSentCount(1);
});

it('follows a redirect on the same host', function (): void {
    Http::fake([
        'https://a.test/robots.txt' => Http::response("User-agent: *\nDisallow:", 200),
        'https://a.test/' => Http::response('', 301, ['Location' => '/vi']),
        'https://a.test/vi' => Http::response('Trang chủ', 200),
    ]);

    expect(aiRun()['findings'])->toBe([]);
    Http::assertSentCount(11);
});

it('takes the platform’s own noindex on its temporary address as information, never a warning', function (): void {
    config(['tenancy.noindex_platform_subdomains' => true]);
    aiFakeSite(all: ['X-Robots-Tag' => 'noindex']);

    expect(aiCodes(aiRun()))->toBe(['info:platform_hidden']);
});

it('warns about a noindex the platform did not set', function (): void {
    aiFakeSite(bots: ['ClaudeBot' => [200, 'ổn', ['X-Robots-Tag' => 'noindex, nofollow']]]);

    expect(aiCodes(aiRun()))->toBe(['warning:noindex_header']);
});

it('cannot check an address that does not resolve or does not answer', function (): void {
    app()->instance(SafeHttp::class, new SafeHttp(static fn (string $host): array => []));
    aiFakeSite();

    expect(aiCodes(aiRun()))->toBe(['unchecked:not_pointing']);
    Http::assertNothingSent();

    app()->instance(SafeHttp::class, new SafeHttp(static fn (string $host): array => [AI_PUBLIC_IP]));
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

    expect(aiCodes(aiRun()))->toBe(['unchecked:not_pointing']);
});

it('never calls an address that resolves to a private network', function (): void {
    app()->instance(SafeHttp::class, new SafeHttp(static fn (string $host): array => ['::ffff:10.0.0.7']));
    aiFakeSite();

    expect(aiCodes(aiRun()))->toBe(['unchecked:private_address']);
    Http::assertNothingSent();
});

it('does not conclude "no block" from a robots.txt longer than it reads', function (): void {
    aiFakeSite(robots: [200, "User-agent: *\nDisallow:\n".str_repeat("# chú thích dài\n", 40000)]);

    expect(aiCodes(aiRun()))->toBe(['unchecked:robots_too_long']);
});

it('checks the site’s primary address — a verified own domain, never one waiting or only redirecting', function (): void {
    $admin = DB::connection('pgsql_admin');
    $admin->table('domains')->where('site_id', 1)->update(['is_primary' => false]);
    $admin->table('domains')->insert([
        ['site_id' => 1, 'hostname' => 'cho-anvinh.test', 'kind' => 'custom', 'is_primary' => false, 'is_redirect' => false, 'verified_at' => null, 'ssl_status' => null, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 1, 'hostname' => 'anvinh.test', 'kind' => 'custom', 'is_primary' => true, 'is_redirect' => false, 'verified_at' => now(), 'ssl_status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 1, 'hostname' => 'www.anvinh.test', 'kind' => 'custom', 'is_primary' => false, 'is_redirect' => true, 'verified_at' => now(), 'ssl_status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    ]);
    aiFakeSite('anvinh.test');

    expect(aiRun()['host'])->toBe('anvinh.test');
    Http::assertNotSent(fn (Request $r): bool => ! str_starts_with($r->url(), 'https://anvinh.test/'));
});

it('falls back to the platform address while the own primary domain is failing, as SiteResolver does', function (): void {
    $admin = DB::connection('pgsql_admin');
    $admin->table('domains')->where('site_id', 1)->update(['is_primary' => false]);
    $admin->table('domains')->insert(['site_id' => 1, 'hostname' => 'anvinh.test', 'kind' => 'custom', 'is_primary' => true, 'verified_at' => now(), 'failing_since' => now(), 'ssl_status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    aiFakeSite();

    expect(aiRun()['host'])->toBe('a.test');
});

it('checks nothing for a site without a verified address', function (): void {
    DB::connection('pgsql_admin')->table('domains')->where('site_id', 1)->update(['verified_at' => null]);

    CheckAiCrawlers::dispatchSync(1);

    expect(app(SiteResolver::class)->primaryHostFor(1))->toBeNull();
    Http::assertNothingSent();
});

it('keeps each result under its own site and host — two sites never share one', function (): void {
    Http::fake([
        'https://a.test/robots.txt' => Http::response("User-agent: *\nDisallow:", 200),
        'https://a.test/' => Http::response('', 200),
        'https://b.test/*' => Http::response('', 503),
    ]);

    aiRun(1);
    aiRun(2);
    $check = app(AiCrawlerCheck::class);

    expect($check->result(1, 'a.test')['level'])->toBe('ok')
        ->and($check->result(2, 'b.test')['level'])->toBe('unchecked')
        ->and($check->result(1, 'b.test'))->toBeNull()
        ->and($check->result(2, 'a.test'))->toBeNull();
});

it('holds the platform to its checks per minute — the rest wait in the queue', function (): void {
    config(['seo.ai_crawlers.platform_per_minute' => 1]);
    aiFakeSite();

    aiRun(1);
    CheckAiCrawlers::dispatchSync(2);

    expect(app(AiCrawlerCheck::class)->result(1, 'a.test'))->not->toBeNull()
        ->and(app(AiCrawlerCheck::class)->result(2, 'b.test'))->toBeNull();
    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'b.test'));
});

it('runs inside the site’s own tenant context', function (): void {
    aiSeo(['indexable' => false], 2);
    Http::fake(['*' => fn () => Http::response("User-agent: *\nDisallow: /", 200)]);

    expect(aiCodes(aiRun(2)))->toBe(['info:hidden'])
        ->and(aiCodes(aiRun(1)))->toBe(['warning:proxy_blocks']);
});

it('holds no database transaction while it calls the site', function (): void {
    $levels = [];
    Http::fake(function (Request $request) use (&$levels) {
        $levels[] = DB::connection()->transactionLevel();

        return Http::response("User-agent: *\nDisallow:", 200);
    });

    expect(aiRun()['level'])->toBe('ok')
        ->and($levels)->toHaveCount(6)
        ->and(array_unique($levels))->toBe([0]);
});
