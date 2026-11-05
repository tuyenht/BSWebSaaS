<?php

declare(strict_types=1);

namespace App\Modules\Cms\Navigation;

use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\MenuItemTranslation;

/**
 * The version token of a menu as the screen saw it: a hash of its name, its own options and every
 * item still in it (place, target, options, labels). Whatever changes the menu — this screen in
 * another tab, an item restored from the trash, a page added on publish — changes the token, with
 * no writer having to remember to bump a counter. A draft saved against an older token is refused
 * (draft_stale). Built from rows the caller has already read, so it costs no query.
 */
final class MenuVersion
{
    /**
     * @param  iterable<MenuItem>  $items  the menu's live items
     * @param  iterable<MenuItemTranslation>  $labels  their labels
     */
    public static function of(Menu $menu, iterable $items, iterable $labels): string
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[$item->id] = [$item->ulid, $item->parent_id, $item->position, $item->link_type, $item->routable_type, $item->routable_id,
                $item->url, $item->dynamic_key, self::sorted($item->meta ?? []), []];
        }
        foreach ($labels as $label) {
            if (isset($rows[$label->menu_item_id])) {
                $rows[$label->menu_item_id][9][$label->locale] = $label->label;
            }
        }
        ksort($rows);
        foreach ($rows as &$row) {
            ksort($row[9]);
        }
        unset($row);

        return hash('xxh128', (string) json_encode([$menu->name, self::sorted($menu->meta ?? []), $rows]));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sorted(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $inner) {
            if (is_array($inner)) {
                $value[$key] = self::sorted($inner);
            }
        }

        return $value;
    }
}
