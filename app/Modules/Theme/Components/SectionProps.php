<?php

declare(strict_types=1);

namespace App\Modules\Theme\Components;

use App\Modules\Tenancy\Context\SiteTime;
use Carbon\CarbonImmutable;

/**
 * Reads the choice props of a section at render time (pure, no query). An `enum` prop picks one look
 * from a fixed list: ValidPageJson refuses any other value at save, and here a stored value that is
 * missing or not in the list falls back to the default, so a prop can never select a partial or a
 * class that is not registered. Numbers are clamped the same way.
 *
 * The option lists live here so a block's schema, its component and its view share one list.
 */
final class SectionProps
{
    /** The band behind a section: the page, the card surface, a light tint or the dark colour. */
    public const array BG = ['none', 'surface', 'tint', 'dark'];

    /** The look of an article list; `zigzag` is a row per item with the photo alternating left and right. Append-only. */
    public const array ENTRY_GRID_VARIANTS = ['list', 'icon-cards', 'project-cards', 'news-cards', 'zigzag'];

    /**
     * News tabs of an article list: none (today's look), one tab per published sub-category, or the
     * same tabs with the filter behaviour (it draws like `auto` until the jobs part is built). Append-only.
     */
    public const array ENTRY_GRID_TABS = ['off', 'auto', 'filter'];

    /** The looks that can carry tabs: the card looks (the plain list and the rows have no tab panel). */
    public const array TAB_VARIANTS = ['icon-cards', 'project-cards', 'news-cards'];

    /** The most tabs a block draws, and the fewest it needs (below that the list is drawn plain). */
    public const int TABS_MAX = 3;

    public const int TABS_MIN = 2;

    /** Where a product grid takes its products from: the newest, one category or a hand-picked list. Append-only. */
    public const array PRODUCT_SOURCES = ['latest', 'category', 'picked'];

    /** The order of the newest/category sources. Append-only. */
    public const array PRODUCT_SORTS = ['newest', 'oldest'];

    /** The most products a hand-picked list holds. */
    public const int PRODUCT_PICK_MAX = 24;

    /** A ulid as the screens print it (26 characters of Crockford base32), either case. */
    public const string ULID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/i';

    /** About: the plain text beside a photo, or a letter (greeting in bold, the last two paragraphs the signature). Append-only. */
    public const array ABOUT_VARIANTS = ['default', 'letter'];

    /** Where the about photo is cropped when the frame is shorter than the picture. Append-only. */
    public const array ABOUT_IMAGE_FOCUS = ['center', 'top', 'bottom'];

    /** How many shortcut tiles an about block holds. */
    public const int ABOUT_LINKS_MAX_ITEMS = 4;

    public const array PRODUCT_GRID_VARIANTS = ['grid', 'overlay'];

    public const array CTA_VARIANTS = ['default', 'rounded'];

    /** The block beside a call to action: nothing (today's look) or up to three stats (default variant only). */
    public const array CTA_SIDES = ['none', 'stats'];

    public const int CTA_SIDE_MAX_ITEMS = 3;

    /** Stats look: the plain band, or a card floating up over the band above it. */
    public const array STATS_VARIANTS = ['default', 'floating'];

    /** What a stat's value means: a counted number, years since a start year, or a year printed as is. Append-only. */
    public const array STAT_KINDS = ['number', 'years', 'year'];

    public const array CONTACT_VARIANTS = ['list', 'cards'];

    public const array FEATURES_VARIANTS = ['default', 'top-rule'];

    /** The glyphs of the quick-links tiles: keys of theme::partials.sf-icon that suit a link. */
    public const array QUICK_LINK_ICONS = ['link', 'phone', 'email', 'address', 'map', 'building', 'license'];

    /** Columns on a wide screen; a phone always shows one (gallery two). Stored as the digit string. */
    public const array GALLERY_COLUMNS = ['3', '2', '4'];

    public const array FAQ_COLUMNS = ['1', '2'];

    /** Team: three or four members across on a wide screen (two on a phone). */
    public const array TEAM_VARIANTS = ['grid-3', 'grid-4'];

    /** Testimonials: two or three cards across on a wide screen (one on a phone). */
    public const array TESTIMONIAL_VARIANTS = ['grid-2', 'grid-3'];

    /** Quick links: a 2x2 grid, one row (up to four across), or wrapping pills. */
    public const array QUICK_LINK_VARIANTS = ['grid-2x2', 'row', 'pill'];

    /**
     * The "display" group of every list block (articles, products, partners, testimonials, team): how
     * the items are laid out, how many across, what a phone does, whether it rotates by itself, the
     * arrows and the "view all" button. Every value is stored as a string; the list order is
     * append-only (a stored value is never renamed). `auto` is the block type's own rule, read from
     * the table in ListDisplay. The editor offers no control for the group yet (group `display`).
     */
    public const array LIST_DISPLAY = [
        'layout' => ['type' => 'enum', 'options' => ['auto', 'grid', 'slider'], 'default' => 'auto', 'group' => 'display'],
        'columns' => ['type' => 'enum', 'options' => ['auto', '2', '3', '4'], 'default' => 'auto', 'group' => 'display'],
        'mobile' => ['type' => 'enum', 'options' => ['auto', 'swipe', 'two', 'one'], 'default' => 'auto', 'group' => 'display'],
        'autoplay' => ['type' => 'enum', 'options' => ['auto', 'off', '3', '5', '8'], 'default' => 'auto', 'group' => 'display'],
        'arrows' => ['type' => 'enum', 'options' => ['auto', 'always', 'none'], 'default' => 'auto', 'group' => 'display'],
        'view_all' => ['type' => 'enum', 'options' => ['auto', 'show', 'hide'], 'default' => 'auto', 'group' => 'display'],
    ];

    /** The most items a list of a static block holds (the block's own list may set a lower `max`). */
    public const int MAX_ITEMS = 12;

    /**
     * The schema leaf of a choice prop.
     *
     * @param  list<string>  $options
     * @return array{type: 'enum', options: list<string>, default: string}
     */
    public static function enum(array $options): array
    {
        return ['type' => 'enum', 'options' => $options, 'default' => $options[0]];
    }

    /**
     * The stored choice when it is one of $options, else $default.
     *
     * @param  array<array-key, mixed>  $props
     * @param  list<string>  $options
     */
    public static function choice(array $props, string $key, array $options, ?string $default = null): string
    {
        $value = $props[$key] ?? null;

        return is_string($value) && in_array($value, $options, true) ? $value : ($default ?? $options[0]);
    }

    /**
     * A whole number within [$min, $max]; anything that is not a number gives $default.
     *
     * @param  array<array-key, mixed>  $props
     */
    public static function clampInt(array $props, string $key, int $min, int $max, int $default): int
    {
        $value = $props[$key] ?? null;

        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }

    /** The first letter of a name, uppercase: the stand-in for a missing photo. */
    public static function initial(string $name): string
    {
        // Composed first, so a name pasted in decomposed form ("A" + combining accent) keeps its accent.
        $name = trim($name);
        $composed = \Normalizer::normalize($name, \Normalizer::FORM_C);

        return mb_strtoupper(mb_substr(is_string($composed) ? $composed : $name, 0, 1));
    }

    /**
     * A text prop, or $fallback when it is missing or blank.
     *
     * @param  array<array-key, mixed>  $props
     */
    public static function text(array $props, string $key, string $fallback = ''): string
    {
        $value = $props[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : $fallback;
    }

    /**
     * The fields of one stat (a stats block's item and a call to action's side item share them).
     * `value` of the `years` kind is the start year, of the `year` kind the year itself.
     */
    public const array STAT_ITEM = [
        'kind' => ['type' => 'enum', 'options' => self::STAT_KINDS, 'default' => 'number'],
        'value' => ['type' => 'int', 'min' => 0, 'max' => 999_999_999_999],
        'suffix' => ['type' => 'text'],
        'label' => ['type' => 'text'],
    ];

    /** The earliest year a `year` / `years` stat accepts. */
    public const int STAT_YEAR_MIN = 1900;

    /**
     * The current year in the site's timezone. A page cache added later must not keep a page across
     * 1 January, because a `years` stat prints this year minus its start year.
     */
    public static function currentYear(): int
    {
        return (int) CarbonImmutable::now(app(SiteTime::class)->zone())->format('Y');
    }

    /**
     * The stats to draw from a list prop, in order, each as {value, number, suffix, label, count}:
     * `number` prints the value with thousands separators and counts up (`count`); `years` prints
     * this year minus the start year and `year` the year itself, both plain and with the unit locked.
     * This year is read (a settings query on a first use) only when an item is a year kind, so a block
     * of plain numbers costs nothing extra. An item with an unknown kind is a number; a year outside
     * 1900…this year, or an item with neither a number nor a label, is left out.
     *
     * @param  array<array-key, mixed>  $props
     * @return list<array{value: int|null, number: string, suffix: string, label: string, count: bool}>
     */
    public static function statEntries(array $props, string $key, int $max): array
    {
        $stats = [];
        $year = null;
        foreach (self::items($props, $key, $max) as $item) {
            $kind = self::choice($item, 'kind', self::STAT_KINDS);
            $value = self::statNumber($item['value'] ?? null);
            $label = self::text($item, 'label');

            if ($kind !== 'number') {
                $year ??= self::currentYear();
                if ($value === null || $value < self::STAT_YEAR_MIN || $value > $year) {
                    continue;
                }
                $stats[] = ['value' => $value, 'number' => (string) ($kind === 'years' ? $year - $value : $value), 'suffix' => '', 'label' => $label, 'count' => false];

                continue;
            }

            if ($value === null && $label === '') {
                continue;
            }
            $stats[] = [
                'value' => $value,
                'number' => $value === null ? '' : number_format($value, 0, ',', '.'),
                'suffix' => $value === null ? '' : mb_substr(self::text($item, 'suffix'), 0, 8),
                'label' => $label,
                'count' => $value !== null,
            ];
        }

        return $stats;
    }

    /** The most numbers a stats block shows. */
    public const int STATS_MAX_ITEMS = 8;

    /**
     * The number of a stats item: a whole number from 0 to 999 999 999 999, typed as an int or as digits
     * (leading zeros allowed, as the save check accepts them), or null. The view and the page's
     * count-up flag both read it, so the script loads only when a number is actually shown.
     */
    public static function statNumber(mixed $raw): ?int
    {
        if (is_string($raw) && preg_match('/\A\d{1,15}\z/', trim($raw)) === 1) {
            $raw = (int) trim($raw);
        }

        return is_int($raw) && $raw >= 0 && $raw <= 999_999_999_999 ? $raw : null;
    }

    /**
     * The first $max entries of a list prop that are objects, re-indexed; anything else is dropped.
     *
     * @param  array<array-key, mixed>  $props
     * @return list<array<array-key, mixed>>
     */
    public static function items(array $props, string $key, int $max = self::MAX_ITEMS): array
    {
        $value = $props[$key] ?? null;

        return is_array($value) ? array_slice(array_values(array_filter($value, is_array(...))), 0, $max) : [];
    }

    /**
     * A price in whole dong as printed in Vietnamese ("1.500.000 ₫", the space is a no-break one), or
     * null when the price is unset or not above zero — the view then says "Liên hệ". Only an integer
     * or a string of digits counts; there is no float anywhere on the way.
     */
    public static function price(mixed $value): ?string
    {
        if (is_string($value) && preg_match('/\A\d{1,15}\z/', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? number_format($value, 0, ',', '.')."\u{A0}₫" : null;
    }

    /**
     * The non-blank lines of a textarea prop, trimmed, at most $max of them.
     *
     * @return list<string>
     */
    public static function lines(mixed $value, int $max = self::MAX_ITEMS): array
    {
        if (! is_string($value)) {
            return [];
        }

        $lines = array_filter(array_map(trim(...), preg_split('/\R/u', $value) ?: []), static fn (string $line): bool => $line !== '');

        return array_slice(array_values($lines), 0, $max);
    }
}
