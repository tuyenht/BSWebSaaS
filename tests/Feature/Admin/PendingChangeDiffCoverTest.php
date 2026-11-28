<?php

use App\Modules\Admin\Support\Entries\PendingChangeDiff;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * «So sánh» of a pending change, the picture row: each side's cover is the file its cover_media_id
 * names when the id is set (none when that file is in the Trash or gone, the old key in meta never
 * read), else the meta's old key, and an empty key is no cover. The diff reads nothing itself:
 * coverRefs() reads the files the changes and their entries name by id, once for a list.
 */

beforeEach(function (): void {
    seedTenancy();
});

/** A library file of the current site. */
function pcdMedia(string $slug): Media
{
    return Media::query()->create([
        'collection' => 'library', 'slug' => $slug, 'hash' => hash('sha256', $slug),
        'storage_key' => 'a/library/'.$slug.'.webp', 'disk' => 'r2', 'mime' => 'image/webp', 'size' => 1024,
    ]);
}

/**
 * The picture row of the diff between a live entry and a change with these shared fields, or null.
 *
 * @param  array<string, mixed>  $live  the entry's cover_media_id and meta
 * @param  array<string, mixed>  $shared  the change's shared fields
 * @return array{now: string, seen: string}|null
 */
function pcdCoverRow(array $live, array $shared): ?array
{
    $entry = (new Entry)->forceFill(['type' => 'page', 'template_override' => null] + $live);
    $change = (new EntryDraft)->forceFill(['content' => ['locales' => [], 'shared' => $shared]]);
    $change->setRelation('entry', $entry);

    $diff = app(PendingChangeDiff::class)->between($entry, $change, collect(), null, fn (array $ids): array => [], ['vi'], PendingChangeDiff::coverRefs([$change]));
    $row = collect($diff['rows'])->firstWhere('label', __('entries.chg.f_cover'));

    return $row === null ? null : ['now' => $row['now'], 'seen' => $row['seen']];
}

it('reads each side’s cover by its id when it has one, the old key only without one', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $photo = pcdMedia('bia-moi');
        $old = pcdMedia('bia-cu');
        $trashed = pcdMedia('bia-da-xoa');
        $trashed->delete();
        $other = __('entries.chg.cover_other');
        $empty = __('entries.chg.diff_empty');

        // the change names a file by id, no old key; the live entry has no cover
        expect(pcdCoverRow(['cover_media_id' => null, 'meta' => []], ['cover_media_id' => $photo->id, 'meta' => []]))
            ->toBe(['now' => $other, 'seen' => $empty])
            // the live entry names the file by id, the change holds the same file by its old key: no difference
            ->and(pcdCoverRow(['cover_media_id' => $photo->id, 'meta' => []], ['cover_media_id' => null, 'meta' => ['image_url' => $photo->storage_key]]))->toBeNull()
            // the change names a file in the Trash beside the live old key: no cover, the old key not read
            ->and(pcdCoverRow(['cover_media_id' => null, 'meta' => ['image_url' => $old->storage_key]], ['cover_media_id' => $trashed->id, 'meta' => ['image_url' => $old->storage_key]]))
            ->toBe(['now' => $empty, 'seen' => $other])
            // an empty old key is no cover, as no key is
            ->and(pcdCoverRow(['cover_media_id' => null, 'meta' => []], ['cover_media_id' => null, 'meta' => ['image_url' => '']]))->toBeNull();
    });
});

it('reads the files the changes and their entries name by id in one query, and none while none does', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = pcdMedia('bia-a');
        $b = pcdMedia('bia-b');
        $change = (new EntryDraft)->forceFill(['content' => ['locales' => [], 'shared' => ['cover_media_id' => $a->id]]]);
        $change->setRelation('entry', (new Entry)->forceFill(['cover_media_id' => $b->id]));
        $plain = (new EntryDraft)->forceFill(['content' => ['locales' => [], 'shared' => ['meta' => ['image_url' => $a->storage_key]]]]);
        $plain->setRelation('entry', (new Entry)->forceFill(['cover_media_id' => null]));

        $queries = countQueries(fn () => PendingChangeDiff::coverRefs([$plain]));
        $refs = null;
        $both = countQueries(function () use ($change, $plain, &$refs): void {
            $refs = PendingChangeDiff::coverRefs([$change, $plain]);
        });

        expect($queries)->toBe(0)
            ->and($both)->toBe(1)
            ->and($refs->byId($a->id)?->storage_key)->toBe($a->storage_key)
            ->and($refs->byId($b->id)?->storage_key)->toBe($b->storage_key);
    });
});
