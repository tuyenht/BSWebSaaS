<?php

use App\Modules\Media\Actions\PresignMediaUpload;
use App\Modules\Media\Actions\RegisterMediaUpload;
use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/*
 * RegisterMediaUpload (DAM P1) — the REAL gate of the presigned direct-to-R2 flow. After the browser
 * PUTs bytes to a temp key, register verifies the key belongs to THIS site (cross-tenant guard),
 * sniffs magic bytes, hashes + dedups, and R2-internal-copies the object to the final content-hash
 * key. A foreign / spoofed upload is rejected and its temp object deleted — never a usable row.
 */

beforeEach(function (): void {
    seedTenancy();
    Storage::fake('r2');
});

/** A real 1×1 PNG (finfo sniffs image/png; no GD needed). */
function pngContent(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
}

/** Simulate a completed presigned PUT: an object at a temp key + its site-ownership record. */
function stageUpload(int $site, string $tempKey, string $contents): void
{
    Storage::disk('r2')->put($tempKey, $contents);
    Cache::put(PresignMediaUpload::ownershipKey($tempKey), $site, now()->addMinutes(5));
}

it('finalizes a valid upload: copies temp→content-hash key + stamps the site', function (): void {
    $tempKey = '_incoming/a/01abc.png';
    stageUpload(1, $tempKey, pngContent());

    $media = app(Tenancy::class)->enter(1, fn () => app(RegisterMediaUpload::class)->handle($tempKey, 'Bảng Giá.png'));

    expect($media->site_id)->toBe(1)
        ->and($media->mime)->toBe('image/png')
        ->and(strlen($media->hash))->toBe(64)
        ->and($media->storage_key)->toStartWith('a/library/')
        ->and($media->storage_key)->not->toContain('_incoming');
    Storage::disk('r2')->assertExists($media->storage_key);
    Storage::disk('r2')->assertMissing($tempKey);
});

it('rejects a temp key issued to ANOTHER site (cross-tenant guard) + deletes the object', function (): void {
    $tempKey = '_incoming/b/01xyz.png';
    stageUpload(2, $tempKey, pngContent()); // owned by site 2

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(RegisterMediaUpload::class)->handle($tempKey, 'x.png')))
        ->toThrow(RuntimeException::class);

    expect(app(Tenancy::class)->enter(1, fn () => Media::query()->count()))->toBe(0);
    Storage::disk('r2')->assertMissing($tempKey);
});

it('rejects a spoofed file by sniffing magic bytes (PHP in a .png) + writes no row', function (): void {
    $tempKey = '_incoming/a/01evil.png';
    stageUpload(1, $tempKey, '<?php echo "x"; ?>');

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(RegisterMediaUpload::class)->handle($tempKey, 'evil.png')))
        ->toThrow(RuntimeException::class);

    expect(app(Tenancy::class)->enter(1, fn () => Media::query()->count()))->toBe(0);
});

it('dedups identical content within a site (one master, temp dropped)', function (): void {
    [$firstId, $secondId, $count] = app(Tenancy::class)->enter(1, function (): array {
        stageUpload(1, '_incoming/a/01one.png', pngContent());
        $first = app(RegisterMediaUpload::class)->handle('_incoming/a/01one.png', 'one.png');

        stageUpload(1, '_incoming/a/01two.png', pngContent());
        $second = app(RegisterMediaUpload::class)->handle('_incoming/a/01two.png', 'two.png');

        return [$first->id, $second->id, Media::query()->count()];
    });

    expect($secondId)->toBe($firstId)->and($count)->toBe(1);
    Storage::disk('r2')->assertMissing('_incoming/a/01two.png');
});

it('omits the hash suffix when url_hash_length is 0 (clean {slug}.{ext})', function (): void {
    config(['media.url_hash_length' => 0]);
    $tempKey = '_incoming/a/01zero.png';
    stageUpload(1, $tempKey, pngContent());

    $media = app(Tenancy::class)->enter(1, fn () => app(RegisterMediaUpload::class)->handle($tempKey, 'about-doi-ngu.png'));

    expect($media->storage_key)->toBe('a/library/about-doi-ngu.png'); // no -hash suffix
});

it('uses an 8-hex suffix when url_hash_length is 8', function (): void {
    config(['media.url_hash_length' => 8]);
    $tempKey = '_incoming/a/01eight.png';
    stageUpload(1, $tempKey, pngContent());

    $media = app(Tenancy::class)->enter(1, fn () => app(RegisterMediaUpload::class)->handle($tempKey, 'anh.png'));

    expect($media->storage_key)->toMatch('#^a/library/anh-[0-9a-f]{8}\.png$#');
});

it('omits the tenant prefix in standalone (non-SaaS) mode', function (): void {
    config(['media.tenant_prefix' => false]);
    $tempKey = '_incoming/a/01solo.png';
    stageUpload(1, $tempKey, pngContent());

    $media = app(Tenancy::class)->enter(1, fn () => app(RegisterMediaUpload::class)->handle($tempKey, 'logo.png'));

    expect($media->storage_key)->toStartWith('library/logo')
        ->and($media->storage_key)->not->toStartWith('a/');
});

it('rejects an oversize object via HEAD before loading it (OOM guard)', function (): void {
    $tempKey = '_incoming/a/01big.png';
    stageUpload(1, $tempKey, str_repeat('x', UploadMedia::MAX_KB * 1024 + 1));

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(RegisterMediaUpload::class)->handle($tempKey, 'big.png')))
        ->toThrow(RuntimeException::class);

    expect(app(Tenancy::class)->enter(1, fn () => Media::query()->count()))->toBe(0);
    Storage::disk('r2')->assertMissing($tempKey);
});

it('throws when the temp key has no ownership record (expired/forged)', function (): void {
    $tempKey = '_incoming/a/01gone.png';
    Storage::disk('r2')->put($tempKey, pngContent()); // object but NO ownership cache

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(RegisterMediaUpload::class)->handle($tempKey, 'x.png')))
        ->toThrow(RuntimeException::class);
});

it('writes NO row when the storage copy fails (orphan guard)', function (): void {
    $tempKey = '_incoming/a/01fail.png';
    Cache::put(PresignMediaUpload::ownershipKey($tempKey), 1, now()->addMinutes(5));

    // copy() returns bool on the r2 disk ('throw' => false) — a silent false here used to
    // persist a row whose object never existed (renders as a broken thumb in the picker).
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->with($tempKey)->andReturnTrue();
    $disk->shouldReceive('size')->with($tempKey)->andReturn(strlen(pngContent()));
    $disk->shouldReceive('get')->with($tempKey)->andReturn(pngContent());
    $disk->shouldReceive('copy')->once()->andReturnFalse();
    $disk->shouldReceive('delete')->never(); // the temp object must survive for a retry
    Storage::shouldReceive('disk')->with('r2')->andReturn($disk);

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(RegisterMediaUpload::class)->handle($tempKey, 'fail.png')))
        ->toThrow(RuntimeException::class, 'Không thể lưu tệp vào kho');

    expect(app(Tenancy::class)->enter(1, fn () => Media::query()->count()))->toBe(0);
});

it('self-heals a master whose object went missing when the same bytes are re-uploaded', function (): void {
    [$firstId, $secondId, $key] = app(Tenancy::class)->enter(1, function (): array {
        stageUpload(1, '_incoming/a/01heal1.png', pngContent());
        $first = app(RegisterMediaUpload::class)->handle('_incoming/a/01heal1.png', 'heal.png');

        // Simulate the interrupted-finalize orphan: the row stays, its object vanishes.
        Storage::disk('r2')->delete($first->storage_key);

        stageUpload(1, '_incoming/a/01heal2.png', pngContent());
        $second = app(RegisterMediaUpload::class)->handle('_incoming/a/01heal2.png', 'heal-again.png');

        return [$first->id, $second->id, $first->storage_key];
    });

    // Dedup still returns the one master — now with its object restored under the SAME key.
    expect($secondId)->toBe($firstId);
    Storage::disk('r2')->assertExists($key);
    Storage::disk('r2')->assertMissing('_incoming/a/01heal2.png');
});
