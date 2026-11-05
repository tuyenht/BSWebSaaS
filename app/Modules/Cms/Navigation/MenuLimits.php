<?php

declare(strict_types=1);

namespace App\Modules\Cms\Navigation;

use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Theme\Theming\MenuAreas;

/**
 * What a menu may hold, given the theme areas that show it (MenuAreas): its depth limit is the
 * strictest `depth` among those areas, and a top-level item may open as a large panel when at
 * least one of them can draw one. An area that follows an area showing the menu ("Dùng trình đơn
 * chung") shows it too. A menu no declared area shows gets MenuAreas::FALLBACK_DEPTH, and may keep
 * a large panel for the day it is bound. Never above MenuRules::MAX_DEPTH.
 */
final class MenuLimits
{
    /**
     * @return array{depth: int, area: string|null, mega: bool} area = the area that sets the depth (null: none)
     */
    public static function forMenu(int $menuId): array
    {
        $rows = MenuAssignment::query()
            ->where(fn ($query) => $query
                ->where('menu_id', $menuId)
                ->orWhereIn('same_as_region', MenuAssignment::query()->select('region_key')->where('menu_id', $menuId)))
            ->get(['region_key', 'locale', 'menu_id', 'same_as_region']);

        return self::fromAssignments($menuId, $rows->map(fn (MenuAssignment $row): array => [
            'region' => $row->region_key, 'locale' => $row->locale, 'menu' => $row->menu_id, 'follows' => $row->same_as_region,
        ])->all());
    }

    /**
     * The same, from assignment rows already read (the screen reads them all once).
     *
     * @param  iterable<array{region: string, locale: string, menu: int|null, follows: string|null}>  $assignments
     * @return array{depth: int, area: string|null, mega: bool}
     */
    public static function fromAssignments(int $menuId, iterable $assignments): array
    {
        return self::fromAreas(self::areasShowing($menuId, $assignments));
    }

    /**
     * The declared areas that show the menu in some language.
     *
     * @param  iterable<array{region: string, locale: string, menu: int|null, follows: string|null}>  $assignments
     * @return list<string>
     */
    public static function areasShowing(int $menuId, iterable $assignments): array
    {
        $own = [];
        $follows = [];
        foreach ($assignments as $row) {
            if ($row['menu'] === $menuId) {
                $own[$row['locale']][$row['region']] = true;
            } elseif ($row['follows'] !== null) {
                $follows[] = $row;
            }
        }
        $areas = [];
        foreach ($own as $regions) {
            foreach (array_keys($regions) as $region) {
                $areas[$region] = true;
            }
        }
        foreach ($follows as $row) {
            if (isset($own[$row['locale']][$row['follows']])) {
                $areas[$row['region']] = true;
            }
        }

        return array_values(array_filter(array_map('strval', array_keys($areas)), MenuAreas::has(...)));
    }

    /**
     * @param  list<string>  $areas
     * @return array{depth: int, area: string|null, mega: bool}
     */
    public static function fromAreas(array $areas): array
    {
        if ($areas === []) {
            return ['depth' => min(MenuAreas::FALLBACK_DEPTH, MenuRules::MAX_DEPTH), 'area' => null, 'mega' => true];
        }
        $depth = MenuRules::MAX_DEPTH;
        $by = null;
        $mega = false;
        foreach ($areas as $area) {
            $areaDepth = MenuAreas::depth($area) ?? MenuAreas::FALLBACK_DEPTH;
            if ($by === null || $areaDepth < $depth) {
                $depth = min($areaDepth, MenuRules::MAX_DEPTH);
                $by = $area;
            }
            $mega = $mega || MenuAreas::mega($area);
        }

        return ['depth' => $depth, 'area' => $by, 'mega' => $mega];
    }
}
