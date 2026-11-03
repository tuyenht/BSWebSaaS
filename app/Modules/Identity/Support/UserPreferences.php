<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Writes one key of a member's `users.preferences` at a time. Every screen keeps its own choices
 * there (the theme, a list's columns, a page size), often from two tabs at once, so a write never
 * replaces the whole column: one UPDATE sets just its path with jsonb_set (or drops it with #-),
 * and whatever another screen saved meanwhile stays.
 *
 * A path is a list of keys written by the code, never by the browser. Missing or non-object levels
 * on the way are made empty objects first, so a path can be written into any stored value.
 */
final class UserPreferences
{
    /** @param  non-empty-list<string>  $path */
    public static function set(User $user, array $path, mixed $value): void
    {
        $sql = "case when jsonb_typeof(preferences) = 'object' then preferences else '{}'::jsonb end";
        $bindings = [];
        $prefix = [];

        // every level above the key becomes an object (an existing one is kept as it is)
        foreach (array_slice($path, 0, -1) as $key) {
            $prefix[] = $key;
            $at = self::pathSql(count($prefix));
            $sql = "jsonb_set({$sql}, {$at}, case when jsonb_typeof(preferences #> {$at}) = 'object' then preferences #> {$at} else '{}'::jsonb end, true)";
            $bindings = [...$bindings, ...$prefix, ...$prefix, ...$prefix];
        }

        // placeholders read left to right: the wrapped expression's, then this path's keys, then the value
        $sql = "jsonb_set({$sql}, ".self::pathSql(count($path)).', ?::jsonb, true)';
        $bindings = [...$bindings, ...$path, json_encode($value, JSON_THROW_ON_ERROR)];

        DB::connection($user->getConnectionName())->update(
            "update {$user->getTable()} set preferences = {$sql} where id = ?",
            [...$bindings, $user->getKey()],
        );
    }

    /**
     * Drop the key at $path. When that leaves its parent an empty object, the parent goes too, so
     * a reset leaves no empty shell behind.
     *
     * @param  non-empty-list<string>  $path
     */
    public static function forget(User $user, array $path): void
    {
        $db = DB::connection($user->getConnectionName());
        $table = $user->getTable();

        $db->update(
            "update {$table} set preferences = preferences #- ".self::pathSql(count($path))." where id = ? and jsonb_typeof(preferences) = 'object'",
            [...$path, $user->getKey()],
        );

        $parent = array_slice($path, 0, -1);
        if ($parent !== []) {
            $at = self::pathSql(count($parent));
            $db->update(
                "update {$table} set preferences = preferences #- {$at} where id = ? and preferences #> {$at} = '{}'::jsonb",
                [...$parent, $user->getKey(), ...$parent],
            );
        }
    }

    /** Read the stored column back into $user, so what the caller renders next is what was saved. */
    public static function reload(User $user): void
    {
        $raw = DB::connection($user->getConnectionName())->table($user->getTable())->where('id', $user->getKey())->value('preferences');
        $user->setRawAttributes([...$user->getAttributes(), 'preferences' => $raw]);
        $user->syncOriginalAttribute('preferences');
    }

    /** @return literal-string `array[?, ?, …]::text[]` with one placeholder per key */
    private static function pathSql(int $keys): string
    {
        return 'array['.implode(', ', array_fill(0, max(1, $keys), '?')).']::text[]';
    }
}
