<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * «Ngày đăng hiện trên bài» (board 07): the day a post shows as its publish day, kept in the entry's
 * meta as `display_date` (a calendar day, Y-m-d, already in the site's zone). Empty or absent means
 * the real publish day. It is only printed — on the post's band and its cards — never filtered or
 * sorted on, so it stays in meta (the graduation rule). The post's structured data says the same day
 * (it must match what a reader sees); the sitemap and the feeds keep the real publish time.
 */
final class DisplayDate
{
    public const string KEY = 'display_date';

    /** Whether $value is a real calendar day written as Y-m-d (four-digit year). */
    public static function valid(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Why the meta's display day cannot be stored; empty when it is fine or absent.
     *
     * @param  array<array-key, mixed>  $meta
     * @return list<string>
     */
    public static function errors(array $meta): array
    {
        if (! array_key_exists(self::KEY, $meta) || self::valid($meta[self::KEY])) {
            return [];
        }

        return [self::KEY.' must be a day written as YYYY-MM-DD.'];
    }

    /** The stored display day (Y-m-d), or '' for none. */
    public static function of(mixed $meta): string
    {
        $value = is_array($meta) ? ($meta[self::KEY] ?? null) : null;

        return is_string($value) && self::valid($value) ? $value : '';
    }

    /**
     * The meta with the display day set to $day ('' takes it out, so the real day shows).
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function into(array $meta, string $day): array
    {
        if ($day === '') {
            unset($meta[self::KEY]);
        } else {
            $meta[self::KEY] = $day;
        }

        return $meta;
    }

    /**
     * The day to print for a post: its display day (midnight in $zone), else $published as given
     * (the real publish time already moved into the site's zone by the caller), else null.
     */
    public static function shown(mixed $meta, ?DateTimeInterface $published, string $zone): ?CarbonImmutable
    {
        $day = self::of($meta);
        if ($day !== '') {
            return CarbonImmutable::createFromFormat('!Y-m-d', $day, $zone) ?: null;
        }

        return $published !== null ? CarbonImmutable::instance($published) : null;
    }
}
