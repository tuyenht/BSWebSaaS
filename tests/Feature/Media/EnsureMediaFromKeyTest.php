<?php

use App\Modules\Media\Exceptions\MediaKeyRejectedException;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\EnsureMediaFromKey;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * Contract phase (content ↔ media by media_id): An Vinh's page-section images live in R2 as bare
 * keys with no Media row, so there is no media_id to reference. EnsureMediaFromKey materializes that
 * row (idempotently) from the key alone, so the seeder + the URL→media_id migration can point content
 * at a real media_id. Proves: metadata derived from the key, idempotent per site, and an existing
 * master is never overwritten.
 */

beforeEach(fn () => seedTenancy());

it('materializes a Media row from a storage key with metadata derived from the key', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $media = app(EnsureMediaFromKey::class)->handle('an-vinh/hero/hero-home-7ca90eafe212.jpg');

        expect($media->exists)->toBeTrue()
            ->and($media->site_id)->toBe(1)
            ->and($media->storage_key)->toBe('an-vinh/hero/hero-home-7ca90eafe212.jpg')
            ->and($media->collection)->toBe('hero')
            ->and($media->slug)->toBe('hero-home')                  // trailing -hash dropped
            ->and($media->mime)->toBe('image/jpeg')
            ->and($media->hash)->toHaveLength(64);                  // synthetic sha256 of the key
    });
});

it('is idempotent per site — the same key returns the same row, never a duplicate', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = app(EnsureMediaFromKey::class)->handle('an-vinh/brand/logo-e76d7cb1dfe4.png');
        $b = app(EnsureMediaFromKey::class)->handle('an-vinh/brand/logo-e76d7cb1dfe4.png');

        expect($b->id)->toBe($a->id)
            ->and($a->mime)->toBe('image/png')
            ->and(Media::query()->where('storage_key', 'an-vinh/brand/logo-e76d7cb1dfe4.png')->count())->toBe(1);
    });
});

it('never overwrites an existing master for the key', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $existing = Media::query()->create([
            'collection' => 'library', 'slug' => 'real', 'hash' => str_repeat('e', 64),
            'storage_key' => 'an-vinh/content/real-abc123abc123.webp', 'disk' => 'r2',
            'mime' => 'image/webp', 'size' => 4242,
        ]);

        $got = app(EnsureMediaFromKey::class)->handle('an-vinh/content/real-abc123abc123.webp');

        expect($got->id)->toBe($existing->id)->and($got->size)->toBe(4242);   // untouched
    });
});

it('reads a site-code key: library collection, 4-hex hash dropped from the slug', function (): void {
    $key = siteCode(1).'/anh-san-pham-ab12.png';

    $media = app(Tenancy::class)->enter(1, fn () => app(EnsureMediaFromKey::class)->handle($key));

    expect($media->storage_key)->toBe($key)
        ->and($media->collection)->toBe('library')
        ->and($media->slug)->toBe('anh-san-pham')
        ->and($media->mime)->toBe('image/png');
});

it('reads optional year and month folders in a site-code key', function (): void {
    $key = siteCode(1).'/2026/04/bang-gia-ab12c.webp';

    $media = app(Tenancy::class)->enter(1, fn () => app(EnsureMediaFromKey::class)->handle($key));

    expect($media->collection)->toBe('library')
        ->and($media->slug)->toBe('bang-gia')
        ->and($media->mime)->toBe('image/webp');
});

it('refuses a key under another site\'s code, a staging key, or a malformed site-code key', function (string $key): void {
    $key = str_replace(['{own}', '{other}'], [siteCode(1), siteCode(2)], $key);

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(EnsureMediaFromKey::class)->handle($key)))
        ->toThrow(MediaKeyRejectedException::class);

    expect(app(Tenancy::class)->enter(1, fn () => Media::query()->count()))->toBe(0);
})->with([
    'other site' => '{other}/anh-ab12.png',
    'staging' => '_incoming/a/01abc.png',
    'bad month' => '{own}/2026/13/anh-ab12.png',
    'no hash' => '{own}/anh.png',
    'unknown type' => '{own}/anh-ab12.exe',
]);
