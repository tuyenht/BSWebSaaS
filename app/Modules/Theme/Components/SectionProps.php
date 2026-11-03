<?php

declare(strict_types=1);

namespace App\Modules\Theme\Components;

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

    public const array ENTRY_GRID_VARIANTS = ['list', 'icon-cards', 'project-cards', 'news-cards'];

    public const array PRODUCT_GRID_VARIANTS = ['grid', 'overlay'];

    public const array CTA_VARIANTS = ['default', 'rounded'];

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
        return mb_strtoupper(mb_substr(trim($name), 0, 1));
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
