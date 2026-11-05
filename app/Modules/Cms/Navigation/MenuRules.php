<?php

declare(strict_types=1);

namespace App\Modules\Cms\Navigation;

/**
 * The menu rules the website (MenuResolver) and the write path (Cms\Actions\Menus) share, so what
 * an editor may save is exactly what a visitor can be shown.
 */
final class MenuRules
{
    /** Levels a menu tree may have, the top level included. */
    public const int MAX_DEPTH = 5;

    /**
     * A theme region key: lower-case, e.g. primary, footer, mobile-bottom. It caps both
     * menu_assignments.region_key and same_as_region (the area followed), so neither can hold a
     * value the other cannot.
     */
    public const string REGION_PATTERN = '/\A[a-z][a-z0-9_-]{0,31}\z/';

    /** Days a removed menu item waits in the trash before it is deleted for good. */
    public const int TRASH_DAYS = 30;

    /** Live items one menu may hold: far above any real menu, low enough that one draft stays one quick save. */
    public const int ITEMS_MAX = 2000;

    /**
     * Special links ("Liên kết đặc biệt", link_type 'dynamic') → the fixed site path they open, or
     * null when {@see SpecialLinks} works the address out from what the site has (its phone, email,
     * address, or a page a module provides). 'group' is a label that goes nowhere ('#').
     */
    public const array DYNAMIC = [
        'home' => '/',
        'search' => null,
        'cart' => null,
        'account' => null,
        'login' => null,
        'call' => null,
        'zalo' => null,
        'mail' => null,
        'map' => null,
        'sitemap' => null,
        'group' => '#',
    ];

    /**
     * The link when it is safe to show, else null: site-relative paths and anchors, and absolute
     * links with http(s), mailto or tel — never javascript:, data: or another scheme. A path that
     * starts with `//` or `/\` opens another host in the browser, so it is not site-relative; a tab
     * or line break anywhere is refused too, since browsers drop them (`/<tab>/host` is `//host`).
     */
    public static function safeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }
        if (str_starts_with($url, '//') || str_starts_with($url, '/\\')) {
            return null;
        }
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return $url;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true) ? $url : null;
    }
}
