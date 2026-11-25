<?php

use App\Modules\Admin\Support\MediaUsageLocator;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\EntryCoverUsage;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;

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

        expect($counts)->toBe([$cover->id => 1, $unused->id => 0])
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
