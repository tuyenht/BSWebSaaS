<?php

declare(strict_types=1);

namespace App\Modules\Theme\Chrome;

use App\Modules\Shared\Support\SafeLink;
use App\Modules\Tenancy\Support\ContactPhone;
use App\Modules\Theme\Theming\ReadableColor;

/**
 * The floating contact buttons (`storefront.floating_contact` site setting): the channels a site
 * offers, how each value is checked, the link it becomes, and the plain shape the theme prints.
 *
 * Every link is built here from a checked value — a phone number becomes `tel:`/`sms:` or a
 * zalo.me / wa.me link, a page name an m.me link, a web link passes SafeLink — so nothing typed in
 * the panel reaches an href unchecked, and a stored value that no longer passes is simply not
 * shown. The admin panel validates with the same functions. Plain links only: no third-party
 * script is loaded. Pure: no query, no request state.
 */
final class FloatingContact
{
    public const array THEMES = ['vertical', 'horizontal'];

    /**
     * How the buttons are laid out: combo = round buttons on a computer and a bottom bar on a phone
     * (up to 767 px); vertical = round buttons everywhere; horizontal = the bottom bar on phones only.
     */
    public const array LAYOUTS = ['combo', 'vertical', 'horizontal'];

    /** The bottom bar's tone: follow the page, always light or always dark. */
    public const array BAR_TONES = ['auto', 'light', 'dark'];

    /** Cells of the bottom bar, the "Mua hàng" and "Thêm" cells counted. */
    public const int BAR_CAP = 5;

    /** Channels that open from the "Thêm" sheet only, never as a cell of the bar. */
    public const array SHEET_ONLY = ['messenger', 'email'];

    /** Ready channels from which a phone folds round buttons into one "Liên hệ" button. */
    public const int DIAL_FROM = 4;

    public const array POSITIONS = ['right', 'left', 'bottom'];

    public const int MAX_OFFSET_X = 60;

    public const int MAX_OFFSET_Y = 120;

    public const int DEFAULT_OFFSET_X = 20;

    public const int DEFAULT_OFFSET_Y = 24;

    /** Most channels one site keeps (built-in and its own together). */
    public const int MAX_CHANNELS = 20;

    /** Longest channel name or hover label. */
    public const int MAX_LABEL = 40;

    /** Longest value (a link or an address). */
    public const int MAX_VALUE = 500;

    /** A channel of the site's own: `c` and a number. */
    public const string CUSTOM_KEY = '/\Ac[1-9]\d{0,5}\z/';

    /**
     * The built-in channels, in the order a new site lists them: value type, icon, brand colour;
     * `text` = a short word drawn in the round button instead of the icon; `pulse` = the ring that
     * draws the eye to the call button.
     *
     * @var array<string, array{type: string, icon: string, color: string, text?: string, pulse?: bool}>
     */
    public const array CHANNELS = [
        'phone' => ['type' => 'phone', 'icon' => 'ri-phone-line', 'color' => '#4caf50', 'pulse' => true],
        'zalo' => ['type' => 'zalo', 'icon' => 'ri-chat-smile-2-line', 'color' => '#0084ff', 'text' => 'Zalo'],
        'messenger' => ['type' => 'messenger', 'icon' => 'ri-messenger-line', 'color' => '#0078ff'],
        'email' => ['type' => 'email', 'icon' => 'ri-mail-line', 'color' => '#ff4b4b'],
        'sms' => ['type' => 'sms', 'icon' => 'ri-message-2-line', 'color' => '#e91e63', 'text' => 'SMS'],
        'whatsapp' => ['type' => 'whatsapp', 'icon' => 'ri-whatsapp-line', 'color' => '#25d366'],
        'telegram' => ['type' => 'telegram', 'icon' => 'ri-telegram-line', 'color' => '#229ed9'],
        'viber' => ['type' => 'viber', 'icon' => 'ri-chat-1-line', 'color' => '#7360f2'],
        'tiktok' => ['type' => 'url', 'icon' => 'ri-tiktok-line', 'color' => '#111111'],
        'youtube' => ['type' => 'url', 'icon' => 'ri-youtube-line', 'color' => '#ff0000'],
        'instagram' => ['type' => 'url', 'icon' => 'ri-instagram-line', 'color' => '#e1306c'],
        'maps' => ['type' => 'url', 'icon' => 'ri-map-pin-line', 'color' => '#ea4335'],
    ];

    /** Channels every site keeps in its list (they can be switched off, not removed). */
    public const array CORE = ['phone', 'zalo', 'messenger', 'email', 'sms'];

    /** Channels added from "Chọn kênh có sẵn". */
    public const array PRESETS = ['whatsapp', 'telegram', 'viber', 'tiktok', 'youtube', 'instagram', 'maps'];

    /** Channels the board-style phone bar (`phone_bar` of a round-button site) leaves out; the horizontal bar keeps every channel. */
    public const array BAR_HIDDEN = ['messenger', 'email'];

    /** Ink of a mark on a light fill (a dark slate); white stays the ink wherever white reads. */
    public const string DARK_INK = '#0f172a';

    /** Cells of the board-style phone bar, the "request a quote" cell counted. */
    public const int PHONE_BAR_MAX = 3;

    /** Longest label of the phone bar's own cell. */
    public const int MAX_CTA_LABEL = 40;

    /** Colour of a channel of the site's own when none is chosen: a fixed hue, never the admin brand. */
    public const string CUSTOM_COLOR = '#1e40af';

    /** Colour of the "Mua hàng" button when none (or a bad one) is stored. */
    public const string BUY_COLOR = '#ff7a46';

    /** Messenger's own colour band, used unless the site chose a colour for it. */
    public const string MESSENGER_FILL = 'linear-gradient(45deg,#0099FF,#A033FF 70%,#FF5280)';

    /** Fill of the "Thêm" cell. */
    public const string MORE_COLOR = '#475569';

    /** The website draws e-mail with the open envelope of the design (the panel keeps its own code). */
    private const array WIDGET_ICONS = ['email' => 'ri-mail-open-fill'];

    /**
     * The website's widget for a page in $locale: what the theme prints, every colour and link already
     * worked out; null when the setting is off or no channel passes. Each site keeps the meaning of what
     * it saved (layout(): an older horizontal or round-buttons-plus-phone-bar setting reads as combo),
     * and an older phone bar's own cell ("Yêu cầu báo giá") reads as one more channel of the site's own
     * at the end of the list (where older code drew it, and only while the list has room for it). Fills are the stored colours darkened under white marks (inkUnder);
     * Messenger keeps its colour band unless the site chose a colour. `link` names the kind of link
     * (for the click event: never the number or the address itself).
     *
     * @param  mixed  $raw  the stored value (an array when set)
     * @param  string|null  $shopHref  the site's product list, or null when it has none
     * @return array{layout: string, position: string, offset_x: int, offset_y: int, tone: string, track: bool, dial: bool, buttons: list<array{key: string, href: string, label: string|null, icon: string, zalo: bool, fill: string, tint: string, pulse: bool, link: string, tel: string|null}>, bar: list<array{key: string, href: string, label: string|null, icon: string, zalo: bool, fill: string, tint: string, pulse: bool, link: string, tel: string|null}>, sheet: list<array{key: string, href: string, label: string|null, icon: string, zalo: bool, fill: string, tint: string, pulse: bool, link: string, tel: string|null}>, more: bool, buy: array{href: string, fill: string, ink: string, icon: string}|null}|null
     */
    public static function widget(mixed $raw, string $locale, ?string $shopHref = null): ?array
    {
        if (! is_array($raw) || ($raw['enabled'] ?? false) !== true) {
            return null;
        }

        $layout = self::layout($raw);
        $items = [];
        foreach (array_slice(is_array($raw['channels'] ?? null) ? array_values($raw['channels']) : [], 0, self::MAX_CHANNELS) as $channel) {
            $item = is_array($channel) ? self::item($channel, $locale) : null;
            if ($item !== null && ! isset($items[$item['key']])) {
                $items[$item['key']] = $item;
            }
        }
        // The older phone bar's own cell, only where older code drew it (round buttons with the phone bar
        // on: a stale `phone_bar` under the bar theme never showed), and only while the list has room.
        $pb = $raw['phone_bar'] ?? null;
        $cta = self::storedTheme($raw) === 'vertical' && is_array($pb) && ($pb['enabled'] ?? false) === true
            && count($items) < self::MAX_CHANNELS ? self::cta($pb['cta'] ?? null, $locale) : null;
        $ctaHref = $cta === null ? null : self::clean('custom', $cta['href']);
        $ctaItem = $cta === null || $ctaHref === null ? null : self::item(['key' => 'cta', 'on' => true, 'value' => $ctaHref, 'name' => $cta['label']], $locale, 'custom');
        if ($ctaItem !== null) {
            $items['cta'] = $ctaItem;
        }
        $items = array_values($items);
        if ($items === []) {
            return null;
        }

        $buy = $shopHref !== null && self::wantsShop($raw) ? self::buy($raw['buy'], $shopHref, true) : null;
        $byKey = array_column($items, null, 'key');
        $slots = self::barSlots(array_column($items, 'key'), $buy !== null);

        return [
            'layout' => $layout,
            'position' => in_array($raw['position'] ?? null, self::POSITIONS, true) ? (string) $raw['position'] : 'right',
            'offset_x' => self::offset($raw['offset_x'] ?? null, self::MAX_OFFSET_X, self::DEFAULT_OFFSET_X),
            'offset_y' => self::offset($raw['offset_y'] ?? null, self::MAX_OFFSET_Y, self::DEFAULT_OFFSET_Y),
            // "auto" follows the theme's dark flag, which no theme has yet: light.
            'tone' => self::barTone($raw) === 'dark' ? 'dark' : 'light',
            'track' => self::track($raw),
            'dial' => self::dials($layout, self::dial($raw), count($items)),
            'buttons' => $items,
            'bar' => $layout === 'vertical' ? [] : self::pick($byKey, $slots['bar']),
            'sheet' => $layout === 'vertical' ? [] : self::pick($byKey, $slots['sheet']),
            'more' => $layout !== 'vertical' && $slots['more'],
            'buy' => $buy,
        ];
    }

    /**
     * The widget channels for the given keys, in that order.
     *
     * @template T of array<string, mixed>
     *
     * @param  array<string, T>  $byKey
     * @param  list<string>  $keys
     * @return list<T>
     */
    private static function pick(array $byKey, array $keys): array
    {
        $picked = [];
        foreach ($keys as $key) {
            $picked[] = $byKey[$key];
        }

        return $picked;
    }

    /**
     * One channel of the website's widget, or null when it is off, unknown or its value does not pass.
     *
     * @param  array<array-key, mixed>  $channel
     * @return array{key: string, href: string, label: string|null, icon: string, zalo: bool, fill: string, tint: string, pulse: bool, link: string, tel: string|null}|null
     */
    private static function item(array $channel, string $locale, ?string $type = null): ?array
    {
        $key = is_string($channel['key'] ?? null) ? $channel['key'] : '';
        $type ??= self::typeOf($key);
        if ($type === null || ($channel['on'] ?? false) !== true) {
            return null;
        }
        $clean = self::clean($type, $channel['value'] ?? null);
        $href = $clean === null ? null : self::href($type, $clean);
        if ($href === null) {
            return null;
        }

        $meta = self::CHANNELS[$key] ?? null;
        $own = self::color($channel['color'] ?? null);
        $color = $own ?? ($meta['color'] ?? self::CUSTOM_COLOR);
        $icon = self::WIDGET_ICONS[$key] ?? $meta['icon'] ?? (in_array($channel['icon'] ?? null, ContactIcons::CUSTOM, true) ? (string) $channel['icon'] : 'ri-global-line');

        $tips = is_array($channel['tips'] ?? null) ? $channel['tips'] : [];
        $label = self::label($tips[$locale] ?? null);
        if ($label === null && $meta === null) {
            $label = self::label($channel['name'] ?? null);
        }

        return [
            'key' => $key,
            'href' => $href,
            'label' => $label,
            'icon' => ContactIcons::solid($icon) ?? '',
            'zalo' => $key === 'zalo',
            'fill' => $key === 'messenger' && $own === null ? self::MESSENGER_FILL : ReadableColor::inkUnder($color),
            // The hue itself, for the soft glow under a disc of the bar and the call button's pulse.
            'tint' => $color,
            'pulse' => ($meta['pulse'] ?? false) === true,
            'link' => self::linkType($href),
            'tel' => $type === 'phone' ? self::spaced($clean) : null,
        ];
    }

    /** The kind of link, for the click event: tel, sms, mailto, app (viber://), web or page (a path on the site). */
    private static function linkType(string $href): string
    {
        return match (true) {
            str_starts_with($href, 'tel:') => 'tel',
            str_starts_with($href, 'sms:') => 'sms',
            str_starts_with($href, 'mailto:') => 'mailto',
            str_starts_with($href, '/') => 'page',
            str_starts_with($href, 'http') => 'web',
            default => 'app',
        };
    }

    /** A ten-digit number grouped 4 3 3 for reading (0912 345 678); any other as it is. */
    public static function spaced(string $number): string
    {
        $digits = (string) preg_replace('/\D/', '', $number);

        return strlen($digits) === 10 ? substr($digits, 0, 4).' '.substr($digits, 4, 3).' '.substr($digits, 7) : $number;
    }

    /** The stored value checked and tidied for its type, or null when it does not pass. */
    public static function clean(string $type, mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > self::MAX_VALUE) {
            return null;
        }

        return match ($type) {
            'phone', 'sms' => self::phone($value, true, true),
            'whatsapp', 'viber' => self::phone($value, false, true),
            'zalo' => preg_match('~\A(?:https?://)?(?:www\.)?zalo\.me/(\d{6,20})/?\z~i', $value, $m) === 1 ? $m[1] : self::mobile($value),
            'messenger' => self::messenger($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null,
            'telegram' => self::telegram($value),
            'url' => self::web($value),
            'custom' => self::custom($value),
            default => null,
        };
    }

    /** The link a checked value opens (the value as clean() returns it). */
    public static function href(string $type, string $clean): ?string
    {
        return match ($type) {
            'phone' => 'tel:'.$clean,
            'sms' => 'sms:'.$clean,
            'zalo' => 'https://zalo.me/'.ltrim($clean, '+'),
            'messenger' => 'https://m.me/'.$clean,
            'email' => 'mailto:'.$clean,
            'whatsapp' => 'https://wa.me/'.self::international($clean),
            'telegram' => 'https://t.me/'.$clean,
            'viber' => 'viber://chat?number=%2B'.self::international($clean),
            'url', 'custom' => $clean,
            default => null,
        };
    }

    /** The value type of a channel key (a built-in channel, or one of the site's own). */
    public static function typeOf(string $key): ?string
    {
        if (isset(self::CHANNELS[$key])) {
            return self::CHANNELS[$key]['type'];
        }

        return preg_match(self::CUSTOM_KEY, $key) === 1 ? 'custom' : null;
    }

    /**
     * The layout of a stored setting. A value saved before layouts existed is read from its theme and
     * phone-bar switch (today's horizontal = bar on phones + round buttons on a computer = combo); anything
     * else reads as the older default, vertical (what the form and stored() give a site that never saved).
     *
     * @param  mixed  $raw  the stored value (an array when set)
     * @return 'combo'|'vertical'|'horizontal'
     */
    public static function layout(mixed $raw): string
    {
        if (! is_array($raw)) {
            return 'vertical';
        }
        if (is_string($raw['layout'] ?? null) && in_array($raw['layout'], self::LAYOUTS, true)) {
            return $raw['layout'];
        }
        if (($raw['theme'] ?? null) === 'horizontal') {
            return 'combo';
        }
        $bar = $raw['phone_bar'] ?? null;

        return is_array($bar) && ($bar['enabled'] ?? false) === true ? 'combo' : 'vertical';
    }

    /**
     * The older `theme` word for a layout (what code from before layouts reads after a rollback).
     *
     * @return 'vertical'|'horizontal'
     */
    public static function legacyTheme(string $layout): string
    {
        return $layout === 'vertical' ? 'vertical' : 'horizontal';
    }

    /** Whether a phone folds many round buttons into one "Liên hệ" button (default on; only false turns it off). */
    public static function dial(mixed $raw): bool
    {
        return ! is_array($raw) || ($raw['dial'] ?? true) !== false;
    }

    /**
     * The bottom bar's tone (default auto).
     *
     * @return 'auto'|'light'|'dark'
     */
    public static function barTone(mixed $raw): string
    {
        $tone = is_array($raw) ? ($raw['bar_tone'] ?? null) : null;

        return is_string($tone) && in_array($tone, self::BAR_TONES, true) ? $tone : 'auto';
    }

    /** Whether a click on a button is counted (default on; only false turns it off). */
    public static function track(mixed $raw): bool
    {
        return ! is_array($raw) || ($raw['track'] ?? true) !== false;
    }

    /**
     * Which channels sit on the phone's bottom bar, which wait in the "Thêm" sheet, and whether that
     * cell shows: the quick channels (not SHEET_ONLY) fill the bar in saved order; one leftover joins
     * the bar when there is room, two or more (or a full bar) turn the last cell into "Thêm".
     *
     * @param  list<string>  $ready  keys of the channels that are on and have a value that passes, in saved order
     * @param  bool  $buy  whether the "Mua hàng" cell takes one place
     * @return array{bar: list<string>, sheet: list<string>, more: bool}
     */
    public static function barSlots(array $ready, bool $buy): array
    {
        $cap = self::BAR_CAP - ($buy ? 1 : 0);
        $quick = array_values(array_filter($ready, static fn (string $k): bool => ! in_array($k, self::SHEET_ONLY, true)));
        $left = array_values(array_diff($ready, array_slice($quick, 0, $cap)));

        if ($left === []) {
            return ['bar' => $quick, 'sheet' => [], 'more' => false];
        }
        if (count($left) === 1 && count($quick) < $cap) {
            return ['bar' => $ready, 'sheet' => [], 'more' => false];
        }

        $bar = array_slice($quick, 0, $cap - 1);

        return ['bar' => $bar, 'sheet' => array_values(array_diff($ready, $bar)), 'more' => true];
    }

    /** Whether a phone folds the round buttons into one "Liên hệ" button: vertical layout, switch on, enough ready channels. */
    public static function dials(string $layout, bool $dial, int $ready): bool
    {
        return $layout === 'vertical' && $dial && $ready >= self::DIAL_FROM;
    }

    /** A colour as stored (#rrggbb, lower case), else null. */
    public static function color(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A#[0-9a-f]{6}\z/i', $value) === 1 ? strtolower($value) : null;
    }

    /**
     * Whether the stored setting would show the "Mua hàng" button (on, a layout with the bottom bar,
     * its switch on, and the bar theme stored, as older code required: a round-button site with the
     * phone bar never showed it, whatever its stale `buy` says) — the only case where the caller needs
     * to look up the site's product list.
     */
    public static function wantsShop(mixed $raw): bool
    {
        return is_array($raw) && ($raw['enabled'] ?? false) === true && self::storedTheme($raw) === 'horizontal'
            && self::layout($raw) !== 'vertical'
            && is_array($raw['buy'] ?? null) && ($raw['buy']['enabled'] ?? false) === true;
    }

    /**
     * The older `theme` word as stored (vertical when missing or unknown, as older code read it).
     *
     * @param  array<array-key, mixed>  $raw
     * @return 'vertical'|'horizontal'
     */
    private static function storedTheme(array $raw): string
    {
        return ($raw['theme'] ?? null) === 'horizontal' ? 'horizontal' : 'vertical';
    }

    /**
     * The setting in the shape the theme prints, for a page in $locale; null when it is off or
     * has no channel to show. `label` is the hover label in the page's language (null = the
     * channel's own default, which the theme words); custom channels fall back to their name.
     * `buy` is the "Mua hàng" button at the end of the phone bar: only when its switch is on and
     * the site has a product list to send people to ($shopHref); it then always keeps one of the first three places.
     *
     * @param  mixed  $raw  the stored value (an array when set)
     * @param  string|null  $shopHref  the site's product list, or null when it has none
     * @return array{theme: string, position: string, offset_x: int, offset_y: int, buttons: list<array{key: string, href: string, label: string|null, icon: string, text: string|null, fill: string, ink: string, bar_fill: string, bar_ink: string, pulse: bool}>, bar: list<array{key: string, href: string, label: string|null, icon: string, text: string|null, fill: string, ink: string, bar_fill: string, bar_ink: string, pulse: bool}>, buy: array{href: string, fill: string, ink: string, icon: string}|null, phone_bar: array{bar: list<array{key: string, href: string, label: string|null, icon: string, text: string|null, fill: string, ink: string, bar_fill: string, bar_ink: string, pulse: bool}>, cta: array{href: string, label: string}|null}|null}|null
     */
    public static function for(mixed $raw, string $locale, ?string $shopHref = null): ?array
    {
        return self::build($raw, $locale, $shopHref, false);
    }

    /**
     * The same shape for the admin preview: a channel switched on whose value is missing or does not
     * pass yet keeps its place there as an unfinished slot (`pending` = true, `href` = ''), so the
     * member sees where its button goes once filled in. The bar and the round buttons keep every channel
     * at its place, and the board-style phone bar stays the site's. Never used for the site itself.
     *
     * @param  mixed  $raw  the stored value (an array when set)
     * @param  string|null  $shopHref  the site's product list, or null when it has none
     * @return array{theme: string, position: string, offset_x: int, offset_y: int, buttons: list<array{key: string, href: string, label: string|null, icon: string, text: string|null, fill: string, ink: string, bar_fill: string, bar_ink: string, pulse: bool, pending?: true}>, bar: list<array{key: string, href: string, label: string|null, icon: string, text: string|null, fill: string, ink: string, bar_fill: string, bar_ink: string, pulse: bool, pending?: true}>, buy: array{href: string, fill: string, ink: string, icon: string}|null, phone_bar: array{bar: list<array{key: string, href: string, label: string|null, icon: string, text: string|null, fill: string, ink: string, bar_fill: string, bar_ink: string, pulse: bool, pending?: true}>, cta: array{href: string, label: string}|null}|null}|null
     */
    public static function preview(mixed $raw, string $locale, ?string $shopHref = null): ?array
    {
        return self::build($raw, $locale, $shopHref, true);
    }

    /**
     * The setting in the shape the theme prints; with $draft, unfinished slots kept (see preview()).
     *
     * @return array{theme: string, position: string, offset_x: int, offset_y: int, buttons: list<array{key: string, href: string, label: string|null, icon: string, text: string|null, fill: string, ink: string, bar_fill: string, bar_ink: string, pulse: bool, pending?: true}>, bar: list<array{key: string, href: string, label: string|null, icon: string, text: string|null, fill: string, ink: string, bar_fill: string, bar_ink: string, pulse: bool, pending?: true}>, buy: array{href: string, fill: string, ink: string, icon: string}|null, phone_bar: array{bar: list<array{key: string, href: string, label: string|null, icon: string, text: string|null, fill: string, ink: string, bar_fill: string, bar_ink: string, pulse: bool, pending?: true}>, cta: array{href: string, label: string}|null}|null}|null
     */
    private static function build(mixed $raw, string $locale, ?string $shopHref, bool $draft): ?array
    {
        if (! is_array($raw) || ($raw['enabled'] ?? false) !== true) {
            return null;
        }

        $theme = in_array($raw['theme'] ?? null, self::THEMES, true) ? (string) $raw['theme'] : 'vertical';
        $buy = $shopHref !== null && $theme === 'horizontal' && self::wantsShop($raw) ? self::buy($raw['buy'], $shopHref) : null;

        $buttons = [];
        $seen = [];
        foreach (array_slice(is_array($raw['channels'] ?? null) ? array_values($raw['channels']) : [], 0, self::MAX_CHANNELS) as $channel) {
            $button = is_array($channel) ? self::button($channel, $locale, $draft) : null;
            if ($button === null || isset($seen[$button['key']])) {
                continue;
            }
            $seen[$button['key']] = true;
            $buttons[] = $button;
        }

        // The bar holds every channel in the setting's order (the theme drops the ones that do not fit
        // the phone's width); unfinished slots (preview only) keep their places in it.
        $ready = array_values(array_filter($buttons, static fn (array $b): bool => ! isset($b['pending'])));
        $bar = $buttons;

        if ($buttons === [] || ($theme === 'horizontal' && $bar === [])) {
            return null;
        }

        return [
            'phone_bar' => $theme === 'vertical' ? self::phoneBar($raw['phone_bar'] ?? null, $ready, $locale) : null,
            'theme' => $theme,
            'position' => in_array($raw['position'] ?? null, self::POSITIONS, true) ? (string) $raw['position'] : 'right',
            'offset_x' => self::offset($raw['offset_x'] ?? null, self::MAX_OFFSET_X, self::DEFAULT_OFFSET_X),
            'offset_y' => self::offset($raw['offset_y'] ?? null, self::MAX_OFFSET_Y, self::DEFAULT_OFFSET_Y),
            'buttons' => $buttons,
            'bar' => $bar,
            'buy' => $buy,
        ];
    }

    /**
     * The board-style phone bar of a round-button site: on phones the round buttons give way to a
     * bar along the bottom of up to PHONE_BAR_MAX equal cells — the quick channels first, the call leading
     * (messenger and e-mail left out, BAR_HIDDEN), then an optional cell of the site's own, such as
     * "Yêu cầu báo giá". Null when the site did not switch it on or nothing is left to show.
     *
     * @param  list<array{key: string, href: string, label: string|null, icon: string, text: string|null, fill: string, ink: string, bar_fill: string, bar_ink: string, pulse: bool}>  $buttons
     * @return array{bar: list<array{key: string, href: string, label: string|null, icon: string, text: string|null, fill: string, ink: string, bar_fill: string, bar_ink: string, pulse: bool}>, cta: array{href: string, label: string}|null}|null
     */
    private static function phoneBar(mixed $raw, array $buttons, string $locale): ?array
    {
        if (! is_array($raw) || ($raw['enabled'] ?? false) !== true) {
            return null;
        }
        $cta = self::cta($raw['cta'] ?? null, $locale);
        $quick = array_values(array_filter($buttons, static fn (array $b): bool => ! in_array($b['key'], self::BAR_HIDDEN, true)));
        // On a phone the call comes first (the rest keep the order of the setting).
        usort($quick, static fn (array $a, array $b): int => ($b['key'] === 'phone') <=> ($a['key'] === 'phone'));
        $bar = array_map(static function (array $b): array {
            $b['fill'] = $b['bar_fill'];
            $b['ink'] = $b['bar_ink'];

            return $b;
        }, array_slice($quick, 0, self::PHONE_BAR_MAX - ($cta === null ? 0 : 1)));

        return $bar === [] && $cta === null ? null : ['bar' => $bar, 'cta' => $cta];
    }

    /**
     * The phone bar's own cell: a label in the page's language (else the first one given) and a link
     * that passes SafeLink; null when either is missing.
     *
     * @return array{href: string, label: string}|null
     */
    private static function cta(mixed $cta, string $locale): ?array
    {
        if (! is_array($cta)) {
            return null;
        }
        $labels = is_array($cta['label'] ?? null) ? $cta['label'] : [];
        $label = self::label($labels[$locale] ?? null);
        foreach ($labels as $text) {
            $label ??= self::label($text);
        }
        $href = is_string($cta['url'] ?? null) ? SafeLink::href(trim($cta['url'])) : null;

        return $label === null || $href === null ? null : ['href' => $href, 'label' => $label];
    }

    /**
     * The "Mua hàng" button: the site's brand fill with the brand's own readable ink, or a chosen
     * colour darkened until the white bag reads on it at 3:1 (with $under, toward the slate in OKLab:
     * ReadableColor::inkUnder, as the website draws every fill).
     *
     * @return array{href: string, fill: string, ink: string, icon: string}
     */
    private static function buy(mixed $buy, string $href, bool $under = false): array
    {
        $color = is_array($buy) ? ($buy['color'] ?? null) : null;
        if ($color === 'brand') {
            return ['href' => $href, 'fill' => 'var(--color-primary)', 'ink' => 'var(--color-on-primary)', 'icon' => ContactIcons::path('ri-shopping-bag-fill') ?? ''];
        }
        $hex = self::color($color) ?? self::BUY_COLOR;

        return ['href' => $href, 'fill' => $under ? ReadableColor::inkUnder($hex) : (ReadableColor::on($hex, '#ffffff', 3.0) ?? $hex), 'ink' => '#ffffff', 'icon' => ContactIcons::path('ri-shopping-bag-fill') ?? ''];
    }

    /** An offset in px: an even whole number from 0 to $max, else the default. */
    public static function offset(mixed $value, int $max, int $default): int
    {
        return is_int($value) && $value >= 0 && $value <= $max && $value % 2 === 0 ? $value : $default;
    }

    /**
     * One shown button, or null when the channel is off, unknown or its value does not pass — with
     * $draft, a switched-on channel whose value does not pass yet is an unfinished slot instead.
     *
     * @param  array<array-key, mixed>  $channel
     * @return array{key: string, href: string, label: string|null, icon: string, text: string|null, fill: string, ink: string, bar_fill: string, bar_ink: string, pulse: bool, pending?: true}|null
     */
    private static function button(array $channel, string $locale, bool $draft = false): ?array
    {
        $key = is_string($channel['key'] ?? null) ? $channel['key'] : '';
        $type = self::typeOf($key);
        if ($type === null || ($channel['on'] ?? false) !== true) {
            return null;
        }
        $clean = self::clean($type, $channel['value'] ?? null);
        $href = $clean === null ? null : self::href($type, $clean);
        if ($href === null && ! $draft) {
            return null;
        }

        $meta = self::CHANNELS[$key] ?? null;
        $iconCode = $meta['icon'] ?? (in_array($channel['icon'] ?? null, ContactIcons::CUSTOM, true) ? (string) $channel['icon'] : 'ri-global-line');
        $text = $meta['text'] ?? null;
        $color = self::color($channel['color'] ?? null) ?? ($meta['color'] ?? self::CUSTOM_COLOR);

        $tips = is_array($channel['tips'] ?? null) ? $channel['tips'] : [];
        $label = self::label($tips[$locale] ?? null);
        if ($label === null && $meta === null) {
            $label = self::label($channel['name'] ?? null);
        }

        // White marks on the fill: a word needs 4.5:1, a glyph 3:1. A light fill the site chose itself keeps
        // its hue and takes a dark ink when that reads; otherwise the hue is darkened just enough.
        $need = $text !== null ? ReadableColor::AA_NORMAL_TEXT : 3.0;
        $light = self::color($channel['color'] ?? null) !== null && (ReadableColor::ratio($color, '#ffffff') ?? $need) < $need && (ReadableColor::ratio($color, self::DARK_INK) ?? 0.0) >= $need;

        return ($href === null ? ['pending' => true] : []) + [
            'key' => $key,
            'href' => $href ?? '',
            'label' => $label,
            'icon' => ContactIcons::path($iconCode) ?? '',
            'text' => $text,
            // Round buttons and the horizontal bar keep their colour darkened until white reads on it.
            'fill' => ReadableColor::on($color, '#ffffff', $need) ?? $color,
            'ink' => '#ffffff',
            // Only the phone bar keeps a light colour of the site's own and takes a dark ink on it.
            'bar_fill' => $light ? $color : (ReadableColor::on($color, '#ffffff', $need) ?? $color),
            'bar_ink' => $light ? self::DARK_INK : '#ffffff',
            'pulse' => ($meta['pulse'] ?? false) === true,
        ];
    }

    private static function label(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, self::MAX_LABEL);
    }

    /**
     * A phone number as people type it, read by the same rules as a customer's contact phone
     * (ContactPhone: a Vietnamese mobile 0 + 3/5/7/8/9 + 8 digits or landline 0 + 2 + 9 digits, also
     * written +84 / 0084 / "+84 (0)", any spaces, dots, dashes and brackets); with $hotline also a
     * 1900/1800 number (+ 4 or 6 digits); with $abroad also a number of another country. What the
     * link dials: a home number typed with its leading 0 stays 0…, any other the +number.
     */
    private static function phone(string $value, bool $hotline, bool $abroad): ?string
    {
        $digits = self::digits($value);
        if ($hotline && preg_match('/\A(?:1900|1800)(?:\d{4}|\d{6})\z/', $digits) === 1) {
            return $digits;
        }
        $number = ContactPhone::normalize($value, '84');   // the button's rules are Vietnam's (see above)
        if ($number === null || (! $abroad && ! str_starts_with($number, '+84'))) {
            return null;
        }

        return str_starts_with($number, '+84') && preg_match('/\A0[1-9]/', $digits) === 1 ? '0'.substr($number, 3) : $number;
    }

    /** A Vietnamese mobile number only (what Zalo is registered with). */
    private static function mobile(string $value): ?string
    {
        $number = self::phone($value, false, false);

        return $number !== null && preg_match('/\A(?:0|\+84)[35789]/', $number) === 1 ? $number : null;
    }

    /** The typed number without any kind of space, dots, dashes and brackets. */
    private static function digits(string $value): string
    {
        return (string) preg_replace('/[\p{Z}\s.\-()]/u', '', $value);
    }

    /** 0912345678 / +84912345678 → 84912345678; any other +number → its digits. */
    private static function international(string $phone): string
    {
        return str_starts_with($phone, '+') ? substr($phone, 1) : '84'.substr($phone, 1);
    }

    /** A Facebook page name or id, or an m.me / facebook.com link to one. */
    private static function messenger(string $value): ?string
    {
        $page = (string) preg_replace('~\A(?:https?://)?(?:www\.)?(?:m\.me|messenger\.com/t|facebook\.com|fb\.com)/~i', '', $value);
        $page = rtrim($page, '/');

        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9.\-_]{0,99}\z/', $page) === 1 ? $page : null;
    }

    /** A Telegram username (5–32 letters, digits, _), with or without @ or a t.me link. */
    private static function telegram(string $value): ?string
    {
        $user = (string) preg_replace('~\A(?:@|(?:https?://)?(?:www\.)?t\.me/)~i', '', $value);
        $user = rtrim($user, '/');

        return preg_match('/\A[A-Za-z][A-Za-z0-9_]{4,31}\z/', $user) === 1 ? $user : null;
    }

    /** An absolute web link (http/https with a host). */
    private static function web(string $value): ?string
    {
        $href = SafeLink::href($value);

        return $href !== null && ! str_starts_with($href, '/') ? $href : null;
    }

    /** A channel of the site's own: a web link or a path on the site, or tel: / sms: / mailto:. */
    private static function custom(string $value): ?string
    {
        if (preg_match('/\A(tel|sms|mailto):(.*)\z/is', $value, $m) === 1) {
            $scheme = strtolower($m[1]);
            if ($scheme === 'mailto') {
                return SafeLink::mailto($m[2]);
            }
            $tel = SafeLink::tel($m[2]);

            return $tel === null ? null : $scheme.':'.substr($tel, 4);
        }

        return SafeLink::href($value);
    }
}
