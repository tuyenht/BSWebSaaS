<?php

use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Exceptions\MediaRejectedException;
use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * UploadMedia (Increment ②) — secure ingest (§2.5 / ADR-17). The real MIME is sniffed from magic
 * bytes (never the client extension), content is SHA-256 deduped within (site, collection, hash),
 * and the single master is stored to R2 under the opaque content-hash key. site_id is stamped by
 * BelongsToSite. A spoofed extension (PHP in a .jpg) and an over-size file are rejected.
 */

beforeEach(function (): void {
    seedTenancy();
    Storage::fake('r2');
});

/** A real 1×1 PNG (deterministic content → deterministic hash, for the dedup test). */
function pngBytes(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        true,
    );
}

it('stores a valid image as a single R2 master + a media row stamped to the site', function (): void {
    $media = app(Tenancy::class)->enter(1, fn () => app(UploadMedia::class)->handle(
        UploadedFile::fake()->createWithContent('Ảnh Sản Phẩm.png', pngBytes()),
        'An Vinh',
    ));

    expect($media->site_id)->toBe(1)
        ->and($media->mime)->toBe('image/png')
        ->and($media->disk)->toBe('r2')
        ->and(strlen($media->hash))->toBe(64)                       // full SHA-256
        ->and($media->storage_key)->toStartWith('an-vinh/library/') // {tenant=slug(name)}/{collection}/
        ->and($media->width)->toBe(1)->and($media->height)->toBe(1);

    Storage::disk('r2')->assertExists($media->storage_key);
});

it('dedups identical content within a site (one master, one R2 object)', function (): void {
    [$first, $second, $count] = app(Tenancy::class)->enter(1, function (): array {
        $a = app(UploadMedia::class)->handle(UploadedFile::fake()->createWithContent('a.png', pngBytes()), 'An Vinh');
        $b = app(UploadMedia::class)->handle(UploadedFile::fake()->createWithContent('b.png', pngBytes()), 'An Vinh');

        return [$a->id, $b->id, Media::query()->count()];
    });

    expect($second)->toBe($first)->and($count)->toBe(1);
});

it('restores a trashed master and heals a missing object on re-upload by someone who may restore', function (): void {
    // Same two invariants as the presigned path: a soft-deleted master must not turn the
    // UNIQUE(site,collection,hash) insert into a dead-end, and dedup must put the bytes back
    // when the row's object is gone instead of pinning the hash to a dead key.
    [$firstId, $secondId, $stillTrashed, $key] = app(Tenancy::class)->enter(1, function (): array {
        $a = app(UploadMedia::class)->handle(UploadedFile::fake()->createWithContent('a.png', pngBytes()), 'An Vinh');
        $a->delete(); // Thùng rác
        Storage::disk('r2')->delete($a->storage_key); // and the object is gone too

        $b = app(UploadMedia::class)->handle(UploadedFile::fake()->createWithContent('b.png', pngBytes()), 'An Vinh', mayRestore: true);

        return [$a->id, $b->id, $b->trashed(), $a->storage_key];
    });

    expect($secondId)->toBe($firstId)->and($stillTrashed)->toBeFalse();
    Storage::disk('r2')->assertExists($key);
});

it('rejects a spoofed extension by sniffing magic bytes (PHP in a .jpg)', function (): void {
    // A real temp file so the action's finfo-based getMimeType() runs (the fake guesses from the
    // extension). The client mime lies ('image/jpeg'); finfo sees PHP → rejected.
    $path = (string) tempnam(sys_get_temp_dir(), 'evil');
    file_put_contents($path, '<?php echo "pwned"; ?>');
    $spoofed = new UploadedFile($path, 'evil.jpg', 'image/jpeg', null, true);

    app(Tenancy::class)->enter(1, fn () => app(UploadMedia::class)->handle($spoofed, 'An Vinh'));
})->throws(MediaRejectedException::class, 'unsupported_type');

it('rejects a file over the size limit', function (): void {
    app(Tenancy::class)->enter(1, fn () => app(UploadMedia::class)->handle(
        UploadedFile::fake()->create('huge.jpg', 11 * 1024), // 11MB > 10MB
        'An Vinh',
    ));
})->throws(MediaRejectedException::class, 'too_large');

it('throws on an R2 write failure (no orphan media row)', function (): void {
    // Laravel's filesystem returns false (does not throw) on a failed write; the action must turn
    // that into a friendly error BEFORE inserting, or a master would point at a missing object.
    Storage::shouldReceive('disk')->with('r2')->andReturnSelf();
    Storage::shouldReceive('put')->once()->andReturn(false);

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(UploadMedia::class)->handle(
        UploadedFile::fake()->createWithContent('a.png', pngBytes()),
        'An Vinh',
    )))->toThrow(MediaRejectedException::class, 'storage_failed');

    expect(app(Tenancy::class)->enter(1, fn () => Media::query()->count()))->toBe(0);
});

/** Parse a PHP ini shorthand size ("12M", "256K", "1G") to bytes. */
function uploadIniBytes(string $key): int
{
    $value = ini_get($key);
    if (! is_string($value) || $value === '') {
        return 0;
    }

    $number = (int) $value;

    return match (strtolower(substr($value, -1))) {
        'g' => $number * 1024 ** 3,
        'm' => $number * 1024 ** 2,
        'k' => $number * 1024,
        default => $number,
    };
}

it('keeps the PHP upload ceiling at or above the media size cap (browser-upload guard)', function (): void {
    // The real browser upload is bounded by PHP's SAPI limits: a body over upload_max_filesize /
    // post_max_size is truncated BEFORE Livewire ever sees it, surfacing as a misleading "no file"
    // error (the prod incident this guards). Keep both at/above UploadMedia::MAX_KB so the friendly
    // app-level validation — not a silent SAPI truncation — is the gate. Set in
    // docker/frankenphp/conf.d/app.ini; this fails on the stock 2M default.
    $cap = UploadMedia::MAX_KB * 1024;

    expect(uploadIniBytes('upload_max_filesize'))->toBeGreaterThanOrEqual($cap)
        ->and(uploadIniBytes('post_max_size'))->toBeGreaterThan($cap);
});

it('refuses to take a trashed master out of the Trash for an uploader who may not restore', function (): void {
    // Uploading needs media.upload only; restoring from the Trash is a media.delete right. A
    // re-upload of the same bytes must not become a back door around that.
    [$reason, $stillTrashed] = app(Tenancy::class)->enter(1, function (): array {
        $a = app(UploadMedia::class)->handle(UploadedFile::fake()->createWithContent('a.png', pngBytes()), 'An Vinh');
        $a->delete(); // Thùng rác

        try {
            app(UploadMedia::class)->handle(UploadedFile::fake()->createWithContent('b.png', pngBytes()), 'An Vinh');
            $reason = null;
        } catch (MediaRejectedException $e) {
            $reason = $e->reason;
        }

        return [$reason, Media::query()->withTrashed()->whereKey($a->id)->first()?->trashed()];
    });

    expect($reason)->toBe('in_trash')->and($stillTrashed)->toBeTrue();
});
