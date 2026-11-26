<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\BlockUsage;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Theme\Components\BlockReference;
use App\Modules\Theme\Rendering\LayoutSlots;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The one writer of `block_usages`, where the website's shared blocks are placed: a page or post
 * body (per language), a category's own slot or the website's default slot (per slot and language),
 * a menu item's large panel (`meta.mega = {type: block, block: <ulid>}`, no language). Each sync
 * rewrites the rows of the owners it is handed from what they hold now: the owners' rows go, then
 * one row per (block, place) comes back for every ulid that names one of this website's blocks
 * (one site-scoped lookup, BelongsToSite + RLS; a ulid of another website or one never issued is
 * never written). A draft block counts: it is placed, it shows once published.
 *
 * Runs inside the save that changed the owner (its transaction), so the index never drifts from the
 * content; blocks:reconcile-usage rebuilds it for what never passed through a save. The owner keys
 * are single-column foreign keys, which the database checks without RLS, so an owner is taken only
 * as a model read in the current website's context, and one of another website is refused (a
 * LogicException: the save rolls back) rather than linked. An owner in the Trash keeps its rows
 * (ContentBlockUsages leaves it out); one deleted for good takes them along (CASCADE).
 *
 * Holds no state (Octane-safe).
 */
final readonly class BlockUsageIndex
{
    public function __construct(private CurrentSite $site) {}

    /**
     * @param  array<array-key, mixed>  $bodies  locale → the body's blocks in that language
     */
    public function syncEntry(Entry $entry, array $bodies): void
    {
        $this->own($entry);
        $places = [];
        foreach ($bodies as $locale => $blocks) {
            $places[] = ['owner' => $entry->id, 'locale' => (string) $locale, 'slot' => null, 'ulids' => BlockReference::ulids(Cast::arr($blocks))];
        }

        $this->write('entry_id', [$entry->id], $places);
    }

    /**
     * @param  mixed  $slots  the category's own slot map (`meta.layout_blocks`: slot → NONE | {locale: {"blocks": […]}})
     */
    public function syncTaxonomy(Taxonomy $taxonomy, mixed $slots): void
    {
        $this->own($taxonomy);
        $this->write('taxonomy_id', [$taxonomy->id], $this->slotPlaces($taxonomy->id, $slots));
    }

    /**
     * @param  Model  $setting  the website's `storefront.layout_blocks` settings row (a Tenancy model,
     *                          which Cms may not name: module boundaries)
     * @param  mixed  $slots  the website's default slot map (that row's value)
     */
    public function syncSiteSetting(Model $setting, mixed $slots): void
    {
        $this->own($setting);
        if ($setting->getTable() !== 'site_settings'
            || $setting->getAttribute('group_name') !== LayoutSlots::SETTING_GROUP
            || $setting->getAttribute('key') !== LayoutSlots::SETTING_KEY) {
            throw new LogicException('Only the website\'s block slots setting places shared blocks.');
        }
        $id = Cast::int($setting->getKey());
        $this->write('site_setting_id', [$id], $this->slotPlaces($id, $slots));
    }

    /**
     * The large panels of these menu items, from their `meta` as handed (read in this save).
     *
     * @param  iterable<MenuItem>  $items
     */
    public function syncMenuItems(iterable $items): void
    {
        $ids = [];
        $places = [];
        foreach ($items as $item) {
            $this->own($item);
            $ids[] = $item->id;
            $ulid = self::panelBlock($item->meta);
            if ($ulid !== null) {
                $places[] = ['owner' => $item->id, 'locale' => null, 'slot' => null, 'ulids' => [$ulid]];
            }
        }

        $this->write('menu_item_id', $ids, $places);
    }

    /** The ulid of the shared block a menu item's large panel shows, upper-cased, or null. */
    public static function panelBlock(mixed $meta): ?string
    {
        $mega = is_array($meta) ? ($meta['mega'] ?? null) : null;
        if (! is_array($mega) || ($mega['type'] ?? null) !== 'block') {
            return null;
        }

        return BlockReference::ulid(['type' => BlockReference::TYPE, 'props' => [BlockReference::PROP => $mega['block'] ?? null]]);
    }

    /**
     * @return list<array{owner: int, locale: string, slot: string, ulids: list<string>}>
     */
    private function slotPlaces(int $owner, mixed $slots): array
    {
        $places = [];
        foreach (LayoutSlots::placedLists($slots) as $list) {
            $places[] = ['owner' => $owner, 'locale' => $list['locale'], 'slot' => $list['slot'], 'ulids' => BlockReference::ulids($list['blocks'])];
        }

        return $places;
    }

    /**
     * Replace the rows of these owners (one delete) with one row per block and place (one lookup and
     * one insert, only when anything is placed).
     *
     * @param  'entry_id'|'taxonomy_id'|'site_setting_id'|'menu_item_id'  $column
     * @param  list<int>  $owners
     * @param  list<array{owner: int, locale: string|null, slot: string|null, ulids: list<string>}>  $places
     */
    private function write(string $column, array $owners, array $places): void
    {
        if ($owners === []) {
            return;
        }
        BlockUsage::query()->whereIn($column, $owners)->delete();

        $ulids = array_values(array_unique(array_merge([], ...array_column($places, 'ulids'))));
        if ($ulids === []) {
            return;
        }
        $known = [];
        foreach (ContentBlock::query()->whereIn('ulid', $ulids)->get(['id', 'ulid']) as $block) {
            $known[$block->ulid] = $block->id;
        }

        $rows = [];
        $siteId = $this->site->id();
        $now = now();
        foreach ($places as $place) {
            foreach ($place['ulids'] as $ulid) {
                if (! isset($known[$ulid])) {
                    continue;
                }
                $row = [
                    'site_id' => $siteId,
                    'content_block_id' => $known[$ulid],
                    'locale' => $place['locale'],
                    'entry_id' => null,
                    'taxonomy_id' => null,
                    'site_setting_id' => null,
                    'menu_item_id' => null,
                    'slot' => $place['slot'],
                    'created_at' => $now,
                ];
                $row[$column] = $place['owner'];
                $rows[] = $row;
            }
        }
        if ($rows !== []) {
            BlockUsage::query()->insert($rows);
        }
    }

    /** An owner must be a row of the current website, read in its context. */
    private function own(Model $owner): void
    {
        if (! $this->site->hasId() || Cast::int($owner->getAttribute('site_id')) !== $this->site->id()) {
            throw new LogicException('A shared block can only be recorded for a place of the current website.');
        }
    }
}
