<?php

use App\Modules\Shared\Http\SafeHttp;
use App\Modules\Shared\Http\SafeHttpException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The shared outbound guard: http(s) only, every resolved address public (IPv6 with an IPv4 inside
 * included), the request pinned to the checked IP, each redirect hop re-checked, and — when asked
 * — redirects kept on the same host. DNS is answered by the test (the resolver), never for real.
 */

function guardResolving(string ...$ips): SafeHttp
{
    return new SafeHttp(static fn (string $host): array => array_values($ips));
}

function guardRefusal(Closure $call): string
{
    try {
        $call();
    } catch (SafeHttpException $e) {
        return $e->reason;
    }

    return 'no refusal';
}

beforeEach(fn () => Http::preventStrayRequests());

it('refuses a host that resolves to a private or reserved address', function (string $ip): void {
    expect(guardRefusal(fn () => guardResolving($ip)->get('https://vi-du-đ.test/')))->toBe('private_address');
    Http::assertNothingSent();
})->with(['10.0.0.5', '127.0.0.1', '169.254.169.254', '192.168.1.1', '100.64.0.1', '::1', 'fd00::1', 'fe80::1']);

it('refuses a host when even one of its addresses is private', function (): void {
    expect(guardRefusal(fn () => guardResolving('93.184.216.34', '10.0.0.5')->get('https://vi-du.test/')))->toBe('private_address');
});

it('refuses an IPv6 address that carries a private IPv4 inside', function (string $ip): void {
    expect(guardRefusal(fn () => guardResolving($ip)->get('https://vi-du.test/')))->toBe('private_address')
        ->and(guardRefusal(fn () => guardResolving()->get("https://[{$ip}]/")))->toBe('private_address');
})->with(['::ffff:169.254.169.254', '::ffff:10.0.0.1', '::127.0.0.1', '64:ff9b::a00:1']);

it('refuses a redirect to a private address, checked again at the new hop', function (): void {
    Http::fake([
        'https://93.184.216.34/start' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
        '*' => Http::response('bí mật', 200),
    ]);

    expect(guardRefusal(fn () => guardResolving()->get('https://93.184.216.34/start')))->toBe('private_address');
    Http::assertSentCount(1);
});

it('sends the caller’s headers to an address that resolves to a public IP', function (): void {
    Http::fake(['https://vi-du.test/' => Http::response('ổn', 200)]);

    $answer = guardResolving('93.184.216.34')->get('https://vi-du.test/', ['User-Agent' => 'Thử nghiệm']);

    expect($answer['response']->status())->toBe(200);
    Http::assertSent(fn (Request $request): bool => $request->header('User-Agent') === ['Thử nghiệm']);
});

it('keeps redirects on the same host when asked, and names where the redirect wanted to go', function (): void {
    Http::fake([
        'http://vi-du.test/' => Http::response('', 301, ['Location' => 'https://vi-du.test/']),
        'https://vi-du.test/' => Http::response('', 301, ['Location' => 'https://khac.test/']),
        '*' => Http::response('', 200),
    ]);

    try {
        guardResolving('93.184.216.34')->get('http://vi-du.test/', sameHost: true);
        $this->fail('expected a refusal');
    } catch (SafeHttpException $e) {
        expect($e->reason)->toBe('redirect_off_host')->and($e->host)->toBe('khac.test');
    }
    Http::assertSentCount(2);
});

it('follows at most three redirects', function (): void {
    Http::fake(['*' => Http::response('', 302, ['Location' => '/lại-nữa'])]);

    expect(guardRefusal(fn () => guardResolving('93.184.216.34')->get('https://vi-du.test/')))->toBe('too_many_redirects');
    Http::assertSentCount(4);
});

it('refuses other schemes and a host that does not resolve', function (): void {
    expect(guardRefusal(fn () => guardResolving('93.184.216.34')->get('file:///etc/passwd')))->toBe('url_invalid')
        ->and(guardRefusal(fn () => guardResolving()->get('https://khong-co.test/')))->toBe('host_unresolvable');
});

it('reads a body up to a cap and says whether more was left', function (): void {
    Http::fake(['*' => Http::response(str_repeat('ă', 600), 200)]);

    $response = guardResolving('93.184.216.34')->get('https://vi-du.test/')['response'];
    [$bytes, $more] = SafeHttp::readUpTo($response, 1000);

    expect(strlen($bytes))->toBe(1000)->and($more)->toBeTrue();
});
