<?php

use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * MediaObserver (Increment ③) — force-purge cleanup. Soft-delete (Thùng rác) keeps the R2 object +
 * attachments; force-deleting a master (emptying the trash) is the irreversible purge: the observer
 * drops the master's attachments (no orphan mediables) and deletes the single R2 object. It runs in
 * the master's own site context → the attachment cleanup is RLS-scoped to that site.
 */

beforeEach(function (): void {
    seedTenancy();
    Storage::fake('r2');
});

/** Create a master + one attachment in the CURRENT site context (caller wraps Tenancy::enter). */
function makeMediaWithAttachment(string $key, string $hash): Media
{
    Storage::disk('r2')->put($key, 'binary');

    $media = Media::query()->create([
        'collection' => 'library',
        'hash' => $hash,
        'storage_key' => $key,
        'disk' => 'r2',          // as UploadMedia stamps it (the observer purges from $media->disk)
        'mime' => 'image/png',
        'size' => 6,
    ]);

    Mediable::query()->create([
        'media_id' => $media->id,
        'mediable_type' => 'entry',
        'mediable_id' => 7,
        'collection' => 'gallery',
    ]);

    return $media;
}

it('force-deleting a master deletes its R2 object once the delete commits, and purges its attachments', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $media = makeMediaWithAttachment('a/library/anh-aaaaaaaaaaaa.png', str_repeat('a', 64));

        $media->forceDelete();

        expect(Mediable::query()->count())->toBe(0);
        Storage::disk('r2')->assertExists('a/library/anh-aaaaaaaaaaaa.png'); // still there until the commit
    });

    Storage::disk('r2')->assertMissing('a/library/anh-aaaaaaaaaaaa.png');
});

it('keeps the R2 object of a master whose force delete rolls back', function (): void {
    expect(fn () => app(Tenancy::class)->enter(1, function (): void {
        makeMediaWithAttachment('a/library/anh-dddddddddddd.png', str_repeat('d', 64))->forceDelete();

        throw new RuntimeException('the purge fails after the row is gone');
    }))->toThrow(RuntimeException::class);

    Storage::disk('r2')->assertExists('a/library/anh-dddddddddddd.png');
});

it('soft-deleting a master keeps its R2 object and attachments (Thùng rác)', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $media = makeMediaWithAttachment('a/library/keep-bbbbbbbbbbbb.png', str_repeat('b', 64));

        $media->delete(); // soft

        expect(Mediable::query()->count())->toBe(1);
        Storage::disk('r2')->assertExists('a/library/keep-bbbbbbbbbbbb.png');
    });
});

it('never deletes an object under a seed folder, whichever field names it', function (): void {
    config(['media.seed_folders' => ['seed-demo']]);
    DB::connection('pgsql_admin')->table('reserved_site_codes')->insert(['code' => 'seed-demo', 'reason' => 'legacy_key', 'site_id' => 1]);
    $seed = 'seed-demo/hero/anh-cccccccccccc.png';
    $own = 'a/library/anh-dddddddddddd.png';
    Storage::disk('r2')->put($seed, 'seed');

    app(Tenancy::class)->enter(1, function () use ($seed, $own): void {
        makeMediaWithAttachment($seed, str_repeat('c', 64))->forceDelete();   // a row still holding the seed key
        $moved = makeMediaWithAttachment($own, str_repeat('d', 64));
        $moved->update(['meta' => ['moved_from' => $seed]]);                  // a moved row naming it as its old key
        $moved->forceDelete();
    });

    Storage::disk('r2')->assertExists($seed);
    Storage::disk('r2')->assertMissing($own);
});
