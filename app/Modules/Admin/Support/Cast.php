<?php

namespace App\Modules\Admin\Support;

/**
 * Narrowing helpers for the inevitably-`mixed` values read from JSON-backed settings
 * (site_settings / platform_settings), the cache, and config(). Keeps the call sites
 * type-safe (Larastan max) without scattering is_string()/is_int() guards everywhere.
 */
final class Cast
{
    public static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    public static function int(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(mixed $value, bool $default = false): bool
    {
        return is_bool($value) ? $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public static function arr(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * The string values of $value (e.g. an OAuth scope list), discarding non-strings.
     *
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    public static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
