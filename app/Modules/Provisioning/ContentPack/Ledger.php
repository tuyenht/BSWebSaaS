<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\ContentPack;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Models\SiteSetting;

/**
 * What the loader last wrote to a site, per pack: one `site_settings` row (`content_pack`, <pack code>)
 * holding, per item key, the canonical hash of the item's managed fields as read back right after
 * the write, and the record's ulid or slug (never a bigint id). A record whose hash still equals
 * the ledger's is untouched since the last load and may be updated; one that differs was edited by
 * staff and is kept. An existing tenant table with RLS: no schema change. Callers run inside the
 * site's tenant context.
 */
final class Ledger
{
    public const string GROUP = 'content_pack';

    /**
     * @param  bool  $lock  read the row FOR NO KEY UPDATE (inside the --apply transaction), like the Planner's rows
     * @return array{pack_version: int, loaded_at: string, items: array<string, array{hash: string, ref: string}>}|null
     */
    public static function read(string $code, bool $lock = false): ?array
    {
        $query = SiteSetting::query()->where('group_name', self::GROUP)->where('key', $code);
        if ($lock) {
            $query->lock('for no key update');
        }
        $value = $query->value('value');
        if ($value === null) {
            return null;
        }
        $value = is_string($value) ? json_decode($value, true) : $value;
        $value = Cast::arr($value);

        $items = [];
        foreach (Cast::arr($value['items'] ?? null) as $key => $entry) {
            $entry = Cast::arr($entry);
            $items[(string) $key] = ['hash' => Cast::string($entry['hash'] ?? null), 'ref' => Cast::string($entry['ref'] ?? null)];
        }

        return [
            'pack_version' => Cast::int($value['pack_version'] ?? null),
            'loaded_at' => Cast::string($value['loaded_at'] ?? null),
            'items' => $items,
        ];
    }

    /** Whether the current site has been loaded from any content pack. */
    public static function any(): bool
    {
        return SiteSetting::query()->where('group_name', self::GROUP)->exists();
    }

    /**
     * Store the ledger; returns false (and writes nothing) when its items and version are unchanged.
     *
     * @param  array<string, array{hash: string, ref: string}>  $items
     * @param  array{pack_version: int, loaded_at: string, items: array<string, array{hash: string, ref: string}>}|null  $previous
     */
    public static function write(string $code, int $version, array $items, ?array $previous): bool
    {
        ksort($items, SORT_STRING);
        if ($previous !== null && $previous['pack_version'] === $version && CanonicalHash::of($previous['items']) === CanonicalHash::of($items)) {
            return false;
        }

        SiteSetting::query()->updateOrCreate(
            ['group_name' => self::GROUP, 'key' => $code],
            ['value' => ['pack_version' => $version, 'loaded_at' => now()->utc()->toIso8601String(), 'items' => $items]],
        );

        return true;
    }
}
