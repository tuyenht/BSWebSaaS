<?php

use App\Modules\Storefront\Http\Controllers\RecordContactClick;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;

/*
 * A tap on a storefront contact button is sent by the visitor's browser (sendBeacon) to
 * POST /_click. Public like the page-view beacon (no session, no cookie, no CSRF token): the website
 * is the Host's only, the channel and placement must be known ones, crawlers are ignored and each
 * address is limited. The tap goes to the Valkey buffer only — the request touches no table.
 */

beforeEach(function (): void {
    seedTenancy(); // sites 1 (a.test) / 2 (b.test)
    Cache::flush();
    forgetContactClicks();
});

afterEach(function (): void {
    forgetContactClicks();
});

/** POST a tap the way sendBeacon sends it: a text/plain JSON body. */
function sendClickBeacon(string $host, string $body, array $headers = []): TestResponse
{
    return test()->call('POST', "http://{$host}/_click", [], [], [], [
        'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
        'HTTP_USER_AGENT' => $headers['User-Agent'] ?? 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Mobile Safari/604.1',
    ], $body);
}

/** The site's buffered taps as channel|placement → count (the time slot left out). */
function bufferedClicks(int $site): array
{
    $counts = [];
    foreach (Redis::hgetall('contact_clicks:'.$site) as $field => $count) {
        [, $channel, $placement] = explode('|', (string) $field);
        $counts["{$channel}|{$placement}"] = ($counts["{$channel}|{$placement}"] ?? 0) + (int) $count;
    }
    ksort($counts);

    return $counts;
}

it('counts a tap into the site of the Host, with no session and no cookie', function (): void {
    $response = sendClickBeacon('a.test', '{"channel":"zalo","placement":"bar"}')->assertNoContent();
    sendClickBeacon('a.test', '{"channel":"zalo","placement":"bar"}')->assertNoContent();
    sendClickBeacon('a.test', '{"channel":"c12","placement":"floating"}')->assertNoContent();

    expect($response->headers->has('Set-Cookie'))->toBeFalse()
        ->and($response->headers->getCookies())->toBe([])
        ->and(bufferedClicks(1))->toBe(['c12|floating' => 1, 'zalo|bar' => 2])
        ->and(bufferedClicks(2))->toBe([])
        ->and(array_map(intval(...), Redis::smembers('contact_clicks:dirty')))->toBe([1]);
});

it('counts a tap on “Mua hàng” in the phone bar, the one place it is drawn', function (string $placement, array $counted): void {
    sendClickBeacon('a.test', '{"channel":"buy","placement":"'.$placement.'"}')->assertNoContent();

    expect(bufferedClicks(1))->toBe($counted)
        ->and(bufferedClicks(2))->toBe([]);
})->with([
    'the phone bar' => ['bar', ['buy|bar' => 1]],
    'the round buttons' => ['floating', []],
    'the speed dial' => ['dial', []],
    'the bottom sheet' => ['sheet', []],
]);

it('reads a form-encoded body too', function (): void {
    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 Firefox/140.0'])
        ->post('http://a.test/_click', ['channel' => 'phone', 'placement' => 'dial'])
        ->assertNoContent();

    expect(bufferedClicks(1))->toBe(['phone|dial' => 1]);
});

it('ignores a site named in the body: the tap counts for the Host only', function (): void {
    sendClickBeacon('a.test', '{"channel":"phone","placement":"sheet","site_id":2,"site":"b.test"}')->assertNoContent();

    expect(bufferedClicks(1))->toBe(['phone|sheet' => 1])
        ->and(bufferedClicks(2))->toBe([]);
});

it('counts nothing for a host with no site or a site that is not open', function (): void {
    DB::connection('pgsql_admin')->table('sites')->where('id', 2)->update(['status' => 'suspended']);

    sendClickBeacon('nope.test', '{"channel":"zalo","placement":"bar"}')->assertNotFound();
    sendClickBeacon('b.test', '{"channel":"zalo","placement":"bar"}')->assertForbidden();

    expect(bufferedClicks(1))->toBe([])
        ->and(bufferedClicks(2))->toBe([])
        ->and(Redis::smembers('contact_clicks:dirty'))->toBe([]);
});

it('answers 204 and counts nothing for an unknown channel or placement', function (string $body): void {
    sendClickBeacon('a.test', $body)->assertNoContent();

    expect(bufferedClicks(1))->toBe([])
        ->and(Redis::smembers('contact_clicks:dirty'))->toBe([]);
})->with([
    'an unknown channel' => ['{"channel":"snapchat","placement":"bar"}'],
    'a custom key out of pattern' => ['{"channel":"c0","placement":"bar"}'],
    'an unknown placement' => ['{"channel":"zalo","placement":"header"}'],
    'a field that is not text' => ['{"channel":["zalo"],"placement":"bar"}'],
    'no placement' => ['{"channel":"zalo"}'],
    'not JSON' => ['zalo bar'],
    'an empty body' => [''],
    'an oversized body' => ['{"channel":"zalo","placement":"bar","pad":"'.str_repeat('x', 600).'"}'],
]);

it('does not count crawlers, link previews or a request with no browser name', function (string $agent): void {
    sendClickBeacon('a.test', '{"channel":"zalo","placement":"bar"}', ['User-Agent' => $agent])->assertNoContent();
    sendClickBeacon('a.test', '{"channel":"buy","placement":"bar"}', ['User-Agent' => $agent])->assertNoContent();

    expect(bufferedClicks(1))->toBe([]);
})->with([
    'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
    'a link preview' => ['facebookexternalhit/1.1'],
    'Lighthouse' => ['Mozilla/5.0 Chrome-Lighthouse'],
    'a script' => ['curl/8.5.0'],
    'nothing' => [''],
]);

it('stops counting an address past its limit on a site and answers 429, other sites still count it', function (): void {
    expect(RecordContactClick::MAX_ATTEMPTS)->toBe(60);   // as many as page views: carriers share addresses

    for ($i = 0; $i < RecordContactClick::MAX_ATTEMPTS; $i++) {
        sendClickBeacon('a.test', '{"channel":"zalo","placement":"bar"}')->assertNoContent();
    }

    sendClickBeacon('a.test', '{"channel":"zalo","placement":"bar"}')->assertStatus(429);
    sendClickBeacon('b.test', '{"channel":"zalo","placement":"bar"}')->assertNoContent();   // the key is the site and the address

    expect(bufferedClicks(1))->toBe(['zalo|bar' => RecordContactClick::MAX_ATTEMPTS])
        ->and(bufferedClicks(2))->toBe(['zalo|bar' => 1]);
});

it('reads and writes no table beyond resolving the host', function (): void {
    sendClickBeacon('a.test', '{"channel":"zalo","placement":"bar"}')->assertNoContent(); // warm up

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    sendClickBeacon('a.test', '{"channel":"messenger","placement":"floating"}')->assertNoContent();
    $one = count($queries);
    sendClickBeacon('a.test', '{"channel":"buy","placement":"bar"}')->assertNoContent();

    // The site context (set + check) and the open-site check — nothing else (after the warm-up the
    // host's site comes from the resolver's cache).
    $tables = [];
    foreach ($queries as $sql) {
        preg_match_all('/\bfrom\s+"?([a-z_]+)"?/i', $sql, $m);
        array_push($tables, ...$m[1]);
    }

    expect($one)->toBe(3)
        ->and($queries)->toHaveCount(6)   // a "Mua hàng" tap costs the same
        ->and(array_values(array_unique($tables)))->toBe(['sites'])
        ->and(implode("\n", $queries))->not->toContain('contact_click')
        ->and(bufferedClicks(1))->toBe(['buy|bar' => 1, 'messenger|floating' => 1, 'zalo|bar' => 1]);
});

it('answers only a POST', function (): void {
    $this->get('http://a.test/_click')->assertNotFound(); // a GET is an ordinary missing page
});
