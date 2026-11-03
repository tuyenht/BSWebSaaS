<?php

declare(strict_types=1);

namespace App\Modules\Theme\Chrome;

use App\Modules\Shared\Support\SafeLink;

/**
 * The storefront header and footer settings (`storefront.header`, `storefront.footer`) turned
 * into the plain shape the theme prints: each text picked in the page's language (falling back
 * to the site's default language), trimmed and capped, every link validated (SafeLink), unknown
 * keys dropped. Views never read the raw JSON. A setting that is absent stays null, and the theme
 * then renders the classic header or footer unchanged. Pure: no query, no request state.
 */
final class StorefrontChrome
{
    /** Top-bar items, in the order shown. */
    public const int MAX_TOP_BAR_ITEMS = 4;

    public const int MAX_TOP_BAR_TEXT = 120;

    public const int MAX_FACTORIES = 6;

    public const int MAX_FOOTER_TEXT = 300;

    /** Breakpoints an item may appear from (xs = always). */
    private const array BREAKPOINTS = ['xs', 'sm', 'md', 'lg'];

    private const array TOP_BAR_KINDS = ['address', 'phone', 'email', 'link'];

    /** Social networks the footer has an icon for → their display name. Others are not shown. */
    public const array NETWORKS = [
        'facebook' => 'Facebook',
        'linkedin' => 'LinkedIn',
        'youtube' => 'YouTube',
        'zalo' => 'Zalo',
        'tiktok' => 'TikTok',
        'x' => 'X',
    ];

    /** A theme region key (the same shape menus are bound to). */
    private const string REGION_PATTERN = '/\A[a-z][a-z0-9_-]{0,31}\z/';

    /**
     * @param  mixed  $raw  the stored `storefront.header` value (an array when set)
     * @return array{top_bar: list<array{kind: string, text: string, href: string|null, from: string, below: string|null}>, text_size: bool, sticky: bool}|null
     */
    public static function header(mixed $raw, string $locale, string $default): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $items = [];
        $stored = is_array($raw['top_bar'] ?? null) && is_array($raw['top_bar']['items'] ?? null) ? $raw['top_bar']['items'] : [];
        foreach ($stored as $item) {
            if (count($items) >= self::MAX_TOP_BAR_ITEMS) {
                break;
            }
            if (! is_array($item) || ! in_array($item['kind'] ?? null, self::TOP_BAR_KINDS, true)) {
                continue;
            }
            $text = self::text($item['text'] ?? null, $locale, $default, self::MAX_TOP_BAR_TEXT);
            if ($text === null) {
                continue;
            }

            $kind = (string) $item['kind'];
            $href = match ($kind) {
                'phone' => SafeLink::tel($item['value'] ?? null),
                'email' => SafeLink::mailto($item['value'] ?? null),
                'link' => SafeLink::href($item['url'] ?? null),
                default => null,
            };
            if ($kind === 'link' && $href === null) {
                continue;   // a link item without a safe link is not shown at all
            }

            $from = in_array($item['from'] ?? null, self::BREAKPOINTS, true) ? (string) $item['from'] : 'xs';
            $below = in_array($item['phone_only_below'] ?? null, ['sm', 'md', 'lg'], true) ? (string) $item['phone_only_below'] : null;

            $items[] = ['kind' => $kind, 'text' => $text, 'href' => $href, 'from' => $from, 'below' => $below];
        }

        return [
            'top_bar' => $items,
            'text_size' => ($raw['text_size'] ?? false) === true,
            'sticky' => ($raw['sticky'] ?? true) !== false,
        ];
    }

    /**
     * @param  mixed  $raw  the stored `storefront.footer` value (an array when set)
     * @param  list<string>  $mapHosts  hosts a map link may point at (empty = any web host)
     * @return array{about: string|null, region: string, license_issuer: string|null, show_contact: bool, factories: list<array{title: string, area: int|null, name: string|null, address: string|null, map_url: string|null}>, start_year: int|null, holder: string|null, credit: array{label: string, url: string|null}|null}|null
     */
    public static function footer(mixed $raw, string $locale, string $default, array $mapHosts = []): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $legal = is_array($raw['legal'] ?? null) ? $raw['legal'] : [];
        $copyright = is_array($raw['copyright'] ?? null) ? $raw['copyright'] : [];
        $region = $raw['columns_menu_region'] ?? 'footer';

        $factories = [];
        foreach (is_array($raw['factories'] ?? null) ? $raw['factories'] : [] as $factory) {
            if (count($factories) >= self::MAX_FACTORIES) {
                break;
            }
            if (! is_array($factory)) {
                continue;
            }
            $title = self::text($factory['title'] ?? null, $locale, $default, self::MAX_FOOTER_TEXT);
            if ($title === null) {
                continue;
            }
            $area = $factory['area_m2'] ?? null;
            $factories[] = [
                'title' => $title,
                'area' => is_int($area) && $area >= 1 && $area <= 10_000_000 ? $area : null,
                'name' => self::text($factory['name'] ?? null, $locale, $default, self::MAX_FOOTER_TEXT),
                'address' => self::text($factory['address'] ?? null, $locale, $default, self::MAX_FOOTER_TEXT),
                'map_url' => self::external($factory['map_url'] ?? null, $mapHosts),
            ];
        }

        $credit = null;
        if (is_array($raw['credit'] ?? null)) {
            $label = self::text($raw['credit']['label'] ?? null, $locale, $default, self::MAX_FOOTER_TEXT);
            if ($label !== null) {
                $credit = ['label' => $label, 'url' => self::external($raw['credit']['url'] ?? null)];
            }
        }

        $startYear = $copyright['start_year'] ?? null;

        return [
            'about' => self::text($raw['about'] ?? null, $locale, $default, self::MAX_FOOTER_TEXT),
            'region' => is_string($region) && preg_match(self::REGION_PATTERN, $region) === 1 ? $region : 'footer',
            'license_issuer' => self::text($legal['license_issuer'] ?? null, $locale, $default, self::MAX_FOOTER_TEXT),
            'show_contact' => ($legal['show_contact'] ?? true) !== false,
            'factories' => $factories,
            'start_year' => is_int($startYear) && $startYear >= 1900 && $startYear <= 9999 ? $startYear : null,
            'holder' => self::text($copyright['holder'] ?? null, $locale, $default, self::MAX_FOOTER_TEXT),
            'credit' => $credit,
        ];
    }

    /**
     * The site's social profiles the footer can show: known networks with a safe web link, in the
     * order of NETWORKS.
     *
     * @return list<array{network: string, label: string, url: string}>
     */
    public static function socials(mixed $links): array
    {
        if (! is_array($links)) {
            return [];
        }

        $socials = [];
        foreach (self::NETWORKS as $network => $label) {
            $url = self::external($links[$network] ?? null);
            if ($url !== null) {
                $socials[] = ['network' => $network, 'label' => $label, 'url' => $url];
            }
        }

        return $socials;
    }

    /**
     * The language switch: one entry per public language the page exists in (from its hreflang
     * alternates), or none when there is only one.
     *
     * @param  list<array{hreflang: string, href: string}>  $alternates
     * @return list<array{locale: string, href: string, current: bool}>
     */
    public static function languages(array $alternates, string $current): array
    {
        $languages = [];
        foreach ($alternates as $alternate) {
            if ($alternate['hreflang'] !== 'x-default') {
                $languages[] = ['locale' => $alternate['hreflang'], 'href' => $alternate['href'], 'current' => $alternate['hreflang'] === $current];
            }
        }

        return count($languages) > 1 ? $languages : [];
    }

    /**
     * Whether a menu item's subtree holds the current page (the parent of the active page is
     * highlighted too).
     *
     * @param  array<mixed>  $item  a resolved menu node {label, url, active, children}
     */
    public static function inTrail(array $item): bool
    {
        foreach (is_array($item['children'] ?? null) ? $item['children'] : [] as $child) {
            if (is_array($child) && (! empty($child['active']) || self::inTrail($child))) {
                return true;
            }
        }

        return false;
    }

    /** Whether any item of a menu tree has children (it then needs the disclosure script). */
    public static function hasSubmenus(mixed $menu): bool
    {
        foreach (is_array($menu) ? $menu : [] as $item) {
            if (is_array($item) && ! empty($item['children'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The visitor's saved text size (Vừa / Lớn / Cực lớn), applied before the first paint. It is
     * inlined in the head so it costs no request, and the storefront CSP allows exactly this text
     * by its hash (see textSizeBootHash()), so no other inline script can run.
     */
    public const string TEXT_SIZE_BOOT = "try{var s=localStorage.getItem('sf-fs');if(s==='l'||s==='xl')document.documentElement.setAttribute('data-fs',s)}catch(e){}";

    /** The CSP source that allows TEXT_SIZE_BOOT and nothing else. */
    public static function textSizeBootHash(): string
    {
        return "'sha256-".base64_encode(hash('sha256', self::TEXT_SIZE_BOOT, true))."'";
    }

    /**
     * A text in the page's language: a plain string, or a {locale: text} map read in the page
     * language and then the site's default one. Trimmed; capped at $max characters; blank = null.
     */
    private static function text(mixed $value, string $locale, string $default, int $max): ?string
    {
        if (is_array($value)) {
            $value = is_string($value[$locale] ?? null) && trim($value[$locale]) !== '' ? $value[$locale] : ($value[$default] ?? null);
        }
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * An absolute web link (never a site path) that passes SafeLink.
     *
     * @param  list<string>  $hosts
     */
    private static function external(mixed $url, array $hosts = []): ?string
    {
        $href = SafeLink::href($url, $hosts);

        return $href !== null && ! str_starts_with($href, '/') ? $href : null;
    }
}
