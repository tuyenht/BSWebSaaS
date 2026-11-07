<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Shared\Support\Cast;
use App\Modules\Theme\Components\ButtonLink;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SchemaHtml;
use App\Modules\Theme\Components\SchemaImages;
use App\Modules\Theme\Rendering\RichHtml;
use Illuminate\Database\Eloquent\Builder;

/**
 * The library's "in use" rows (`mediables`, collection `content`) of one owner of blocks: an entry
 * (its bodies in every language), the site's block slots setting, or a category's own slot blocks.
 * A media id buried in blocks[].props cannot be found by an indexed query, so each save writes it
 * here, and "where is this used" and the unused filter read only this table — the anti-scan
 * contract at tenant scale.
 *
 * sync() collects every media id the block lists reference: by an image or video prop (a hero's
 * background video counts as used like a picture), by a library picture (`img[data-media-id]`)
 * inside a formatted-text (`html`) prop, or by a "Tải tệp" button (its library file named by
 * storage key, ButtonLink). It keeps the ones that name this site's media, prunes the owner's
 * content rows that are no longer named and adds the missing ones. It runs inside the caller's
 * transaction (the save), so the index never drifts from the content. forget() drops an owner's
 * rows when the owner is gone: the attachment is polymorphic, no foreign key cascades it (D3).
 *
 * Holds no state beyond the component registry (a boot-time singleton), so it is Octane-safe.
 */
final readonly class BlockMediaUsage
{
    public const string COLLECTION = 'content';

    public function __construct(private ComponentRegistry $registry) {}

    /**
     * @param  string  $ownerType  the morph alias (entry, taxonomy, site_setting)
     * @param  iterable<mixed>  $blockLists  each a list of blocks ([{type, props}, …])
     */
    public function sync(string $ownerType, int $ownerId, iterable $blockLists): void
    {
        [$ids, $keys] = $this->referenced($blockLists);
        $mediaIds = $this->visibleMediaIds($ids, $keys);

        $rows = fn (): Builder => Mediable::query()
            ->where('mediable_type', $ownerType)
            ->where('mediable_id', $ownerId)
            ->where('collection', self::COLLECTION);

        // Prune content rows the owner no longer references, then add the missing ones (idempotent).
        // Empty set (no block media) → the when() is skipped, so every content row is pruned.
        $rows()->when($mediaIds !== [], fn (Builder $query) => $query->whereNotIn('media_id', $mediaIds))->delete();

        if ($mediaIds === []) {
            return;
        }

        // What is left is all still referenced: one read, then a createOrFirst (BelongsToSite stamps
        // site_id; a concurrent save of the same row is absorbed) only for each media new to the
        // owner, so an unchanged save costs no write.
        $recorded = [];
        foreach ($rows()->pluck('media_id') as $id) {
            $recorded[] = Cast::int($id);
        }

        foreach (array_diff($mediaIds, $recorded) as $id) {
            Mediable::query()->createOrFirst([
                'media_id' => $id,
                'mediable_type' => $ownerType,
                'mediable_id' => $ownerId,
                'collection' => self::COLLECTION,
            ]);
        }
    }

    /**
     * Drop the content rows of owners that are gone. One query, site-scoped.
     *
     * @param  list<int>  $ownerIds
     */
    public function forget(string $ownerType, array $ownerIds): void
    {
        if ($ownerIds === []) {
            return;
        }

        Mediable::query()
            ->where('mediable_type', $ownerType)
            ->whereIn('mediable_id', $ownerIds)
            ->where('collection', self::COLLECTION)
            ->delete();
    }

    /**
     * The ids that name media of the current site — Trash included, so a restorable media keeps
     * its usage. Blocks are client-written, so they may hold any id: the foreign key on
     * mediables.media_id is checked without RLS, so another site's id would be recorded while an
     * unknown one fails the insert, telling the writer which ids exist elsewhere. Both are
     * skipped alike. One query (RLS + the site scope).
     *
     * @param  list<int>  $ids
     * @param  list<string>  $keys  storage keys (a button's file)
     * @return list<int>
     */
    private function visibleMediaIds(array $ids, array $keys): array
    {
        if ($ids === [] && $keys === []) {
            return [];
        }

        $visible = [];
        $found = Media::withTrashed()
            ->where(fn (Builder $query) => $query->whereIn('id', $ids)->orWhereIn('storage_key', $keys))
            ->pluck('id');
        foreach ($found as $id) {
            $visible[] = Cast::int($id);
        }

        return array_values(array_unique($visible));
    }

    /**
     * Every media id the block lists reference: by an image or video prop, and by a library
     * picture inside an html prop — so a picture used only inside formatted text still counts as
     * used (the library's "where used" list and its delete warning) — and the storage keys of the
     * files their buttons offer.
     *
     * @param  iterable<mixed>  $blockLists
     * @return array{0: list<int>, 1: list<string>} media ids, and the storage keys button files name
     */
    private function referenced(iterable $blockLists): array
    {
        $ids = [];
        $keys = [];
        foreach ($blockLists as $blocks) {
            if (! is_array($blocks)) {
                continue;
            }
            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $type = $block['type'] ?? null;
                $props = is_array($block['props'] ?? null) ? $block['props'] : [];
                if (is_string($type) && $this->registry->has($type)) {
                    $schema = $this->registry->schemaFor($type);
                    $ids = array_merge($ids, SchemaImages::ids($props, $schema));
                    foreach (SchemaHtml::values($props, $schema) as $html) {
                        $ids = array_merge($ids, RichHtml::imageIds($html));
                    }
                    $keys = array_merge($keys, ButtonLink::refs($props, $schema)['files']);
                }
            }
        }

        return [array_values(array_unique($ids)), array_values(array_unique($keys))];
    }
}
