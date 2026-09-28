<?php

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Media\Models\Mediable;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SchemaImages;
use Illuminate\Database\Eloquent\Builder;

/**
 * Materializes an entry's block image usage into `mediables` (collection='content') so "where is this
 * media used" is an indexed lookup, not a scan of every entry body — the anti-scan contract at tenant
 * scale (a media_id buried in blocks[].props can't be found by an indexed query otherwise).
 *
 * Reconciled on save: collects every media_id referenced by an image prop across ALL locale bodies,
 * then upserts/prunes the entry's content rows to match. It runs inside the request transaction (the
 * save), so the usage index never drifts from the content. Lives in Cms, which already depends on
 * Media and Theme, so every Cms write path that changes a body (the editor save, a revision restore)
 * reconciles through this one class.
 */
final readonly class EntryBlockMediaReconciler
{
    public function __construct(private ComponentRegistry $registry) {}

    public function reconcile(Entry $entry): void
    {
        $mediaIds = $this->blockMediaIds($entry);

        // Prune content rows this entry no longer references, then upsert the current set (idempotent).
        // Empty set (no block images) → the when() is skipped, so every content row is pruned.
        Mediable::query()
            ->where('mediable_type', 'entry')
            ->where('mediable_id', $entry->id)
            ->where('collection', 'content')
            ->when($mediaIds !== [], fn (Builder $query) => $query->whereNotIn('media_id', $mediaIds))
            ->delete();

        foreach ($mediaIds as $id) {
            Mediable::query()->updateOrCreate([
                'media_id' => $id,
                'mediable_type' => 'entry',
                'mediable_id' => $entry->id,
                'collection' => 'content',
            ]);
        }
    }

    /**
     * Every media_id referenced by an image prop across all of the entry's locale block bodies.
     *
     * @return list<int>
     */
    private function blockMediaIds(Entry $entry): array
    {
        $ids = [];
        foreach ($entry->translations()->get() as $translation) {
            $blocks = is_array($translation->body['blocks'] ?? null) ? $translation->body['blocks'] : [];
            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $type = $block['type'] ?? null;
                $props = is_array($block['props'] ?? null) ? $block['props'] : [];
                if (is_string($type) && $this->registry->has($type)) {
                    $ids = array_merge($ids, SchemaImages::ids($props, $this->registry->get($type)->schema()));
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
