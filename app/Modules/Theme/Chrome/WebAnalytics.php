<?php

declare(strict_types=1);

namespace App\Modules\Theme\Chrome;

/**
 * The site's own Cloudflare Web Analytics token (site_settings storefront / web_analytics, shaped
 * {"cloudflare_token": "<32 lowercase hex>"}). With one, every storefront page closes with
 * Cloudflare's manual beacon snippet, which reports to cloudflareinsights.com itself rather than to
 * the zone's /cdn-cgi/rum (which a custom domain may not serve). Only the token is ever stored, never
 * markup, and it is checked again when the page is built, so a hand-edited row cannot put anything
 * but a plain token into a page. The StorefrontCsp already allows the beacon's script and its
 * reporting origin.
 */
final class WebAnalytics
{
    public const string TOKEN_PATTERN = '/\A[0-9a-f]{32}\z/';

    public const string BEACON_SRC = 'https://static.cloudflareinsights.com/beacon.min.js';

    /** @phpstan-assert-if-true string $token */
    public static function valid(mixed $token): bool
    {
        return is_string($token) && preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    /** The token a page reports with, or null when the stored setting holds none that is well-formed. */
    public static function token(mixed $stored): ?string
    {
        $token = is_array($stored) ? ($stored['cloudflare_token'] ?? null) : null;

        return self::valid($token) ? $token : null;
    }

    /**
     * The value of the snippet's data-cf-beacon attribute, in Cloudflare's own shape. The token is
     * JSON-encoded with quotes, apostrophes, tags and ampersands escaped, so the value is safe inside
     * the single-quoted attribute even if the pattern above were ever loosened.
     */
    public static function beaconConfig(string $token): string
    {
        return '{"token": '.json_encode($token, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR).'}';
    }
}
