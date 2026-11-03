<?php

declare(strict_types=1);

namespace App\Modules\Theme\Components;

/**
 * Finds and rewrites the `html`-typed props of a section's §E7 schema — the props whose value is
 * formatted text printed without escaping (ADR-19). The same walk serves the data layer (every
 * value is sanitized on write) and the renderer (images resolved, videos turned into façades), so
 * the two cannot disagree about which props hold HTML. Handles depth like SchemaImages: a top-level
 * leaf and a list `of` an object carrying html fields. Only string values reach $fn; anything else
 * is left as it is (ValidPageJson refuses it at the editor).
 */
final class SchemaHtml
{
    /**
     * Whether the schema declares an html prop at any depth.
     *
     * @param  array<array-key, mixed>  $schema
     */
    public static function declares(array $schema): bool
    {
        foreach ($schema as $spec) {
            if (! is_array($spec)) {
                continue;
            }
            if (($spec['type'] ?? null) === 'html') {
                return true;
            }
            $of = $spec['of'] ?? null;
            if (($spec['type'] ?? null) === 'list' && is_array($of) && (isset($of['type']) && is_string($of['type']) ? $of['type'] === 'html' : self::declares($of))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every string value of an html prop in $props, per $schema.
     *
     * @param  array<array-key, mixed>  $props
     * @param  array<array-key, mixed>  $schema
     * @return list<string>
     */
    public static function values(array $props, array $schema): array
    {
        $values = [];
        self::map($props, $schema, function (string $html) use (&$values): string {
            $values[] = $html;

            return $html;
        });

        return $values;
    }

    /**
     * $props with every string html leaf replaced by $fn's return; every other prop untouched.
     *
     * @param  array<array-key, mixed>  $props
     * @param  array<array-key, mixed>  $schema
     * @param  callable(string): string  $fn
     * @return array<array-key, mixed>
     */
    public static function map(array $props, array $schema, callable $fn): array
    {
        foreach ($schema as $prop => $spec) {
            if (! is_array($spec)) {
                continue;
            }

            $type = $spec['type'] ?? null;

            if ($type === 'html') {
                if (is_string($props[$prop] ?? null)) {
                    $props[$prop] = $fn($props[$prop]);
                }

                continue;
            }

            $of = $spec['of'] ?? null;
            $items = $props[$prop] ?? null;
            if ($type !== 'list' || ! is_array($of) || ! is_array($items)) {
                continue;
            }

            // Same disambiguation as SchemaImages: a scalar list has `of => ['type' => '<string>']`.
            $scalarOf = isset($of['type']) && is_string($of['type']);

            foreach ($items as $i => $item) {
                if ($scalarOf) {
                    if ($of['type'] === 'html' && is_string($item)) {
                        $items[$i] = $fn($item);
                    }
                } elseif (is_array($item)) {
                    $items[$i] = self::map($item, $of, $fn);
                }
            }

            $props[$prop] = $items;
        }

        return $props;
    }
}
