<?php

use App\Modules\Admin\Livewire\Media\Library;
use App\Modules\Admin\Support\MediaUsageLocator;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\EntryCoverUsage;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * An entry's featured / share picture is named by its storage key inside entries.meta, which no
 * index serves. Its "in use" row (mediables, collection `cover`) is written with every save of the
 * entry, so the library counts it on the tile, filters it and lists it in the drawer from that row
 * alone, the same answer in all three, and never reads the entries' meta to do it.
 */

beforeEach(fn () => seedTenancy());

function coverMedia(string $slug, int $site = 1): Media
{
    return app(Tenancy::class)->enter($site, fn (): Media => Media::query()->create([
        'collection' => 'library', 'slug' => $slug, 'hash' => hash('sha256', $site.$slug),
        'storage_key' => "s{$site}/library/{$slug}-abcdef123456.png", 'disk' => 'r2',
        'mime' => 'image/png', 'size' => 1024,
    ]));
}

/** @return list<int> the media ids of the entry's cover rows */
function coverRows(Entry $entry): array
{
    return array_map('intval', Mediable::query()->where('mediable_type', 'entry')->where('mediable_id', $entry->id)
        ->where('collection', EntryCoverUsage::COLLECTION)->orderBy('media_id')->pluck('media_id')->all());
}

it('writes the featured picture’s row with the entry, swaps it with the picture and drops it with it', function (): void {
    $a = coverMedia('a');
    $b = coverMedia('b');
    $theirs = coverMedia('theirs', 2);

    app(Tenancy::class)->enter(1, function () use ($a, $b, $theirs): void {
        $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => $a->storage_key]]);
        expect(coverRows($entry))->toBe([$a->id]);

        $entry->update(['meta' => ['image_url' => $b->storage_key, 'display_date' => '2026-11-25']]);
        expect(coverRows($entry))->toBe([$b->id]);

        // another meta key changes, the picture does not: no statement on mediables
        DB::flushQueryLog();
        DB::enableQueryLog();
        $entry->update(['meta' => ['image_url' => $b->storage_key, 'display_date' => '2026-11-26']]);
        $sql = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        expect(implode(' ', $sql))->not->toContain('mediables');

        $entry->update(['meta' => ['display_date' => '2026-11-26']]);
        expect(coverRows($entry))->toBe([]);

        // a key no file of this site has (another site's file, an outside address) writes no row
        $entry->update(['meta' => ['image_url' => $theirs->storage_key]]);
        expect(coverRows($entry))->toBe([]);
    });
});

it('counts a post’s featured picture on its tile, in the filter and in the drawer alike, never reading the entries’ meta', function (): void {
    $cover = coverMedia('cover');
    $unused = coverMedia('unused');

    app(Tenancy::class)->enter(1, function () use ($cover, $unused): void {
        Entry::query()->create(['type' => 'post', 'status' => 'published', 'meta' => ['image_url' => $cover->storage_key]]);
        $locator = app(MediaUsageLocator::class);
        $files = Media::query()->whereIn('id', [$cover->id, $unused->id])->orderBy('id')->get();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $counts = $locator->countsFor($files);
        $used = Media::query()->whereIn('id', [$cover->id, $unused->id]);
        $locator->constrain($used, true, liveOwners: true);
        $usedIds = $used->pluck('id')->all();
        $sql = strtolower(implode(' ', array_column(DB::getQueryLog(), 'query')));
        DB::disableQueryLog();

        expect(collect($counts)->sortKeys()->all())->toBe([$cover->id => 1, $unused->id => 0])
            ->and($usedIds)->toBe([$cover->id])
            ->and($sql)->not->toContain('image_url')
            ->and(collect($locator->usagesFor($cover, 'vi'))->pluck('role')->all())->toBe(['cover']);
    });
});

it('says «Chưa dùng» for the picture of a post in the Trash, keeps it from deletion, and forgets it with the post', function (): void {
    $cover = coverMedia('cover');

    app(Tenancy::class)->enter(1, function () use ($cover): void {
        $post = Entry::query()->create(['type' => 'post', 'status' => 'published', 'meta' => ['image_url' => $cover->storage_key]]);
        $post->delete();
        $locator = app(MediaUsageLocator::class);

        expect($locator->countsFor(Media::query()->whereKey($cover->id)->get()))->toBe([$cover->id => 0])
            ->and($locator->usagesFor($cover, 'vi'))->toBe([]);
        $kept = Media::query()->whereKey($cover->id);
        $locator->constrain($kept, false);
        expect($kept->exists())->toBeFalse(); // who may send a file to the Trash still reads it as used

        $post->forceDelete();
        expect(coverRows($post))->toBe([]);
    });
});

it('rebuilds the featured pictures’ rows of content written past the model, and drops the rows of entries gone', function (): void {
    $cover = coverMedia('cover');

    [$quiet, $gone] = app(Tenancy::class)->enter(1, function () use ($cover): array {
        $quiet = Entry::query()->create(['type' => 'post', 'status' => 'published', 'meta' => []]);
        Entry::query()->whereKey($quiet->id)->update(['meta' => json_encode(['image_url' => $cover->storage_key])]);
        $gone = Entry::query()->create(['type' => 'post', 'status' => 'published', 'meta' => []]);
        Mediable::query()->create(['media_id' => $cover->id, 'mediable_type' => 'entry', 'mediable_id' => $gone->id, 'collection' => EntryCoverUsage::COLLECTION]);
        Entry::query()->whereKey($gone->id)->forceDelete(); // past the model: no observer

        expect(coverRows($quiet))->toBe([])->and(coverRows($gone))->toBe([$cover->id]);

        return [$quiet, $gone];
    });

    $this->artisan('media:reconcile-content-usage')->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($quiet, $gone, $cover): void {
        expect(coverRows($quiet))->toBe([$cover->id])->and(coverRows($gone))->toBe([]);
    });
});

/** @return list<int> the ids of every cover row of site 1, to tell a kept row from a rewritten one */
function coverRowIds(): array
{
    return array_map('intval', DB::connection('pgsql_admin')->table('mediables')->where('site_id', 1)
        ->where('collection', EntryCoverUsage::COLLECTION)->orderBy('id')->pluck('id')->all());
}

it('rebuilds the cover rows of posts in the Trash too, idempotently, and the Trash question then calls their picture used', function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Storage::fake('r2');
    app()->setLocale('vi');

    $cover = coverMedia('cover');
    $other = coverMedia('other');
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['media.view', 'media.upload', 'media.delete.any']));

    [$trashed, $live] = app(Tenancy::class)->enter(1, function () use ($cover, $other): array {
        // a post that sat in the Trash before the cover rows existed: written past the model
        $trashed = Entry::query()->create(['type' => 'post', 'status' => 'published', 'meta' => []]);
        Entry::query()->whereKey($trashed->id)->update(['meta' => json_encode(['image_url' => $cover->storage_key]), 'deleted_at' => now()]);
        $live = Entry::query()->create(['type' => 'post', 'status' => 'published', 'meta' => ['image_url' => $cover->storage_key]]);
        // drift on the live post: a stale row naming another file and a purged file's NULL row
        Mediable::query()->where('mediable_id', $live->id)->where('collection', EntryCoverUsage::COLLECTION)->delete();
        foreach ([$other->id, null] as $media) {
            DB::table('mediables')->insert(['media_id' => $media, 'mediable_type' => 'entry', 'mediable_id' => $live->id,
                'site_id' => 1, 'collection' => EntryCoverUsage::COLLECTION, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$trashed, $live];
    });

    $this->artisan('media:reconcile-content-usage')->assertSuccessful();
    $after = coverRowIds();
    app(Tenancy::class)->enter(1, function () use ($trashed, $live, $cover): void {
        expect(coverRows($trashed))->toBe([$cover->id])->and(coverRows($live))->toBe([$cover->id]);
    });

    // a second run changes nothing: the same rows, none rewritten
    $this->artisan('media:reconcile-content-usage')->assertSuccessful();
    expect(coverRowIds())->toBe($after)->and($after)->toHaveCount(2);

    app(Tenancy::class)->enter(1, function () use ($user, $cover, $live): void {
        $live->forceDelete(); // only the post in the Trash uses the picture now

        Livewire::actingAs($user)->test(Library::class)
            ->call('openDetail', $cover->ulid)
            ->call('trashDetail')
            ->assertSet('askTrashOpen', true);

        // without its row the Trash question still reads the trashed post's meta
        Mediable::query()->where('collection', EntryCoverUsage::COLLECTION)->delete();
        $used = Media::query()->whereKey($cover->id);
        app(MediaUsageLocator::class)->constrain($used, true);
        expect($used->exists())->toBeTrue();
    });
});

it('keeps the cover row of a post back from the Trash, and makes it match a picture changed while it was there', function (): void {
    $a = coverMedia('a');
    $b = coverMedia('b');

    app(Tenancy::class)->enter(1, function () use ($a, $b): void {
        $post = Entry::query()->create(['type' => 'post', 'status' => 'published', 'meta' => ['image_url' => $a->storage_key]]);
        $post->delete();
        expect(coverRows($post))->toBe([$a->id]);
        $post->restore();
        expect(coverRows($post))->toBe([$a->id]);

        $post->delete();
        Entry::withTrashed()->whereKey($post->id)->update(['meta' => json_encode(['image_url' => $b->storage_key])]); // past the model
        $post = Entry::withTrashed()->findOrFail($post->id);
        $post->restore();
        expect(coverRows($post))->toBe([$b->id]);
    });
});

it('never undoes a cover change that commits while the rebuild runs', function (): void {
    $a = coverMedia('a');
    $b = coverMedia('b');

    $post = app(Tenancy::class)->enter(1, fn (): Entry => Entry::query()->create(['type' => 'post', 'status' => 'published', 'meta' => ['image_url' => $a->storage_key]]));

    // between the rebuild's INSERT and its DELETE another session saves the post with picture B
    // and commits, its rows swapped as the observer swaps them
    $fired = false;
    DB::listen(function (QueryExecuted $query) use (&$fired, $post, $a, $b): void {
        if ($fired || $query->connectionName === 'pgsql_admin' || ! str_contains($query->sql, 'INSERT INTO mediables')) {
            return;
        }
        $fired = true;
        $other = DB::connection('pgsql_admin');
        $other->table('entries')->where('id', $post->id)->update(['meta' => json_encode(['image_url' => $b->storage_key])]);
        $other->table('mediables')->where('mediable_id', $post->id)->where('collection', EntryCoverUsage::COLLECTION)->where('media_id', $a->id)->delete();
        $other->table('mediables')->insert(['media_id' => $b->id, 'mediable_type' => 'entry', 'mediable_id' => $post->id,
            'site_id' => 1, 'collection' => EntryCoverUsage::COLLECTION, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
    });

    app(Tenancy::class)->enter(1, fn () => EntryCoverUsage::reconcileSite(1));

    expect($fired)->toBeTrue();
    app(Tenancy::class)->enter(1, fn () => expect(coverRows($post))->toBe([$b->id]));
});

it('reads a cover named by id alone, the old key only without an id, in the row, the rebuild and the Trash question', function (): void {
    $byId = coverMedia('by-id');
    $byKey = coverMedia('by-key');
    $theirs = coverMedia('theirs', 2);
    $trashed = coverMedia('trashed');

    app(Tenancy::class)->enter(1, function () use ($byId, $byKey, $theirs, $trashed): void {
        // a new id on a post still holding an old key: the id wins
        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => $byKey->storage_key]]);
        expect(coverRows($post))->toBe([$byKey->id]);
        $post->update(['cover_media_id' => $byId->id]);
        expect(coverRows($post))->toBe([$byId->id]);

        // an id that names no file of this site (another site's) is no picture: the old key is not read
        $post->update(['cover_media_id' => $theirs->id]);
        expect(coverRows($post))->toBe([]);

        // an id naming a file in the Trash beside the old key of a live one: the id's file keeps its use
        $trashed->delete();
        $held = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => $byKey->storage_key]]);
        $held->update(['cover_media_id' => $trashed->id]);
        expect(coverRows($held))->toBe([$trashed->id]);

        // by id alone, written past the model, then rebuilt from the id
        $quiet = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => []]);
        Entry::query()->whereKey($quiet->id)->update(['cover_media_id' => $byId->id]);
        expect(coverRows($quiet))->toBe([]);
        EntryCoverUsage::reconcileSite(1);
        expect(coverRows($quiet))->toBe([$byId->id])
            ->and(coverRows($post))->toBe([])
            ->and(coverRows($held))->toBe([$trashed->id]);

        // the rebuild writes the same rows from nothing
        Mediable::query()->where('collection', EntryCoverUsage::COLLECTION)->delete();
        DB::table('mediables')->insert(['media_id' => $byKey->id, 'mediable_type' => 'entry', 'mediable_id' => $held->id, 'site_id' => 1, 'collection' => EntryCoverUsage::COLLECTION, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        EntryCoverUsage::reconcileSite(1);
        expect(coverRows($held))->toBe([$trashed->id])
            ->and(coverRows($post))->toBe([])
            ->and(coverRows($quiet))->toBe([$byId->id]);

        // the protective reading keeps a file named only by an id out of the Trash, without its row
        Mediable::query()->where('collection', EntryCoverUsage::COLLECTION)->delete();
        $used = Media::query()->whereKey($byId->id);
        app(MediaUsageLocator::class)->constrain($used, true);
        $unused = Media::query()->whereKey($byId->id);
        app(MediaUsageLocator::class)->constrain($unused, false);
        expect($used->exists())->toBeTrue()->and($unused->exists())->toBeFalse();
    });
});
