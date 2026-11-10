<?php

declare(strict_types=1);

namespace App\Modules\Theme\Chrome;

use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\SafeLink;
use App\Modules\Tenancy\Support\BusinessHours;
use Illuminate\Support\HtmlString;

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

    /** The branches and factories a site may list (the footer shows the first MAX_FACTORIES). */
    public const int MAX_BRANCHES = 12;

    public const int MAX_MAP_QUERY = 200;

    /** A branch's key: a short slug, unique in the list. */
    public const string BRANCH_KEY_PATTERN = '/\A[a-z0-9][a-z0-9-]{0,39}\z/';

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

    /** Pixels per label character (0.875rem semibold, measured 6.3–8.3, so on the wide side). */
    private const float MENU_CHAR = 7.5;

    /** An item's side padding (2 × 0.75rem). */
    private const int MENU_ITEM = 26;

    /** The split chevron button beside a parent's link (4px + 16px icon + 0.75rem). */
    private const int MENU_CHEVRON = 34;

    /** A 16px icon and its gap: the chevron of a heading-only parent, or an item's own icon. */
    private const int MENU_ICON = 21;

    /** A badge ("Mới", "Hot"…): its gap, padding and a short word. */
    private const int MENU_BADGE = 46;

    /** The gap between two items (0.25rem). */
    private const int MENU_GAP = 4;

    /** The menu's room in the bar at 1024px (see menuNeedsXl). */
    private const int MENU_ROOM_LG = 698;

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
     * The site's branches and factories with every field, for a branch map or card list: the same
     * `storefront.footer.factories` list the footer reads (the footer keeps its own shorter shape and
     * its first MAX_FACTORIES), up to MAX_BRANCHES, an item without a title left out as the footer
     * does. Each value is read defensively, as footer() does: texts in the page's language, a map
     * link that passes SafeLink, a key that is a slug and not taken by an earlier item, a map search
     * text of at most MAX_MAP_QUERY characters, coordinates only as an in-range pair, the picture as
     * the library id the settings check stored (resolved by the caller, in one query for the list).
     * Pure: no query.
     *
     * @param  mixed  $raw  the stored `storefront.footer` value (an array when set)
     * @param  list<string>  $mapHosts  hosts a map link may point at (empty = any web host)
     * @return list<array{key: string|null, title: string, full_name: string|null, locality: string|null, area: int|null, name: string|null, address: string|null, map_url: string|null, map_query: string|null, lat: float|null, lng: float|null, approximate: bool, image_media_id: int|null}>
     */
    public static function branches(mixed $raw, string $locale, string $default, array $mapHosts = []): array
    {
        $stored = is_array($raw) && is_array($raw['factories'] ?? null) ? $raw['factories'] : [];

        $branches = [];
        $keys = [];
        foreach ($stored as $item) {
            if (count($branches) >= self::MAX_BRANCHES) {
                break;
            }
            if (! is_array($item)) {
                continue;
            }
            $title = self::text($item['title'] ?? null, $locale, $default, self::MAX_FOOTER_TEXT);
            if ($title === null) {
                continue;
            }

            $key = $item['key'] ?? null;
            $key = is_string($key) && preg_match(self::BRANCH_KEY_PATTERN, $key) === 1 && ! isset($keys[$key]) ? $key : null;
            if ($key !== null) {
                $keys[$key] = true;
            }
            $area = $item['area_m2'] ?? null;
            $query = $item['map_query'] ?? null;
            $query = is_string($query) && trim($query) !== '' && mb_strlen($query) <= self::MAX_MAP_QUERY && preg_match('/[\x00-\x1F\x7F]/u', $query) === 0 ? trim($query) : null;
            $lat = self::degrees($item['lat'] ?? null, 90);
            $lng = self::degrees($item['lng'] ?? null, 180);
            $image = $item['image_media_id'] ?? null;

            $branches[] = [
                'key' => $key,
                'title' => $title,
                'full_name' => self::text($item['full_name'] ?? null, $locale, $default, self::MAX_FOOTER_TEXT),
                'locality' => self::text($item['locality'] ?? null, $locale, $default, self::MAX_FOOTER_TEXT),
                'area' => is_int($area) && $area >= 1 && $area <= 10_000_000 ? $area : null,
                'name' => self::text($item['name'] ?? null, $locale, $default, self::MAX_FOOTER_TEXT),
                'address' => self::text($item['address'] ?? null, $locale, $default, self::MAX_FOOTER_TEXT),
                'map_url' => self::external($item['map_url'] ?? null, $mapHosts),
                'map_query' => $query,
                'lat' => $lat !== null && $lng !== null ? $lat : null,
                'lng' => $lat !== null && $lng !== null ? $lng : null,
                'approximate' => ($item['approximate'] ?? false) === true,
                'image_media_id' => is_int($image) && $image >= 1 ? $image : null,
            ];
        }

        return $branches;
    }

    /** A coordinate in degrees within ±$limit, as a float; null for anything else. */
    private static function degrees(mixed $value, int $limit): ?float
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }
        $value = (float) $value;

        return is_finite($value) && abs($value) <= $limit ? $value : null;
    }

    /**
     * The site's social profiles the footer can show: known networks with a safe web link, in the
     * order of NETWORKS. Zalo may be kept as a phone number or an Official Account id: it becomes
     * its zalo.me link.
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
            $url = $network === 'zalo' ? self::zalo($links[$network] ?? null) : self::external($links[$network] ?? null);
            if ($url !== null) {
                $socials[] = ['network' => $network, 'label' => $label, 'url' => $url];
            }
        }

        return $socials;
    }

    /**
     * The opening hours as one line in the page's language — "Thứ 2 – Thứ 6, 08:00 – 17:00",
     * "Theo lịch hẹn" — with the site's note after it; null when there is neither.
     */
    public static function hours(mixed $stored, mixed $note): ?string
    {
        $hours = BusinessHours::from($stored);
        $line = match (true) {
            $hours === null => null,
            $hours->byAppointment() => Cast::string(__('storefront.days.appointment')),
            default => Cast::string(__('storefront.hours_range', ['days' => Cast::string(__('storefront.days.'.$hours->days)), 'opens' => $hours->opens, 'closes' => $hours->closes])),
        };
        $note = is_string($note) && trim($note) !== '' ? trim($note) : null;

        return match (true) {
            $line !== null && $note !== null => Cast::string(__('storefront.hours_note', ['hours' => $line, 'note' => $note])),
            default => $line ?? $note,
        };
    }

    /**
     * A Zalo contact as its link: a Vietnamese mobile number or a zalo.me link (the floating
     * contact button's rule), or an Official Account id (digits, not a phone number) — null for
     * anything else.
     */
    public static function zalo(mixed $value): ?string
    {
        if (! is_string($value) || ($value = trim($value)) === '') {
            return null;
        }
        $clean = FloatingContact::clean('zalo', $value);
        if ($clean !== null) {
            return FloatingContact::href('zalo', $clean);
        }

        if (preg_match('~\Ahttps://(?:www\.)?zalo\.me/\S+\z~i', $value) === 1) {
            return self::external($value);   // a group or other zalo.me page
        }

        return preg_match('/\A[1-9]\d{5,19}\z/', $value) === 1 ? 'https://zalo.me/'.$value : null;
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
     * Whether a menu item is on the way to the current page: it links to a level above the page
     * (marked by withTrail), or something below it is the current page or such a level. The
     * parent of the active page is highlighted too.
     *
     * @param  array<mixed>  $item  a resolved menu node {label, url, active, trail?, children}
     */
    public static function inTrail(array $item): bool
    {
        if (! empty($item['trail'])) {
            return true;
        }
        foreach (is_array($item['children'] ?? null) ? $item['children'] : [] as $child) {
            if (is_array($child) && (! empty($child['active']) || self::inTrail($child))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The menus of a page with the items that link to a level above it marked (`trail`): the
     * levels of its breadcrumb between the home page and the page itself, so a post or product
     * marks the section it sits in ("Dự án" on a project, "Tin tức" on a news post) even when its
     * own address is not in the menu. The home link and the page itself are left out (the page is
     * already `active`). Pure: no query.
     *
     * @param  array<string, mixed>  $extra  the page's layout variables (menu, mobileMenu, breadcrumb)
     * @return array<string, mixed>
     */
    public static function withTrail(array $extra): array
    {
        $crumbs = is_array($extra['breadcrumb'] ?? null) ? array_values($extra['breadcrumb']) : [];
        $paths = [];
        foreach (array_slice($crumbs, 1, -1) as $crumb) {
            if (is_array($crumb) && is_string($crumb['href'] ?? null) && $crumb['href'] !== '') {
                $paths[$crumb['href']] = true;
            }
        }
        if ($paths === []) {
            return $extra;
        }
        foreach (['menu', 'mobileMenu'] as $key) {
            if (is_array($extra[$key] ?? null)) {
                $extra[$key] = self::markTrail($extra[$key], $paths);
            }
        }

        return $extra;
    }

    /**
     * @param  array<mixed>  $items
     * @param  array<string, true>  $paths
     * @return array<mixed>
     */
    private static function markTrail(array $items, array $paths): array
    {
        foreach ($items as $i => $item) {
            if (! is_array($item)) {
                continue;
            }
            if (is_string($item['url'] ?? null) && isset($paths[$item['url']]) && empty($item['active'])) {
                $item['trail'] = true;
            }
            if (is_array($item['children'] ?? null) && $item['children'] !== []) {
                $item['children'] = self::markTrail($item['children'], $paths);
            }
            $items[$i] = $item;
        }

        return $items;
    }

    /**
     * Whether the rich header's desktop menu is too wide for a 1024px laptop, so the header keeps
     * the menu button up to 1280px (`sf-header--menu-xl`); a shorter menu shows from 1024px.
     * Decided from the menu alone, before any browser measures it: the top-level items a laptop
     * shows, by their label length plus what each draws beside it (padding, chevron, icon, badge).
     * Calibrated on Chromium at the default text size: An Vinh's nine items (71 label characters,
     * one heading-only parent, two parents with a page) measure 837px and estimate 887px; the room
     * at 1024px is 698px (1024 less a 15px scroll bar, the 2rem side padding, a 200px logo, the
     * 1rem gap and the menu's 1.5rem offset).
     *
     * @param  mixed  $menu  the resolved desktop menu (a list of menu nodes)
     */
    public static function menuNeedsXl(mixed $menu): bool
    {
        $width = 0.0;
        $count = 0;
        foreach (is_array($menu) ? $menu : [] as $item) {
            // a phone-only row is hidden on a laptop; a leaf that links nowhere is not drawn
            if (! is_array($item) || ($item['device'] ?? 'all') === 'phone' || (($item['url'] ?? '#') === '#' && empty($item['children']))) {
                continue;
            }
            $count++;
            $width += self::MENU_CHAR * mb_strlen(is_string($item['label'] ?? null) ? $item['label'] : '') + self::MENU_ITEM;
            if (! empty($item['children'])) {
                $width += ($item['url'] ?? '#') === '#' ? self::MENU_ICON : self::MENU_CHEVRON;
            }
            if (is_string($item['icon'] ?? null)) {
                $width += self::MENU_ICON;
            }
            if (is_string($item['badge'] ?? null)) {
                $width += self::MENU_BADGE;
            }
        }

        return $width + self::MENU_GAP * max(0, $count - 1) > self::MENU_ROOM_LG;
    }

    /**
     * The classes a menu item adds to its list row: " sf-only-desk" / " sf-only-mob" for an item
     * shown only on a computer or only on a phone ("Hiện trên"), else nothing.
     *
     * @param  array<mixed>  $item  a resolved menu node
     */
    public static function menuItemClass(array $item): string
    {
        return match ($item['device'] ?? 'all') {
            'desktop' => ' sf-only-desk',
            'phone' => ' sf-only-mob',
            default => '',
        };
    }

    /**
     * One menu item's text, for every menu the theme draws: the optional icon (only names of the
     * website's own set reach a node), the label, a badge ("Mới", "Hot"…, which the resolver drops
     * once past its end date) and, where the area shows one ($withDesc), the short description.
     * Every value is escaped; the description is plain text. An item with none of these is its
     * bare label, exactly as before they existed.
     *
     * @param  array<mixed>  $item  a resolved menu node
     */
    public static function menuLabel(array $item, bool $withDesc = false): HtmlString
    {
        $label = e(is_string($item['label'] ?? null) ? $item['label'] : '');
        $icon = is_string($item['icon'] ?? null) && StorefrontIcons::has($item['icon']) ? $item['icon'] : null;
        $badge = is_string($item['badge'] ?? null) ? $item['badge'] : null;
        $desc = $withDesc && is_string($item['description'] ?? null) && $item['description'] !== '' ? $item['description'] : null;
        if ($icon === null && $badge === null && $desc === null) {
            return new HtmlString($label);
        }

        $html = $icon === null ? '' : trim(view('theme::partials.sf-icon', ['name' => $icon, 'size' => 16])->render());
        $html .= $desc === null
            ? '<span>'.$label.'</span>'
            : '<span class="sf-menu__text"><span>'.$label.'</span><span class="sf-menu__desc">'.e($desc).'</span></span>';
        if ($badge !== null) {
            $html .= '<span class="sf-menu__badge">'.e(__('storefront.menu_badge_'.$badge)).'</span>';
        }

        return new HtmlString($html);
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
