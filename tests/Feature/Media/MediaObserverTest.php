<?php

use App\Modules\Media\Actions\PurgeMedia;
use App\Modules\Media\Actions\TrashMedia;
use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Media\Support\MediaStorage;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
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

/*
 * A purge deletes the stored file only after it commits; by then the key is free again. A re-upload of
 * the same bytes under the same name gets that same key and stores its object there before the delete
 * runs, so the delete must look once more and leave a key a row names by then.
 */
it('leaves the stored file of a re-upload that took the purged file’s key before its delete ran', function (): void {
    $bytes = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
    $old = app(Tenancy::class)->enter(1, function () use ($bytes): Media {
        $media = app(UploadMedia::class)->handle(UploadedFile::fake()->createWithContent('may-tien.png', $bytes));
        app(TrashMedia::class)->trash(new Collection([$media]));

        return $media;
    });

    $new = app(Tenancy::class)->enter(1, function () use ($bytes): Media {
        app(PurgeMedia::class)->purge(Media::onlyTrashed()->get());

        return app(UploadMedia::class)->handle(UploadedFile::fake()->createWithContent('may-tien.png', $bytes));
    });

    expect($new->id)->not->toBe($old->id)
        ->and($new->storage_key)->toBe($old->storage_key) // the same name and bytes: the same key
        ->and(app(Tenancy::class)->enter(1, fn () => Media::query()->pluck('id')->all()))->toBe([$new->id]);
    Storage::disk('r2')->assertExists($new->storage_key);
});

/** Trashed files of site 1, each with its stored object (the caller wraps Tenancy::enter). */
function trashedFiles(int $n): Collection
{
    $rows = new Collection;
    for ($i = 1; $i <= $n; $i++) {
        $rows->push(makeMediaWithAttachment("a/library/xoa-{$i}.png", hash('sha256', "xoa-{$i}")));
    }
    app(TrashMedia::class)->trash($rows);

    return $rows;
}

/*
 * The last look before the stored files go is one tenant transaction for everything one purge deletes,
 * not one per file: the website's context is entered once more after the commit, whatever the count.
 */
it('looks at the website\'s rows once for all the files one purge deletes', function (): void {
    $rows = app(Tenancy::class)->enter(1, fn (): Collection => trashedFiles(3));

    $entered = 0;
    DB::listen(function ($query) use (&$entered): void {
        if (str_contains($query->sql, 'set_config') && ($query->bindings[0] ?? null) === 'app.current_site_id') {
            $entered++;
        }
    });
    app(Tenancy::class)->enter(1, fn () => app(PurgeMedia::class)->purge(Media::onlyTrashed()->get()));

    expect($entered)->toBe(2); // the purge's own transaction, then the one last look
    foreach ($rows as $media) {
        Storage::disk('r2')->assertMissing($media->storage_key);
    }
});

/*
 * A moved file keeps its old address (`meta.moved_from`). When some row of the website names that
 * address again by the time the delete runs (a row back from a purge that did not hold, say), the
 * last look keeps the object, as it keeps a stored key a row names.
 */
it('keeps an old address a row names again by the time the delete runs', function (): void {
    $old = 'a/library/cu-eeeeeeeeeeee.png';
    $new = 'a/library/moi-eeeeeeeeeeee.png';
    Storage::disk('r2')->put($old, 'old');

    app(Tenancy::class)->enter(1, function () use ($old, $new): void {
        $moved = makeMediaWithAttachment($new, str_repeat('e', 64));
        $moved->update(['meta' => ['moved_from' => $old]]);
        app(TrashMedia::class)->trash(new Collection([$moved]));
    });

    app(Tenancy::class)->enter(1, function () use ($old): void {
        app(PurgeMedia::class)->purge(Media::onlyTrashed()->get());

        // after the purge's own check, before its delete runs: a row names the old address again
        $back = makeMediaWithAttachment('a/library/khac-ffffffffffff.png', str_repeat('f', 64));
        $back->update(['meta' => ['moved_from' => $old]]);
    });

    Storage::disk('r2')->assertMissing($new);
    Storage::disk('r2')->assertExists($old);
});

it('keeps every stored file when the last look before the delete fails, and reports it', function (): void {
    Exceptions::fake();
    $rows = app(Tenancy::class)->enter(1, fn (): Collection => trashedFiles(2));

    $armed = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$armed): void {
        if ($armed && str_contains($sql, '"storage_key" in')) {
            throw new RuntimeException('the last look fails');
        }
    });
    app(Tenancy::class)->enter(1, function () use (&$armed): void {
        app(PurgeMedia::class)->purge(Media::onlyTrashed()->get());
        $armed = true; // only the look after the commit fails
    });

    expect(app(Tenancy::class)->enter(1, fn () => Media::withTrashed()->count()))->toBe(0);
    foreach ($rows as $media) {
        Storage::disk('r2')->assertExists($media->storage_key); // a leak, never a row without its file
    }
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'the last look fails');
});

it('deletes a whole Trash a page at a time, with one last look for all of it', function (): void {
    $rows = app(Tenancy::class)->enter(1, fn (): Collection => trashedFiles(5));

    $pages = [];
    $entered = 0;
    DB::listen(function ($query) use (&$pages, &$entered): void {
        if (str_contains($query->sql, 'set_config') && ($query->bindings[0] ?? null) === 'app.current_site_id') {
            $entered++;
        }
        if (preg_match('/^select \* from "media" .*"deleted_at" is not null/', $query->sql) === 1) {
            $pages[] = $query->sql;
        }
    });
    $done = app(Tenancy::class)->enter(1, fn (): array => app(PurgeMedia::class)->purgeAll(Media::onlyTrashed(), 2));

    expect($done)->toBe(['files' => 5, 'bytes' => 30])
        ->and($pages)->toHaveCount(3)
        ->and(collect($pages)->every(fn (string $sql): bool => str_contains($sql, 'limit 2')))->toBeTrue()
        ->and($entered)->toBe(2)
        ->and(app(Tenancy::class)->enter(1, fn () => Media::withTrashed()->count()))->toBe(0);
    foreach ($rows as $media) {
        Storage::disk('r2')->assertMissing($media->storage_key);
    }
});

/*
 * The totals' row is the purge's last write (SiteCounters): a whole Trash takes its size off the
 * totals once, after its last page, so the row is not held while the later pages delete.
 */
it('takes a whole Trash off the totals once, after its last page is deleted', function (): void {
    app(Tenancy::class)->enter(1, fn (): Collection => trashedFiles(5));

    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        $sql = strtolower($query->sql);
        if (str_starts_with($sql, 'delete from "media" where')) {
            $writes[] = 'media';
        } elseif (str_starts_with(ltrim($sql), 'insert into site_counters')) {
            $writes[] = $query->bindings[1]; // the metric: one upsert per total
        }
    });
    $done = app(Tenancy::class)->enter(1, fn (): array => app(PurgeMedia::class)->purgeAll(Media::onlyTrashed(), 2));

    expect($done)->toBe(['files' => 5, 'bytes' => 30])
        ->and($writes)->toBe(['media', 'media', 'media', 'media', 'media', MediaStorage::BYTES, MediaStorage::TRASH]);
});

/*
 * A web request turns the error into an error page and its site transaction still commits: the pages
 * purged before the failing one must not go with it (all pages share one savepoint).
 */
it('deletes nothing of a whole Trash when one of its pages fails, even when the caller commits', function (): void {
    $rows = app(Tenancy::class)->enter(1, fn (): Collection => trashedFiles(3));

    $locks = 0;
    DB::connection()->beforeExecuting(function (string $sql) use (&$locks): void {
        if (str_contains($sql, 'for update') && str_contains($sql, '"media"') && ++$locks === 2) {
            throw new RuntimeException('the second page fails');
        }
    });

    $failed = app(Tenancy::class)->enter(1, function (): ?string {
        try {
            app(PurgeMedia::class)->purgeAll(Media::onlyTrashed(), 2);
        } catch (RuntimeException $e) {
            return $e->getMessage(); // the request answers its error page and commits
        }

        return null;
    });

    expect($failed)->toBe('the second page fails')
        ->and($locks)->toBe(2)
        ->and(app(Tenancy::class)->enter(1, fn () => Media::onlyTrashed()->count()))->toBe(3);
    foreach ($rows as $media) {
        Storage::disk('r2')->assertExists($media->storage_key);
    }
});
