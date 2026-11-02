<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\ContentPack;

/**
 * The one way a content-pack item's managed fields become a comparable hash: object keys sorted at
 * every depth, list order kept, encoded without escaping unicode or slashes, then SHA-256. Both
 * sides of every comparison (what the database holds now, what the pack would make of it) go
 * through here, so a difference in jsonb key order or PHP array order never reads as an edit.
 */
final class CanonicalHash
{
    public static function of(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    public static function encode(mixed $value): string
    {
        return json_encode(self::canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonical(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(self::canonical(...), $value);
    }
}
