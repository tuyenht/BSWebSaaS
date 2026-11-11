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

    /** Buttons the phone bottom bar holds at most; past the third they show only where the phone is wide enough. */
    public const int BAR_MAX = 5;

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

    /** Channels the phone bottom bar leaves out (they read as a chat window, not a quick tap). */
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
            'phone', 'sms' => self::phone($value, true, false),
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

    /** A colour as stored (#rrggbb, lower case), else null. */
    public static function color(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A#[0-9a-f]{6}\z/i', $value) === 1 ? strtolower($value) : null;
    }

    /**
     * Whether the stored setting would show the "Mua hàng" button (on, the phone bar, its switch
     * on) — the only case where the caller needs to look up the site's product list.
     */
    public static function wantsShop(mixed $raw): bool
    {
        return is_array($raw) && ($raw['enabled'] ?? false) === true && ($raw['theme'] ?? null) === 'horizontal'
            && is_array($raw['buy'] ?? null) && ($raw['buy']['enabled'] ?? false) === true;
    }

    /**
     * The setting in the shape the theme prints, for a page in $locale; null when it is off or
     * has no channel to show. `label` is the hover label in the page's language (null = the
     * channel's own default, which the theme words); custom channels fall back to their name.
     * `buy` is the "Mua hàng" button at the end of the phone bar: only when its switch is on and
     * the site has a product list to send people to ($shopHref); it then takes one of the five places.
     *
     * @param  mixed  $raw  the stored value (an array when set)
     * @param  string|null  $shopHref  the site's product list, or null when it has none
     * @return array{theme: string, position: string, offset_x: int, offset_y: int, buttons: list<array{key: string, href: string, label: string|null, icon: string, text: string|null, fill: string, ink: string, pulse: bool}>, bar: list<array{key: string, href: string, label: string|null, icon: string, text: string|null, fill: string, ink: string, pulse: bool}>, buy: array{href: string, fill: string, ink: string, icon: string}|null, phone_bar: array{bar: list<array{key: string, href: string, label: string|null, icon: string, text: string|null, fill: string, ink: string, pulse: bool}>, cta: array{href: string, label: string}|null}|null}|null
     */
    public static function for(mixed $raw, string $locale, ?string $shopHref = null): ?array
    {
        if (! is_array($raw) || ($raw['enabled'] ?? false) !== true) {
            return null;
        }

        $theme = in_array($raw['theme'] ?? null, self::THEMES, true) ? (string) $raw['theme'] : 'vertical';
        $buy = $shopHref !== null && self::wantsShop($raw) ? self::buy($raw['buy'], $shopHref) : null;
        $barMax = $buy === null ? self::BAR_MAX : self::BAR_MAX - 1;

        $buttons = [];
        $bar = [];
        $seen = [];
        foreach (array_slice(is_array($raw['channels'] ?? null) ? array_values($raw['channels']) : [], 0, self::MAX_CHANNELS) as $channel) {
            $button = is_array($channel) ? self::button($channel, $locale) : null;
            if ($button === null || isset($seen[$button['key']])) {
                continue;
            }
            $seen[$button['key']] = true;
            $buttons[] = $button;
            if (! in_array($button['key'], self::BAR_HIDDEN, true) && count($bar) < $barMax) {
                $bar[] = $button;
            }
        }

        if ($buttons === [] || ($theme === 'horizontal' && $bar === [])) {
            return null;
        }

        return [
            'phone_bar' => $theme === 'vertical' ? self::phoneBar($raw['phone_bar'] ?? null, $buttons, $locale) : null,
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
     * (messenger and e-mail left out, as in the other bar), then an optional cell of the site's own, such as
     * "Yêu cầu báo giá". Null when the site did not switch it on or nothing is left to show.
     *
     * @param  list<array{key: string, href: string, label: string|null, icon: string, text: string|null, fill: string, ink: string, pulse: bool}>  $buttons
     * @return array{bar: list<array{key: string, href: string, label: string|null, icon: string, text: string|null, fill: string, ink: string, pulse: bool}>, cta: array{href: string, label: string}|null}|null
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
        $bar = array_slice($quick, 0, self::PHONE_BAR_MAX - ($cta === null ? 0 : 1));

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
     * colour darkened until the white bag reads on it at 3:1.
     *
     * @return array{href: string, fill: string, ink: string, icon: string}
     */
    private static function buy(mixed $buy, string $href): array
    {
        $color = is_array($buy) ? ($buy['color'] ?? null) : null;
        if ($color === 'brand') {
            return ['href' => $href, 'fill' => 'var(--color-primary)', 'ink' => 'var(--color-on-primary)', 'icon' => ContactIcons::path('ri-shopping-bag-fill') ?? ''];
        }
        $hex = self::color($color) ?? self::BUY_COLOR;

        return ['href' => $href, 'fill' => ReadableColor::on($hex, '#ffffff', 3.0) ?? $hex, 'ink' => '#ffffff', 'icon' => ContactIcons::path('ri-shopping-bag-fill') ?? ''];
    }

    /** An offset in px: an even whole number from 0 to $max, else the default. */
    public static function offset(mixed $value, int $max, int $default): int
    {
        return is_int($value) && $value >= 0 && $value <= $max && $value % 2 === 0 ? $value : $default;
    }

    /**
     * One shown button, or null when the channel is off, unknown or its value does not pass.
     *
     * @param  array<array-key, mixed>  $channel
     * @return array{key: string, href: string, label: string|null, icon: string, text: string|null, fill: string, ink: string, pulse: bool}|null
     */
    private static function button(array $channel, string $locale): ?array
    {
        $key = is_string($channel['key'] ?? null) ? $channel['key'] : '';
        $type = self::typeOf($key);
        if ($type === null || ($channel['on'] ?? false) !== true) {
            return null;
        }
        $clean = self::clean($type, $channel['value'] ?? null);
        $href = $clean === null ? null : self::href($type, $clean);
        if ($href === null) {
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

        return [
            'key' => $key,
            'href' => $href,
            'label' => $label,
            'icon' => ContactIcons::path($iconCode) ?? '',
            'text' => $text,
            'fill' => $light ? $color : (ReadableColor::on($color, '#ffffff', $need) ?? $color),
            'ink' => $light ? self::DARK_INK : '#ffffff',
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
