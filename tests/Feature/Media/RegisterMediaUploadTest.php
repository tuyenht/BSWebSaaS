<?php

use App\Modules\Media\Actions\PresignMediaUpload;
use App\Modules\Media\Actions\RegisterMediaUpload;
use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Exceptions\MediaRejectedException;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCopyFile;

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

it('rejects a temp key issued to ANOTHER site without touching its object or its claim', function (): void {
    $tempKey = '_incoming/b/01xyz.png';
    stageUpload(2, $tempKey, pngContent()); // owned by site 2

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(RegisterMediaUpload::class)->handle($tempKey, 'x.png')))
        ->toThrow(MediaRejectedException::class, 'upload_session_invalid');

    expect(app(Tenancy::class)->enter(1, fn () => Media::query()->count()))->toBe(0);
    Storage::disk('r2')->assertExists($tempKey);

    // The real owner can still finish its own upload after the replay.
    $media = app(Tenancy::class)->enter(2, fn () => app(RegisterMediaUpload::class)->handle($tempKey, 'x.png'));
    expect($media->site_id)->toBe(2);
});

it('never deletes a stored file named as the temp key of an unclaimed upload', function (): void {
    $foreign = 'b/library/logo-ab12.png'; // another site's live library file
    Storage::disk('r2')->put($foreign, pngContent());

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(RegisterMediaUpload::class)->handle($foreign, 'x.png')))
        ->toThrow(MediaRejectedException::class, 'upload_session_invalid');

    Storage::disk('r2')->assertExists($foreign);
});

it('rejects a spoofed file by sniffing magic bytes (PHP in a .png) + writes no row', function (): void {
    $tempKey = '_incoming/a/01evil.png';
    stageUpload(1, $tempKey, '<?php echo "x"; ?>');

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(RegisterMediaUpload::class)->handle($tempKey, 'evil.png')))
        ->toThrow(MediaRejectedException::class, 'unsupported_type');

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
        ->toThrow(MediaRejectedException::class, 'too_large');

    expect(app(Tenancy::class)->enter(1, fn () => Media::query()->count()))->toBe(0);
    Storage::disk('r2')->assertMissing($tempKey);
});

it('throws when the temp key has no ownership record (expired/forged)', function (): void {
    $tempKey = '_incoming/a/01gone.png';
    Storage::disk('r2')->put($tempKey, pngContent()); // object but NO ownership cache

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(RegisterMediaUpload::class)->handle($tempKey, 'x.png')))
        ->toThrow(MediaRejectedException::class, 'upload_session_invalid');
});

it('writes NO row when the storage copy fails (orphan guard)', function (): void {
    $fake = Storage::fake('r2');
    $tempKey = '_incoming/a/01fail.png';
    stageUpload(1, $tempKey, pngContent());

    // A failed copy used to persist a row whose object never existed (a broken thumb in the picker).
    $driver = Mockery::mock(League\Flysystem\Filesystem::class, [$fake->getAdapter()])->makePartial();
    $driver->shouldReceive('copy')->once()->andThrow(UnableToCopyFile::fromLocationTo($tempKey, 'a/library/fail.png'));
    Storage::set('r2', new FilesystemAdapter($driver, $fake->getAdapter(), ['throw' => false]));

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(RegisterMediaUpload::class)->handle($tempKey, 'fail.png')))
        ->toThrow(MediaRejectedException::class, 'storage_failed');

    $fake->assertExists($tempKey); // the temp object must survive for a retry
    expect(app(Tenancy::class)->enter(1, fn () => Media::query()->count()))->toBe(0);
});

it('restores a trashed master when the same bytes are re-uploaded (no unique-violation dead-end)', function (): void {
    // The UNIQUE(site,collection,hash) index doesn't know about soft-deletes: without
    // withTrashed in the dedup, this second register is a guaranteed constraint violation —
    // an unrecoverable 500 for the user, since the media Trash has no restore UI yet.
    [$firstId, $secondId, $stillTrashed] = app(Tenancy::class)->enter(1, function (): array {
        stageUpload(1, '_incoming/a/01bin1.png', pngContent());
        $first = app(RegisterMediaUpload::class)->handle('_incoming/a/01bin1.png', 'bin.png');
        $first->delete(); // Thùng rác

        stageUpload(1, '_incoming/a/01bin2.png', pngContent());
        $second = app(RegisterMediaUpload::class)->handle('_incoming/a/01bin2.png', 'bin-again.png');

        return [$first->id, $second->id, $second->trashed()];
    });

    expect($secondId)->toBe($firstId)->and($stillTrashed)->toBeFalse();
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

it('stores the master with headers from the sniffed type, never the ones the uploader set', function (): void {
    $fake = Storage::fake('r2');
    $tempKey = '_incoming/a/01html.png';
    stageUpload(1, $tempKey, pngContent());

    // Record what the final copy asks the storage driver for, then let the real copy run.
    $seen = [];
    $driver = Mockery::mock(League\Flysystem\Filesystem::class, [$fake->getAdapter()])->makePartial();
    $driver->shouldReceive('copy')->andReturnUsing(function (string $from, string $to, array $config = []) use (&$seen, $fake): void {
        $seen[] = $config;
        $fake->getDriver()->copy($from, $to, $config);
    });
    Storage::set('r2', new FilesystemAdapter($driver, $fake->getAdapter(), ['throw' => false]));

    $media = app(Tenancy::class)->enter(1, fn () => app(RegisterMediaUpload::class)->handle($tempKey, 'trang.png'));

    expect($seen)->toHaveCount(1)
        ->and($seen[0])->toMatchArray(['MetadataDirective' => 'REPLACE', 'ContentType' => 'image/png'])
        ->and($media->storage_key)->toEndWith('.png');
});

it('marks a stored pdf as a download', function (): void {
    expect(MediaKey::objectOptions('application/pdf'))
        ->toMatchArray(['ContentType' => 'application/pdf', 'ContentDisposition' => 'attachment'])
        ->and(MediaKey::objectOptions('image/webp'))->not->toHaveKey('ContentDisposition');
});
