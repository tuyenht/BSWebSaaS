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
}
