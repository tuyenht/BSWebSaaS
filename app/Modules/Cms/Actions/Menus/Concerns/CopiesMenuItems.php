<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Menus\Concerns;

use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\MenuItemTranslation;
use Illuminate\Support\Collection;

/**
 * Copy menu items (live ones only) with their labels into a menu, keeping their shape: each copy
 * gets a new ulid, its parent is the copy of its parent. Callers run inside a transaction.
 * With $markRoots, each label a root copy has gets " (bản sao)" in that label's language, so the
 * two rows can be told apart on the screen; an empty label keeps following the source's title.
 */
trait CopiesMenuItems
{
    /**
     * @param  Collection<int, MenuItem>  $items  the items to copy, parents among them before or after children
     * @param  array<int, int|null>  $rootParents  original root id → the new parent id of its copy
     * @param  array<int, int>  $rootPositions  original root id → the position of its copy
     * @return array<int, int> original id → copy id
     */
    private function copyItems(int $menuId, Collection $items, array $rootParents, array $rootPositions, bool $markRoots = false): array
    {
        /** @var array<int, MenuItem> $byId */
        $byId = [];
        /** @var array<int, list<int>> $childrenOf parent id (0: top level) → child ids */
        $childrenOf = [];
        foreach ($items as $item) {
            $byId[$item->id] = $item;
            $childrenOf[$item->parent_id ?? 0][] = $item->id;
        }
        /** @var array<int, int> $copies */
        $copies = [];
        $queue = array_map('intval', array_keys($rootParents));

        while ($queue !== []) {
            $id = array_shift($queue);
            $original = $byId[$id] ?? null;
            if ($original === null) {
                continue;
            }
            $parent = array_key_exists($id, $rootParents) ? $rootParents[$id] : ($original->parent_id === null ? null : ($copies[$original->parent_id] ?? null));
            $copy = new MenuItem([
                'menu_id' => $menuId,
                'parent_id' => $parent,
                'position' => $rootPositions[$id] ?? $original->position,
                'link_type' => $original->link_type,
                'routable_type' => $original->routable_type,
                'routable_id' => $original->routable_id,
                'url' => $original->url,
                'dynamic_key' => $original->dynamic_key,
                'meta' => $original->meta ?? [],
            ]);
            $copy->save();
            $copies[$id] = $copy->id;
            foreach ($childrenOf[$id] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        $labels = MenuItemTranslation::query()->whereIn('menu_item_id', array_keys($copies))->get(['site_id', 'menu_item_id', 'locale', 'label']);
        $rows = $labels->map(fn (MenuItemTranslation $label): array => [
            'site_id' => $label->site_id, 'menu_item_id' => $copies[$label->menu_item_id], 'locale' => $label->locale,
            'label' => $markRoots && array_key_exists($label->menu_item_id, $rootParents) ? self::copyLabel($label->label, $label->locale) : $label->label,
            'created_at' => now(), 'updated_at' => now(),
        ])->all();
        foreach (array_chunk($rows, 500) as $chunk) {
            MenuItemTranslation::query()->insert($chunk);
        }

        return $copies;
    }

    /** "<label> (bản sao)" in the label's language, the label shortened (by letters) to fit 255. */
    private static function copyLabel(string $label, string $locale): string
    {
        $marked = __('menus.copy_name', ['name' => $label], $locale);
        $over = mb_strlen($marked) - 255;
        if ($over > 0) {
            $marked = __('menus.copy_name', ['name' => mb_substr($label, 0, max(0, mb_strlen($label) - $over))], $locale);
        }

        return $marked;
    }
}
