<?php

use App\Modules\Media\Actions\PresignMediaUpload;
use App\Modules\Media\Exceptions\MediaRejectedException;
use App\Modules\Media\Services\ExternalImageFetcher;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * ExternalImageFetcher — the SSRF choke point for the picker's "Ảnh từ URL" source. The admin
 * types an arbitrary URL and the APP fetches it, so this proves the guard holds: http(s) only,
 * no private/reserved IPs (literal OR via a redirect hop), body capped, real type sniffed from
 * magic bytes. A safe fetch stages the bytes on `_incoming/` with the same ownership record the
 * presigned flow uses, so RegisterMediaUpload can finalize an import through the hardened path.
 */

beforeEach(function (): void {
    seedTenancy();
    Storage::fake('r2');
    Http::preventStrayRequests();
});

function externalPngBytes(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
}

/** A public IP literal keeps the SSRF check off DNS in tests (example.com's address). */
const PUBLIC_IMG = 'https://93.184.216.34/anh-san-pham.jpg';

it('fetches a valid public image and stages it with a site-ownership record', function (): void {
    Http::fake([PUBLIC_IMG => Http::response(externalPngBytes(), 200, ['Content-Type' => 'image/png'])]);

    $result = app(Tenancy::class)->enter(1, fn (): array => app(ExternalImageFetcher::class)->handle(PUBLIC_IMG, 'An Vinh'));

    expect($result['mime'])->toBe('image/png')
        ->and($result['tempKey'])->toStartWith('_incoming/an-vinh/url-')
        ->and($result['filename'])->toBe('anh-san-pham.jpg')
        ->and($result['size'])->toBe(strlen(externalPngBytes()));
    Storage::disk('r2')->assertExists($result['tempKey']);
    // Register can later finalize it — the ownership cache is stamped to THIS site (register
    // reads it as numeric, so string|int both satisfy the cross-tenant guard).
    expect((int) Cache::get(PresignMediaUpload::ownershipKey($result['tempKey'])))->toBe(1);
});

it('rejects a non-image payload by sniffing magic bytes (HTML pretending to be a jpg)', function (): void {
    Http::fake([PUBLIC_IMG => Http::response('<!doctype html><title>not an image</title>', 200)]);

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(ExternalImageFetcher::class)->handle(PUBLIC_IMG, 'An Vinh')))
        ->toThrow(MediaRejectedException::class, 'not_an_image');
});

it('caps the download at the upload limit (a lying Content-Length cannot balloon memory)', function (): void {
    Http::fake([PUBLIC_IMG => Http::response(str_repeat('x', 11 * 1024 * 1024), 200)]);

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(ExternalImageFetcher::class)->handle(PUBLIC_IMG, 'An Vinh')))
        ->toThrow(MediaRejectedException::class, 'image_too_large');
});

it('blocks non-http(s) schemes', function (): void {
    foreach (['file:///etc/passwd', 'ftp://93.184.216.34/x.jpg', 'gopher://93.184.216.34/'] as $url) {
        expect(fn () => app(Tenancy::class)->enter(1, fn () => app(ExternalImageFetcher::class)->handle($url, 'An Vinh')))
            ->toThrow(MediaRejectedException::class, 'url_invalid');
    }
    Http::assertNothingSent();
});

it('blocks private, loopback and link-local IP literals (SSRF)', function (): void {
    $targets = [
        'http://127.0.0.1/x.jpg',        // loopback
        'http://10.0.0.5/x.jpg',         // private A
        'http://192.168.1.1/x.jpg',      // private C
        'http://169.254.169.254/x.jpg',  // cloud metadata
        'https://[::1]/x.jpg',           // IPv6 loopback
    ];
    foreach ($targets as $url) {
        expect(fn () => app(Tenancy::class)->enter(1, fn () => app(ExternalImageFetcher::class)->handle($url, 'An Vinh')))
            ->toThrow(MediaRejectedException::class, 'private_address');
    }
    Http::assertNothingSent(); // guarded BEFORE any network call
});

it('re-validates a redirect hop and blocks one pointing at a private address', function (): void {
    Http::fake([
        'https://93.184.216.34/start.jpg' => Http::response('', 302, ['Location' => 'http://169.254.169.254/steal']),
        '*' => Http::response(externalPngBytes(), 200), // the metadata endpoint must NEVER be reached
    ]);

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(ExternalImageFetcher::class)->handle('https://93.184.216.34/start.jpg', 'An Vinh')))
        ->toThrow(MediaRejectedException::class, 'private_address');

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '169.254.169.254'));
});

it('follows a redirect to another public image and imports it', function (): void {
    Http::fake([
        'https://93.184.216.34/a.jpg' => Http::response('', 301, ['Location' => 'https://93.184.216.34/b.jpg']),
        'https://93.184.216.34/b.jpg' => Http::response(externalPngBytes(), 200),
    ]);

    $result = app(Tenancy::class)->enter(1, fn (): array => app(ExternalImageFetcher::class)->handle('https://93.184.216.34/a.jpg', 'An Vinh'));

    expect($result['url'])->toBe('https://93.184.216.34/b.jpg')
        ->and($result['mime'])->toBe('image/png');
});

it('blocks a hostname that resolves to a private address (DNS-rebind guard)', function (): void {
    // A hostname passing a naive check but resolving to a private IP — the guard validates the
    // resolved answer, not just the literal, and pins the request to it (no re-resolve window).
    $fetcher = new class(app(CurrentSite::class)) extends ExternalImageFetcher
    {
        #[Override]
        protected function resolveHostIps(string $host): array
        {
            return ['10.0.0.5']; // attacker-controlled DNS answer
        }
    };

    expect(fn () => app(Tenancy::class)->enter(1, fn () => $fetcher->handle('https://images.attacker.test/x.jpg', 'An Vinh')))
        ->toThrow(MediaRejectedException::class, 'private_address');
    Http::assertNothingSent(); // rejected before any fetch
});

it('blocks an IPv4-mapped IPv6 literal smuggling a private address', function (): void {
    // ::ffff:169.254.169.254 is a valid IPv6 that a naive filter would pass — the embedded IPv4
    // is the cloud metadata endpoint and must be rejected.
    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(ExternalImageFetcher::class)->handle('https://[::ffff:169.254.169.254]/x.jpg', 'An Vinh')))
        ->toThrow(MediaRejectedException::class, 'private_address');
    Http::assertNothingSent();
});

it('blocks addresses that are neither private nor public, and IPv6 forms that carry one', function (string $url): void {
    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(ExternalImageFetcher::class)->handle($url, 'An Vinh')))
        ->toThrow(MediaRejectedException::class, 'private_address');
    Http::assertNothingSent();
})->with([
    'carrier-grade NAT' => 'http://100.64.0.1/x.jpg',
    'cloud metadata in the shared range' => 'http://100.100.100.200/x.jpg',
    'protocol assignments' => 'http://192.0.0.8/x.jpg',
    'benchmarking' => 'http://198.18.0.1/x.jpg',
    'documentation' => 'http://203.0.113.9/x.jpg',
    'NAT64 carrying loopback' => 'http://[64:ff9b::7f00:1]/x.jpg',
    'NAT64 carrying cloud metadata' => 'http://[64:ff9b::a9fe:a9fe]/x.jpg',
    '6to4 carrying loopback' => 'http://[2002:7f00:1::]/x.jpg',
]);

it('still fetches from a public IPv6 literal', function (): void {
    $url = 'https://[2606:4700:4700::1111]/anh.png';
    Http::fake([$url => Http::response(externalPngBytes(), 200)]);

    $result = app(Tenancy::class)->enter(1, fn (): array => app(ExternalImageFetcher::class)->handle($url, 'An Vinh'));

    expect($result['mime'])->toBe('image/png');
});

it('refuses a host cURL would read as an IPv4 number, even when DNS vouches for it', function (string $host): void {
    // cURL connects to the number itself (0x7f.0.0.1 = loopback) and ignores the pinned answer,
    // so a resolver answering for such a name — a wildcard search domain — must not get a say.
    $fetcher = new class(app(CurrentSite::class)) extends ExternalImageFetcher
    {
        #[Override]
        protected function resolveHostIps(string $host): array
        {
            return ['93.184.216.34']; // a public answer: the case the IP check alone would pass
        }
    };

    expect(fn () => app(Tenancy::class)->enter(1, fn () => $fetcher->handle("http://{$host}/x.jpg", 'An Vinh')))
        ->toThrow(MediaRejectedException::class, 'url_invalid');
    Http::assertNothingSent();
})->with(['0x7f.0.0.1', '0X7F.0.0.1', '2130706433', '127.1', '0177.0.0.1', '0x7f000001', '127.0.0.1.', '169.254.169.254.']);

it('still fetches from an ordinary hostname that has digits in it', function (): void {
    $url = 'https://cdn2.images123.example.com/anh.png';
    Http::fake([$url => Http::response(externalPngBytes(), 200)]);
    $fetcher = new class(app(CurrentSite::class)) extends ExternalImageFetcher
    {
        #[Override]
        protected function resolveHostIps(string $host): array
        {
            return ['93.184.216.34'];
        }
    };

    $result = app(Tenancy::class)->enter(1, fn (): array => $fetcher->handle($url, 'An Vinh'));

    expect($result['mime'])->toBe('image/png');
});

it('answers a link that cannot be reached as the link\'s problem, not a server error', function (): void {
    Http::fake([PUBLIC_IMG => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(ExternalImageFetcher::class)->handle(PUBLIC_IMG, 'An Vinh')))
        ->toThrow(MediaRejectedException::class, 'fetch_unreachable');
});
