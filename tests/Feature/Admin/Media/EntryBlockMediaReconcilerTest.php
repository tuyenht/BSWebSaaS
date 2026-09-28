<?php

use App\Modules\Admin\Support\MediaUsageLocator;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\EntryBlockMediaReconciler;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * Slice 2 — usage tracking. A block image is a media_id buried in blocks[].props, which no indexed
 * query can find. The reconciler materializes it into mediables (collection='content') on save, across
 * all locale bodies, so "where is this media used" becomes an indexed lookup. Proves: create, prune on
 * change, aggregate across locales, clear when none remain.
 */

beforeEach(fn () => seedTenancy());

function reconcilerMedia(string $slug, string $hashChar): Media
{
    return Media::query()->create([
        'collection' => 'library', 'slug' => $slug, 'hash' => str_repeat($hashChar, 64),
        'storage_key' => "a/library/{$slug}-abcdef123456.png", 'disk' => 'r2',
        'mime' => 'image/png', 'size' => 1024,
    ]);
}

function contentMediaIds(Entry $entry): array
{
    return Mediable::query()
        ->where('mediable_type', 'entry')->where('mediable_id', $entry->id)->where('collection', 'content')
        ->orderBy('media_id')->pluck('media_id')->all();
}

it('materializes block image ids into content mediables and prunes on change', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = reconcilerMedia('a', 'a');
        $b = reconcilerMedia('b', 'b');
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
        $entry->translations()->create([
            'locale' => 'vi', 'title' => 'T', 'slug' => 't', 'status' => 'draft',
            'body' => ['blocks' => [['type' => 'hero', 'props' => ['image_url' => $a->id]]]],
        ]);

        app(EntryBlockMediaReconciler::class)->reconcile($entry);
        expect(contentMediaIds($entry))->toBe([$a->id]);

        $entry->translations()->where('locale', 'vi')->update([
            'body' => ['blocks' => [['type' => 'hero', 'props' => ['image_url' => $b->id]]]],
        ]);
        app(EntryBlockMediaReconciler::class)->reconcile($entry);
        expect(contentMediaIds($entry))->toBe([$b->id]);   // a pruned, b added
    });
});

it('aggregates media ids across all locale bodies', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = reconcilerMedia('a', 'a');
        $b = reconcilerMedia('b', 'b');
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'T', 'slug' => 't', 'status' => 'draft',
            'body' => ['blocks' => [['type' => 'hero', 'props' => ['image_url' => $a->id]]]]]);
        $entry->translations()->create(['locale' => 'en', 'title' => 'T', 'slug' => 't-en', 'status' => 'draft',
            'body' => ['blocks' => [['type' => 'about', 'props' => ['image_url' => $b->id]]]]]);

        app(EntryBlockMediaReconciler::class)->reconcile($entry);
        expect(contentMediaIds($entry))->toBe([$a->id, $b->id]);
    });
});

it('clears content rows when no block images remain', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = reconcilerMedia('a', 'a');
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'T', 'slug' => 't', 'status' => 'draft',
            'body' => ['blocks' => [['type' => 'hero', 'props' => ['image_url' => $a->id]]]]]);
        app(EntryBlockMediaReconciler::class)->reconcile($entry);
        expect(contentMediaIds($entry))->toHaveCount(1);

        $entry->translations()->where('locale', 'vi')->update(['body' => ['blocks' => [['type' => 'richtext', 'props' => ['heading' => 'x']]]]]);
        app(EntryBlockMediaReconciler::class)->reconcile($entry);
        expect(contentMediaIds($entry))->toBe([]);
    });
});

it('surfaces a block-image entry in MediaUsageLocator', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $m = reconcilerMedia('a', 'a');
        $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => []]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Bài A', 'slug' => 'bai-a', 'status' => 'draft',
            'body' => ['blocks' => [['type' => 'hero', 'props' => ['image_url' => $m->id]]]]]);
        app(EntryBlockMediaReconciler::class)->reconcile($entry);

        $usages = app(MediaUsageLocator::class)->usagesFor($m, 'vi');

        expect($usages)->toHaveCount(1)                    // one block usage → one row, role=content
            ->and($usages[0]['kind'])->toBe('post')
            ->and($usages[0]['role'])->toBe('content')
            ->and($usages[0]['label'])->toBe('Bài A');
    });
});

it('lists an entry under BOTH cover and content when it uses the media in both roles', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $m = reconcilerMedia('a', 'a');
        $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => $m->storage_key]]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'X', 'slug' => 'x', 'status' => 'draft',
            'body' => ['blocks' => [['type' => 'hero', 'props' => ['image_url' => $m->id]]]]]);
        app(EntryBlockMediaReconciler::class)->reconcile($entry);

        $roles = collect(app(MediaUsageLocator::class)->usagesFor($m, 'vi'))->pluck('role')->sort()->values()->all();
        expect($roles)->toBe(['content', 'cover']);   // cover (meta key) + content (block) — two rows, not deduped
    });
});

it('media:reconcile-content-usage backfills entries that never went through a save', function (): void {
    $mediaId = app(Tenancy::class)->enter(1, function (): int {
        $m = reconcilerMedia('a', 'a');
        Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []])
            ->translations()->create(['locale' => 'vi', 'title' => 'T', 'slug' => 't', 'status' => 'draft',
                'body' => ['blocks' => [['type' => 'hero', 'props' => ['image_url' => $m->id]]]]]);
        expect(Mediable::query()->where('media_id', $m->id)->exists())->toBeFalse();   // seeded → not tracked yet

        return (int) $m->id;
    });

    $this->artisan('media:reconcile-content-usage')->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($mediaId): void {
        expect(Mediable::query()->where('media_id', $mediaId)->where('collection', 'content')->exists())->toBeTrue();
    });
});

it('cleans an entry content mediables on force-delete, keeps them on soft-delete', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $m = reconcilerMedia('a', 'a');
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'T', 'slug' => 't', 'status' => 'draft',
            'body' => ['blocks' => [['type' => 'hero', 'props' => ['image_url' => $m->id]]]]]);
        app(EntryBlockMediaReconciler::class)->reconcile($entry);

        $exists = fn (): bool => Mediable::query()->where('media_id', $m->id)->where('collection', 'content')->exists();
        expect($exists())->toBeTrue();

        $entry->delete();          // soft (Trash) → kept, so a restore keeps its media
        expect($exists())->toBeTrue();

        $entry->forceDelete();     // permanent → cleaned (no orphan, no false "used")
        expect($exists())->toBeFalse();
    });
});
