<?php

declare(strict_types=1);

namespace App\Modules\Theme\Theming;

/**
 * The menu areas the default theme actually draws, as the theme declares them (design 11
 * "theme.json → menu_areas"): a menu is bound to an area per language, and these entries tell the
 * menu screen and the write path what each area is and what it can draw.
 *
 *  - primary: the menu beside the logo (the desktop menu of the rich header, the classic header's
 *    menu and the classic footer's link list);
 *  - handheld: the panel behind the menu button below the laptop width. With no menu of its own
 *    it shows the main menu, so a site that never sets it up sees no change;
 *  - footer: the rich footer's link columns (the footer settings name that region; "footer" is
 *    what they name by default).
 *
 * Each entry: `group`, `label` and `help` are translation keys; `icon` an admin icon name (Remix,
 * as the design names it); `depth` the levels the area draws (the strictest area a menu is bound to sets the menu's limit; an
 * unbound menu gets FALLBACK_DEPTH); `mega` whether a top-level item may open as a large
 * multi-column panel there; `canSame` the one area whose menu it may show instead of its own
 * ("Dùng trình đơn chung"); `advanced` whether the screen tucks it under "more areas".
 */
final class MenuAreas
{
    /** Levels allowed for a menu bound to no declared area. The backend cap stays MenuRules::MAX_DEPTH. */
    public const int FALLBACK_DEPTH = 3;

    /**
     * @var array<string, array{group: string, label: string, help: string, icon: string, depth: int, mega: bool, canSame: string|null, advanced: bool}>
     */
    private const array AREAS = [
        'primary' => [
            'group' => 'menus.area_group_header', 'label' => 'menus.area_primary', 'help' => 'menus.area_primary_help',
            'icon' => 'ri-layout-top', 'depth' => 3, 'mega' => true, 'canSame' => null, 'advanced' => false,
        ],
        'handheld' => [
            'group' => 'menus.area_group_header', 'label' => 'menus.area_handheld', 'help' => 'menus.area_handheld_help',
            'icon' => 'ri-smartphone', 'depth' => 4, 'mega' => false, 'canSame' => 'primary', 'advanced' => false,
        ],
        'footer' => [
            'group' => 'menus.area_group_footer', 'label' => 'menus.area_footer', 'help' => 'menus.area_footer_help',
            'icon' => 'ri-layout-bottom', 'depth' => 2, 'mega' => false, 'canSame' => null, 'advanced' => false,
        ],
    ];

    /**
     * Every declared area, in the order the screen lists them.
     *
     * @return array<string, array{group: string, label: string, help: string, icon: string, depth: int, mega: bool, canSame: string|null, advanced: bool}>
     */
    public static function all(): array
    {
        return self::AREAS;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::AREAS);
    }

    public static function has(string $area): bool
    {
        return isset(self::AREAS[$area]);
    }

    /** The area $area may follow, or null when the theme lets it follow none. */
    public static function canSame(string $area): ?string
    {
        return self::AREAS[$area]['canSame'] ?? null;
    }

    /** Levels the area draws; null for an area the theme does not declare. */
    public static function depth(string $area): ?int
    {
        return self::AREAS[$area]['depth'] ?? null;
    }

    public static function mega(string $area): bool
    {
        return self::AREAS[$area]['mega'] ?? false;
    }
}
