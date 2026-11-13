<?php

declare(strict_types=1);

namespace App\Modules\Cms\Queries;

use App\Modules\Cms\Models\BlockUsage;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\MenuItemTranslation;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Where the website's reusable blocks are used, read from the block_usages index within the current
 * site (BelongsToSite + RLS). A place is one owner (a page or post, a category's slot, the website's
 * default slot, a menu item's large panel): the languages of one place count once, and an owner
 * waiting in the Trash (a page or a menu item) does not count until it is restored.
 *
 *  - countsFor(): "Dùng ở N nơi" for a whole list page in one grouped read on
 *    (site_id, content_block_id).
 *  - placesOf(): the places of one block with their names in the website's default language (else
 *    any language they have), for "Nơi đang dùng khối", in a fixed number of reads whatever the
 *    number of places.
 */
final readonly class ContentBlockUsages
{
    public const int PLACES_MAX = 200;

    public function __construct(private SiteLocale $locales) {}

    /**
     * @param  list<int>  $blockIds
     * @return array<int, int> block id → places (blocks used nowhere are absent)
     */
    public function countsFor(array $blockIds): array
    {
        if ($blockIds === []) {
            return [];
        }

        $rows = $this->live(BlockUsage::query())
            ->whereIn('block_usages.content_block_id', $blockIds)
            ->groupBy('block_usages.content_block_id')
            ->select([
                'block_usages.content_block_id',
                DB::raw('count(distinct (block_usages.entry_id, block_usages.taxonomy_id, block_usages.site_setting_id, block_usages.menu_item_id, block_usages.slot)) as places'),
            ])
            ->toBase()
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[Cast::int($row->content_block_id)] = Cast::int($row->places);
        }

        return $out;
    }

    /**
     * @return list<array{kind: 'page'|'post'|'category'|'site'|'menu_item', ulid: string|null, name: string|null, menu: string|null, slot: string|null, locales: list<string>}>
     *                                                                                                                                                                           kind = what uses the block; ulid = that owner's public key (null for the website's default
     *                                                                                                                                                                           slot); name = its title, category name or menu item label (null when it has none); menu = the
     *                                                                                                                                                                           menu a menu item is in; slot = the slot, for a category or the website's default
     */
    public function placesOf(ContentBlock $block, int $limit = self::PLACES_MAX): array
    {
        $rows = $this->live(BlockUsage::query())
            ->where('block_usages.content_block_id', $block->id)
            ->orderBy('block_usages.id')
            ->limit(max(1, $limit) * 8)
            ->toBase()
            ->get(['block_usages.entry_id', 'block_usages.taxonomy_id', 'block_usages.site_setting_id', 'block_usages.menu_item_id', 'block_usages.slot', 'block_usages.locale']);

        /** @var array<string, array{entry: int|null, taxonomy: int|null, setting: int|null, item: int|null, slot: string|null, locales: list<string>}> $places */
        $places = [];
        foreach ($rows as $row) {
            $entry = $row->entry_id === null ? null : Cast::int($row->entry_id);
            $taxonomy = $row->taxonomy_id === null ? null : Cast::int($row->taxonomy_id);
            $setting = $row->site_setting_id === null ? null : Cast::int($row->site_setting_id);
            $item = $row->menu_item_id === null ? null : Cast::int($row->menu_item_id);
            $slot = Cast::stringOrNull($row->slot);
            $place = implode(':', [$entry, $taxonomy, $setting, $item, $slot]);
            if (! isset($places[$place])) {
                if (count($places) >= $limit) {
                    continue;
                }
                $places[$place] = ['entry' => $entry, 'taxonomy' => $taxonomy, 'setting' => $setting, 'item' => $item, 'slot' => $slot, 'locales' => []];
            }
            $locale = Cast::stringOrNull($row->locale);
            if ($locale !== null && ! in_array($locale, $places[$place]['locales'], true)) {
                $places[$place]['locales'][] = $locale;
            }
        }

        $entryIds = array_values(array_filter(array_column($places, 'entry'), is_int(...)));
        $taxonomyIds = array_values(array_filter(array_column($places, 'taxonomy'), is_int(...)));
        $itemIds = array_values(array_filter(array_column($places, 'item'), is_int(...)));

        $entries = $entryIds === [] ? [] : Entry::query()->whereIn('id', $entryIds)->get(['id', 'ulid', 'type'])->keyBy('id')->all();
        $entryNames = $entryIds === [] ? [] : $this->names(EntryTranslation::query()->whereIn('entry_id', $entryIds)->toBase()->get(['entry_id as owner', 'locale', 'title as name']));
        $taxonomies = $taxonomyIds === [] ? [] : Taxonomy::query()->whereIn('id', $taxonomyIds)->get(['id', 'ulid'])->keyBy('id')->all();
        $taxonomyNames = $taxonomyIds === [] ? [] : $this->names(TaxonomyTranslation::query()->whereIn('taxonomy_id', $taxonomyIds)->toBase()->get(['taxonomy_id as owner', 'locale', 'name']));
        $items = $itemIds === [] ? [] : MenuItem::query()->whereIn('id', $itemIds)->get(['id', 'ulid', 'menu_id'])->keyBy('id')->all();
        $itemNames = $itemIds === [] ? [] : $this->names(MenuItemTranslation::query()->whereIn('menu_item_id', $itemIds)->toBase()->get(['menu_item_id as owner', 'locale', 'label as name']));
        $menuIds = array_values(array_unique(array_map(static fn (MenuItem $item): int => $item->menu_id, $items)));
        $menus = $menuIds === [] ? [] : Menu::query()->whereIn('id', $menuIds)->pluck('name', 'id')->all();

        $out = [];
        foreach ($places as $place) {
            $locales = $place['locales'];
            sort($locales);
            if ($place['entry'] !== null) {
                $entry = $entries[$place['entry']] ?? null;
                $out[] = ['kind' => $entry?->type === 'post' ? 'post' : 'page', 'ulid' => $entry?->ulid, 'name' => $entryNames[$place['entry']] ?? null,
                    'menu' => null, 'slot' => null, 'locales' => $locales];
            } elseif ($place['taxonomy'] !== null) {
                $out[] = ['kind' => 'category', 'ulid' => ($taxonomies[$place['taxonomy']] ?? null)?->ulid, 'name' => $taxonomyNames[$place['taxonomy']] ?? null,
                    'menu' => null, 'slot' => $place['slot'], 'locales' => $locales];
            } elseif ($place['setting'] !== null) {
                $out[] = ['kind' => 'site', 'ulid' => null, 'name' => null, 'menu' => null, 'slot' => $place['slot'], 'locales' => $locales];
            } elseif ($place['item'] !== null) {
                $item = $items[$place['item']] ?? null;
                $out[] = ['kind' => 'menu_item', 'ulid' => $item?->ulid, 'name' => $itemNames[$place['item']] ?? null,
                    'menu' => $item === null ? null : Cast::stringOrNull($menus[$item->menu_id] ?? null), 'slot' => null, 'locales' => []];
            }
        }

        return $out;
    }

    /**
     * Usage rows whose owner is live: a page or a menu item in the Trash does not count.
     *
     * @param  Builder<BlockUsage>  $query
     * @return Builder<BlockUsage>
     */
    private function live(Builder $query): Builder
    {
        return $query
            ->leftJoin('entries', 'entries.id', '=', 'block_usages.entry_id')
            ->leftJoin('menu_items', 'menu_items.id', '=', 'block_usages.menu_item_id')
            ->whereNull('entries.deleted_at')
            ->whereNull('menu_items.deleted_at');
    }

    /**
     * Each owner's name in the website's default language, else its first language that has one.
     *
     * @param  iterable<object>  $rows  owner, locale, name
     * @return array<int, string>
     */
    private function names(iterable $rows): array
    {
        $default = $this->locales->default();
        $best = [];
        foreach ($rows as $row) {
            $owner = Cast::int($row->owner ?? null);
            $name = trim(Cast::string($row->name ?? null));
            if ($name === '') {
                continue;
            }
            $locale = Cast::string($row->locale ?? null);
            if (! isset($best[$owner]) || $locale === $default || ($best[$owner][0] !== $default && strcmp($locale, $best[$owner][0]) < 0)) {
                $best[$owner] = [$locale, $name];
            }
        }

        return array_map(static fn (array $pick): string => $pick[1], $best);
    }
}
