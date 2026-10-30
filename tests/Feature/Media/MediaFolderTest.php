<?php

use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * media_folders (picker Phase 1) — the flat per-site folder level under the media library.
 * Guards the tenant invariants at the table's birth (§2): BelongsToSite stamping + cross-site
 * invisibility + FORCE RLS at the schema level, per-site citext slug uniqueness (D12), and the
 * SET NULL contract — deleting a folder returns its files to the root view, never deletes them.
 */

beforeEach(function (): void {
    seedTenancy();
});

/**
 * @param  array<string, mixed>  $attrs
 */
function seedMediaFolder(int $site, array $attrs = []): MediaFolder
{
    return app(Tenancy::class)->enter($site, fn () => MediaFolder::query()->create(array_merge([
        'name' => 'Sản phẩm',
        'slug' => 'san-pham',
    ], $attrs)));
}

/**
 * @param  array<string, mixed>  $attrs
 */
function seedMediaInFolder(array $attrs = []): Media
{
    return Media::query()->create(array_merge([
        'collection' => 'library',
        'slug' => 'anh',
        'hash' => str_repeat('b', 64),
        'storage_key' => 'a/library/anh-bbbbbbbbbbbb.png',
        'mime' => 'image/png',
        'size' => 1024,
    ], $attrs));
}

it('stamps site_id from the tenant context and stays invisible cross-site', function (): void {
    $folder = seedMediaFolder(1);

    expect($folder->site_id)->toBe(1)
        ->and(app(Tenancy::class)->enter(2, fn (): int => MediaFolder::query()->count()))->toBe(0)
        ->and(app(Tenancy::class)->enter(1, fn (): int => MediaFolder::query()->count()))->toBe(1);
});

it('enforces FORCE row level security on media_folders at the schema level', function (): void {
    $row = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', ['media_folders']);

    expect($row)->not->toBeNull()
        ->and($row->relrowsecurity)->toBeTrue()
        ->and($row->relforcerowsecurity)->toBeTrue();
});

it('allows the same slug on two sites but rejects a duplicate within one site', function (): void {
    seedMediaFolder(1);
    $other = seedMediaFolder(2); // same slug, another site — legitimate

    expect($other->slug)->toBe('san-pham');
    expect(fn () => seedMediaFolder(1, ['name' => 'Sản phẩm 2']))->toThrow(QueryException::class);
});

it('treats the per-site slug uniqueness case-insensitively (citext, D12)', function (): void {
    seedMediaFolder(1);

    expect(fn () => seedMediaFolder(1, ['slug' => 'SAN-PHAM']))->toThrow(QueryException::class);
});

it('returns files to the root view when their folder is deleted — never deletes them', function (): void {
    $mediaId = app(Tenancy::class)->enter(1, function (): int {
        $folder = MediaFolder::query()->create(['name' => 'Banner', 'slug' => 'banner']);
        $media = seedMediaInFolder(['folder_id' => $folder->id]);
        $folder->delete();

        return $media->id;
    });

    app(Tenancy::class)->enter(1, function () use ($mediaId): void {
        $media = Media::query()->find($mediaId);

        expect($media)->not->toBeNull()
            ->and($media?->folder_id)->toBeNull()
            ->and($media?->position)->toBe(0); // the drag-order column is born with a sane default
    });
});

it('relates folder ↔ media in both directions', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $folder = MediaFolder::query()->create(['name' => 'Bài viết', 'slug' => 'bai-viet']);
        $media = seedMediaInFolder(['folder_id' => $folder->id]);

        expect($folder->media()->pluck('media.id')->all())->toBe([$media->id])
            ->and($media->folder?->is($folder))->toBeTrue();
    });
});

it('makes a folder path from a name in any script, numbered when taken, and keeps a typed one', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $folder = new MediaFolder;
        MediaFolder::query()->create(['name' => 'Sản phẩm', 'slug' => 'san-pham']);

        expect($folder->suggestSlugBase('产品'))->toBe(['slug' => 'chan-pin', 'fallback' => false])
            ->and($folder->uniqueSlug('Sản phẩm', 'Sản phẩm'))->toBe('san-pham-2')
            ->and($folder->uniqueSlug('catalog', '製品カタログ'))->toBe('catalog')       // typed path kept
            ->and($folder->uniqueSlug('製品カタログ', '製品カタログ'))->toMatch('/^thu-muc-\d+$/') // no Chinese reading
            ->and($folder->uniqueSlug('!!!', 'Ảnh'))->toBe('anh');                         // nothing usable typed → from the name
    });
});
