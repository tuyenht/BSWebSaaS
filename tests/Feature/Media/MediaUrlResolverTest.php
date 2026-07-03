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
