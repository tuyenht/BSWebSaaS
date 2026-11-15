<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

/**
 * The one way the storefront decides that a link is "the page the visitor is on": both sides are reduced
 * to a plain path first. An address on the request's own host (https://host/x, //host/x) becomes /x; the
 * query string and the #fragment are dropped; a trailing slash is dropped except for the home page;
 * percent-encoding is compared decoded. An address on another host, a mailto:/tel: link, a bare # and
 * anything that is not a path give null, so they never match. Pure: no query, no request state.
 */
final class MenuPath
{
    /**
     * @param  string  $host  the request's host; only a link on this host counts as a path of this site
     */
    public static function normalize(string $url, string $host): ?string
    {
        $url = trim($url);
        if ($url === '' || $url[0] === '#') {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }
        if (isset($parts['scheme']) && ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }
        if (isset($parts['host']) && strtolower($parts['host']) !== strtolower($host)) {
            return null;
        }
        if (isset($parts['scheme']) && ! isset($parts['host'])) {
            return null;
        }
        $path = rawurldecode($parts['path'] ?? '');
        if ($path === '' || $path[0] !== '/') {
            return isset($parts['host']) ? '/' : null;
        }

        return $path === '/' ? '/' : (rtrim($path, '/') ?: '/');
    }

    /** Whether two addresses are the same page of this site. */
    public static function same(string $a, string $b, string $host): bool
    {
        $left = self::normalize($a, $host);

        return $left !== null && $left === self::normalize($b, $host);
    }
}
