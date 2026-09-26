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

    /** A theme region key: lower-case, e.g. primary, footer, mobile-bottom. */
    public const string REGION_PATTERN = '/\A[a-z][a-z0-9_-]{0,31}\z/';

    /** Computed links → the path they open. */
    public const array DYNAMIC = ['home' => '/'];

    /**
     * The link when it is safe to show, else null: site-relative paths and anchors, and absolute
     * links with http(s), mailto or tel — never javascript:, data: or another scheme.
     */
    public static function safeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return $url;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true) ? $url : null;
    }
}
