<?php

use App\Modules\Media\Actions\PresignMediaUpload;
use App\Modules\Media\Actions\RegisterMediaUpload;
use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Exceptions\MediaKeyRejectedException;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteFeature;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * New media keys are `{site code}/{slug}-{hashN}.{ext}`, or `{site code}/{yyyy}/{mm}/{slug}-{hashN}.{ext}`
 * when the platform turned year/month folders on for the site. The code is the site's permanent,
 * unique prefix, so two websites with the same name can no longer write over or delete each other's
 * files (the old prefix was the slug of the site name). The collection stays a column, not a path part.
 */

beforeEach(function (): void {
    seedTenancy();
    Storage::fake('r2');
});

/** A real 1×1 PNG; $variant changes the bytes (and so the hash) without breaking the sniffed type. */
function codeKeyPng(string $variant = ''): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true).$variant;
}

function uploadAs(int $site, string $filename, string $bytes): Media
{
    return app(Tenancy::class)->enter($site, fn () => app(UploadMedia::class)->handle(
        UploadedFile::fake()->createWithContent($filename, $bytes),
    ));
}

it('keys a direct upload under the site code, with no collection segment', function (): void {
    $media = uploadAs(1, 'Ảnh Sản Phẩm.png', codeKeyPng());
    $hash4 = substr(hash('sha256', codeKeyPng()), 0, 4);

    expect($media->storage_key)->toBe(siteCode(1)."/anh-san-pham-{$hash4}.png")
        ->and($media->collection)->toBe('library');

    Storage::disk('r2')->assertExists($media->storage_key);
});

it('keys a presigned upload under the site code too', function (): void {
    $tempKey = '_incoming/a/01code.png';
    Storage::disk('r2')->put($tempKey, codeKeyPng());
    Cache::put(PresignMediaUpload::ownershipKey($tempKey), 1, now()->addMinutes(5));

    $media = app(Tenancy::class)->enter(1, fn () => app(RegisterMediaUpload::class)->handle($tempKey, 'Bảng Giá.png'));

    expect($media->storage_key)->toMatch('#^'.siteCode(1).'/bang-gia-[0-9a-f]{4}\.png$#');
});

it('gives two websites with the same name separate prefixes, so neither touches the other\'s file', function (): void {
    DB::connection('pgsql_admin')->table('sites')->where('id', 2)->update(['name' => 'A']);   // same name as site 1

    $first = uploadAs(1, 'logo.png', codeKeyPng());
    $second = uploadAs(2, 'logo.png', codeKeyPng());

    expect($first->storage_key)->toStartWith(siteCode(1).'/')
        ->and($second->storage_key)->toStartWith(siteCode(2).'/')
        ->and($first->storage_key)->not->toBe($second->storage_key);

    // Emptying site 1's trash deletes only its own object.
    app(Tenancy::class)->enter(1, fn () => Media::query()->findOrFail($first->id)->forceDelete());

    Storage::disk('r2')->assertMissing($first->storage_key);
    Storage::disk('r2')->assertExists($second->storage_key);
});

it('adds year and month folders, in the site\'s own clock, when the platform turned them on', function (): void {
    app(Tenancy::class)->enter(1, fn () => SiteFeature::query()->create(['feature_key' => 'media.year_month_folders', 'value' => true]));
    $this->travelTo(new DateTimeImmutable('2026-03-31 18:30:00', new DateTimeZone('UTC')));   // 1 April, 01:30 in Hà Nội

    $withFolders = uploadAs(1, 'anh.png', codeKeyPng());
    $withoutFolders = uploadAs(2, 'anh.png', codeKeyPng());

    expect($withFolders->storage_key)->toMatch('#^'.siteCode(1).'/2026/04/anh-[0-9a-f]{4}\.png$#')
        ->and($withoutFolders->storage_key)->toMatch('#^'.siteCode(2).'/anh-[0-9a-f]{4}\.png$#');
});

it('keeps each {slug}-{hash} stem unique within the site whatever the extension', function (): void {
    $hash = hash('sha256', codeKeyPng());
    app(Tenancy::class)->enter(1, fn () => Media::query()->create([
        'collection' => 'library', 'slug' => 'anh', 'hash' => str_repeat('c', 64),
        'storage_key' => siteCode(1).'/anh-'.substr($hash, 0, 4).'.jpg', 'disk' => 'r2', 'mime' => 'image/jpeg', 'size' => 1,
    ]));

    $media = uploadAs(1, 'anh.png', codeKeyPng());

    expect($media->storage_key)->toBe(siteCode(1).'/anh-'.substr($hash, 0, 5).'.png');   // one more hex, not .png beside .jpg
});

it('never shortens the hash below four characters', function (int $configured): void {
    config(['media.url_hash_length' => $configured]);

    $media = uploadAs(1, 'anh.png', codeKeyPng());

    expect($media->storage_key)->toBe(siteCode(1).'/anh-'.substr(hash('sha256', codeKeyPng()), 0, 4).'.png');
})->with([0, 2]);

it('builds slugs without dots, and refuses a prefix or slug that is not of the key format', function (): void {
    $media = uploadAs(1, 'Bảng.Giá.v2.png', codeKeyPng('dots'));
    $hash = str_repeat('a', 64);

    expect(pathinfo($media->storage_key, PATHINFO_FILENAME))->not->toContain('.')
        ->and(fn () => app(Tenancy::class)->enter(1, fn () => MediaKey::unique(siteCode(1).'/', 'anh.v2', $hash, 'png')))->toThrow(MediaKeyRejectedException::class)
        ->and(fn () => app(Tenancy::class)->enter(1, fn () => MediaKey::unique('an-vinh/', 'anh', $hash, 'png')))->toThrow(MediaKeyRejectedException::class)
        ->and(fn () => app(Tenancy::class)->enter(1, fn () => MediaKey::unique(siteCode(1).'/2026/13/', 'anh', $hash, 'png')))->toThrow(MediaKeyRejectedException::class)
        ->and(fn () => app(Tenancy::class)->enter(1, fn () => MediaKey::unique(siteCode(1).'/', 'anh', $hash, 'exe')))->toThrow(MediaKeyRejectedException::class);
});

it('checks the stem against every extension an upload can be stored with', function (): void {
    expect(array_values(array_diff(array_values(UploadMedia::MIME_EXT), MediaKey::EXTENSIONS)))->toBe([]);
});
