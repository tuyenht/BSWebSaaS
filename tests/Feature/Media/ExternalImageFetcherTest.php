<?php

use App\Modules\Media\Actions\PresignMediaUpload;
use App\Modules\Media\Services\ExternalImageFetcher;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
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
        ->toThrow(RuntimeException::class);
});

it('caps the download at the upload limit (a lying Content-Length cannot balloon memory)', function (): void {
    Http::fake([PUBLIC_IMG => Http::response(str_repeat('x', 11 * 1024 * 1024), 200)]);

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(ExternalImageFetcher::class)->handle(PUBLIC_IMG, 'An Vinh')))
        ->toThrow(RuntimeException::class);
});

it('blocks non-http(s) schemes', function (): void {
    foreach (['file:///etc/passwd', 'ftp://93.184.216.34/x.jpg', 'gopher://93.184.216.34/'] as $url) {
        expect(fn () => app(Tenancy::class)->enter(1, fn () => app(ExternalImageFetcher::class)->handle($url, 'An Vinh')))
            ->toThrow(RuntimeException::class);
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
            ->toThrow(RuntimeException::class);
    }
    Http::assertNothingSent(); // guarded BEFORE any network call
});

it('re-validates a redirect hop and blocks one pointing at a private address', function (): void {
    Http::fake([
        'https://93.184.216.34/start.jpg' => Http::response('', 302, ['Location' => 'http://169.254.169.254/steal']),
        '*' => Http::response(externalPngBytes(), 200), // the metadata endpoint must NEVER be reached
    ]);

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(ExternalImageFetcher::class)->handle('https://93.184.216.34/start.jpg', 'An Vinh')))
        ->toThrow(RuntimeException::class);

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
        ->toThrow(RuntimeException::class);
    Http::assertNothingSent(); // rejected before any fetch
});

it('blocks an IPv4-mapped IPv6 literal smuggling a private address', function (): void {
    // ::ffff:169.254.169.254 is a valid IPv6 that a naive filter would pass — the embedded IPv4
    // is the cloud metadata endpoint and must be rejected.
    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(ExternalImageFetcher::class)->handle('https://[::ffff:169.254.169.254]/x.jpg', 'An Vinh')))
        ->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});
