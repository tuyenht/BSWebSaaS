<?php

use App\Modules\Media\Services\MediaUrlResolver;

/*
 * PR-2 (Media epic, ADR-17 L0). The resolver is the SINGLE switch point: every media URL is
 * derived from config('media.driver') + the storage key, never stored on a row. Proves the
 * cloudflare / static / imgproxy forms and that flipping ONE config value changes the output.
 */

function mediaUrl(string $key, ?int $width = null): string
{
    return app(MediaUrlResolver::class)->url($key, $width);
}

beforeEach(function (): void {
    config([
        'media.cloudflare.base' => 'https://files.test',
        'media.imgproxy.base' => 'https://img.test',
        'media.imgproxy.key' => bin2hex('secret-key'),
        'media.imgproxy.salt' => bin2hex('secret-salt'),
        'media.imgproxy.source_bucket' => 's3://baoson-media',
    ]);
});

it('cloudflare driver: raw URL with no width, ?w when a width is given', function (): void {
    config(['media.driver' => 'cloudflare', 'media.widths' => [320, 640, 960, 1280, 1600, 2048]]);

    expect(mediaUrl('an-vinh/content/logo-abc123.webp'))
        ->toBe('https://files.test/an-vinh/content/logo-abc123.webp');
    expect(mediaUrl('an-vinh/content/logo-abc123.webp', 640))
        ->toBe('https://files.test/an-vinh/content/logo-abc123.webp?w=640');
    // A non-whitelisted width snaps UP to the nearest the Worker accepts (800 → 960), so a
    // pre-resolved URL can never be one the Worker rejects with a 400.
    expect(mediaUrl('an-vinh/content/logo-abc123.webp', 800))
        ->toBe('https://files.test/an-vinh/content/logo-abc123.webp?w=960');
});

it('static driver: always the raw master, width is ignored', function (): void {
    config(['media.driver' => 'static']);

    expect(mediaUrl('an-vinh/content/logo-abc123.webp', 800))
        ->toBe('https://files.test/an-vinh/content/logo-abc123.webp');
});

it('imgproxy driver: an HMAC-signed URL carrying the processing path', function (): void {
    config(['media.driver' => 'imgproxy']);

    $url = mediaUrl('an-vinh/content/logo-abc123.webp', 640);

    expect($url)->toStartWith('https://img.test/')
        ->toContain('/rs:fit:640:0/plain/s3://baoson-media/an-vinh/content/logo-abc123.webp@webp');

    $sig = explode('/', str_replace('https://img.test/', '', $url))[0];
    expect($sig)->toMatch('/^[A-Za-z0-9_-]+$/')->not->toBe('');   // urlsafe base64, no padding
});

it('flips driver output by changing ONE config value (the L0 switch)', function (): void {
    $key = 'an-vinh/content/x-abc123.webp';

    config(['media.driver' => 'cloudflare']);
    $cf = mediaUrl($key, 640);
    config(['media.driver' => 'static']);
    $st = mediaUrl($key, 640);
    config(['media.driver' => 'imgproxy']);
    $ip = mediaUrl($key, 640);

    expect($cf)->toContain('?w=640')
        ->and($st)->not->toContain('?w=')
        ->and($ip)->toStartWith('https://img.test/');
});

it('normalises a leading slash in the key', function (): void {
    config(['media.driver' => 'cloudflare']);

    expect(mediaUrl('/an-vinh/content/logo-abc123.webp'))
        ->toBe('https://files.test/an-vinh/content/logo-abc123.webp');
});

/*
 * keyFromUrl — the inverse of url() for the cloudflare/static form. It exists ONLY to migrate
 * legacy content that stored a resolved URL (a frozen path) back to a media reference; new content
 * stores media_id and never a URL. It must round-trip the exact storage key and refuse anything it
 * would never have minted (a foreign URL, or the non-reversible imgproxy form) so a migration can
 * safely tell "our media" from "leave it alone".
 */
function mediaKeyFromUrl(string $url): ?string
{
    return app(MediaUrlResolver::class)->keyFromUrl($url);
}

it('keyFromUrl: round-trips a cloudflare URL back to its storage key (strips ?w)', function (): void {
    config(['media.driver' => 'cloudflare', 'media.widths' => [320, 640, 960, 1280, 1600, 2048]]);
    $key = 'an-vinh/content/logo-abc123.webp';

    expect(mediaKeyFromUrl(mediaUrl($key)))->toBe($key);
    expect(mediaKeyFromUrl(mediaUrl($key, 640)))->toBe($key);
    expect(mediaKeyFromUrl(mediaUrl($key, 800)))->toBe($key);   // the snapped ?w=960 is stripped
});

it('keyFromUrl: round-trips a static URL (nested key path preserved)', function (): void {
    config(['media.driver' => 'static']);
    $key = 'an-vinh/cover/hero-def456.webp';

    expect(mediaKeyFromUrl(mediaUrl($key, 800)))->toBe($key);
});

it('keyFromUrl: null for a URL this resolver would never mint', function (): void {
    config(['media.driver' => 'cloudflare']);
    // A foreign host (a pasted external image) — not our media, not resolvable to a master.
    expect(mediaKeyFromUrl('https://evil.example/an-vinh/content/logo-abc123.webp'))->toBeNull();
    // The imgproxy HMAC form lives under a different base → not reversible.
    config(['media.driver' => 'imgproxy']);
    expect(mediaKeyFromUrl(mediaUrl('an-vinh/content/logo-abc123.webp', 640)))->toBeNull();
});
