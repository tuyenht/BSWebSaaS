<?php

declare(strict_types=1);

namespace App\Modules\Admin\Theme;

use App\Modules\Theme\Theming\BrandPalette;

/**
 * The admin brand colour, from ONE source: resources/admin-brands.json (the approved table of
 * colours the platform may choose, read by scripts/contrast-check.mjs too). The platform picks a
 * row by key (config admin.brand.color); an unknown key falls back to the first row.
 *
 * The admin layouts print cssVariables() as one `<style>:root{…}</style>` block in the head, over
 * the build-time defaults in app.css @theme (which equal the first row). Every printed value comes
 * from the table and is checked to be a #rrggbb colour, so nothing else can reach the stylesheet.
 * The dark scheme derives its own brand ink in CSS from --color-primary-base (admin-chrome.css).
 *
 * Stateless and pure (Octane): the table is read per call, no request state is kept.
 */
final class AdminBrandColor
{
    public const string DEFAULT = 'bao-son';

    /** The table, relative to the project root. */
    public const string SOURCE = 'resources/admin-brands.json';

    /**
     * Valid rows of the table, by key, in table order. A malformed row is left out.
     *
     * @return array<string, array{name: string, brand: string, solid: string, ink: string}>
     */
    public static function table(?string $path = null): array
    {
        $file = $path ?? self::sourcePath();
        $json = is_file($file) ? file_get_contents($file) : false;
        $data = is_string($json) ? json_decode($json, true) : null;
        $rows = is_array($data) && is_array($data['brands'] ?? null) ? $data['brands'] : [];

        $table = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = $row['key'] ?? null;
            $name = $row['name'] ?? null;
            $brand = self::color($row['brand'] ?? null);
            $solid = self::color($row['solid'] ?? null);
            $ink = self::color($row['ink'] ?? null);
            if (! is_string($key) || preg_match('/^[a-z0-9-]{1,32}$/', $key) !== 1 || ! is_string($name)
                || $brand === null || $solid === null || $ink === null) {
                continue;
            }
            $table[$key] = ['name' => $name, 'brand' => $brand, 'solid' => $solid, 'ink' => $ink];
        }

        return $table;
    }

    /**
     * The row for $key, or the default row when the key is unknown (or not a string).
     *
     * @return array{key: string, name: string, brand: string, solid: string, ink: string}|null null only when the table is empty
     */
    public static function pick(mixed $key, ?string $path = null): ?array
    {
        $table = self::table($path);
        $chosen = is_string($key) && isset($table[$key]) ? $key : (isset($table[self::DEFAULT]) ? self::DEFAULT : array_key_first($table));

        return $chosen === null ? null : ['key' => $chosen, ...$table[$chosen]];
    }

    /**
     * Token name (without the leading --) → value, for the admin `:root` block: the base hue,
     * the fill, the light-scheme ink and the ten-step palette of the base hue.
     *
     * @return array<string, string>
     */
    public static function cssVariables(mixed $key, ?string $path = null): array
    {
        $row = self::pick($key, $path);
        if ($row === null) {
            return [];
        }

        $variables = [
            'color-primary-base' => $row['brand'],
            'color-primary' => $row['solid'],
            'color-primary-text' => $row['ink'],
        ];
        foreach (BrandPalette::from($row['brand']) ?? [] as $step => $color) {
            $variables["color-brand-{$step}"] = $color;
        }

        return $variables;
    }

    /** A lowercase #rrggbb colour, or null for anything else. */
    private static function color(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : null;
    }

    private static function sourcePath(): string
    {
        return dirname(__DIR__, 4).'/'.self::SOURCE;
    }
}
