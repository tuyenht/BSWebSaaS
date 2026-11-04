<?php

declare(strict_types=1);

namespace App\Modules\Theme\Theming;

/**
 * What the theme declares about its menu areas that the menu write path must respect: which area
 * may show another area's menu instead of one of its own ("Dùng trình đơn chung", design 11
 * `canSame`) — the phone menu may show the main menu. An area missing here can only hold its own
 * menu or none.
 */
final class MenuAreas
{
    /** @var array<string, string> area → the one area whose menu it may show */
    private const array SAME_AS = [
        'handheld' => 'primary',
    ];

    /** The area $area may follow, or null when the theme lets it follow none. */
    public static function canSame(string $area): ?string
    {
        return self::SAME_AS[$area] ?? null;
    }
}
