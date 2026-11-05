<?php

declare(strict_types=1);

namespace App\Modules\Cms\Taxonomy;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\DB;

/**
 * Reads a category tree through `parent_id` — the source of truth; the ltree `path` only mirrors it
 * and older rows may not have one yet. Walks go one query per level, which MAX_DEPTH keeps to a
 * handful; a parent loop in bad data ends a walk instead of spinning. Runs inside the tenant context.
 */
final class TaxonomyTree
{
    /** Hard stop for a walk over data a loop could have corrupted — far beyond any real tree. */
    private const int WALK_LIMIT = 32;

    /**
     * Serialise changes to a site's category tree until the surrounding transaction ends: the
     * category actions take it before their checks, and so does anything that must not race a
     * category being deleted (setting member limits on it).
     */
    public static function lock(int $siteId): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['taxonomies:'.$siteId]);
    }

    /**
     * The ids above a category, root first, the category itself excluded.
     *
     * @return list<int>
     */
    public static function ancestorsOf(int $id): array
    {
        $ancestors = [];
        $seen = [$id => true];
        $parent = self::parentOf($id);

        while ($parent !== null && ! isset($seen[$parent]) && count($ancestors) < self::WALK_LIMIT) {
            array_unshift($ancestors, $parent);
            $seen[$parent] = true;
            $parent = self::parentOf($parent);
        }

        return $ancestors;
    }

    /** The level a category sits at: 1 for a top-level category. */
    public static function levelOf(int $id): int
    {
        return count(self::ancestorsOf($id)) + 1;
    }

    /**
     * A category and everything under it, as id → how many levels below the category it sits
     * (0 for the category itself).
     *
     * @return non-empty-array<int, int>
     */
    public static function branchOf(int $id): array
    {
        $branch = [$id => 0];
        $frontier = [$id];

        for ($level = 1; $frontier !== [] && $level <= self::WALK_LIMIT; $level++) {
            $children = Taxonomy::query()->whereIn('parent_id', $frontier)->orderBy('id')->pluck('id')->all();
            $frontier = [];

            foreach ($children as $child) {
                $child = Cast::int($child);
                if (! isset($branch[$child])) {
                    $branch[$child] = $level;
                    $frontier[] = $child;
                }
            }
        }

        return $branch;
    }

    /**
     * Levels in a branch: 1 for a category without children.
     *
     * @param  non-empty-array<int, int>  $branch  from branchOf()
     */
    public static function heightOf(array $branch): int
    {
        return max($branch) + 1;
    }

    /**
     * A token for the shape of one vocabulary's tree — every category's parent and position — that
     * changes whenever a category of it is added, moved, reordered or deleted (not when one is
     * renamed). A screen keeps it to tell that the tree is still the one it showed: an undo of a
     * move is refused once anything moved since. One query, whatever the size of the tree.
     *
     * The digest of the ids, parents and positions is signed with the app key before it leaves
     * the server: a plain hash of a small tree could be guessed back to its database ids.
     */
    public static function version(string $vocabulary): string
    {
        $digest = Taxonomy::query()
            ->where('vocabulary', $vocabulary)
            ->selectRaw("md5(coalesce(string_agg(id::text || ':' || coalesce(parent_id::text, '') || ':' || position::text, ',' ORDER BY id), '')) AS version")
            ->value('version');

        return hash_hmac('sha256', 'taxonomy-tree|'.$vocabulary.'|'.Cast::string($digest), Cast::string(config('app.key')));
    }

    private static function parentOf(int $id): ?int
    {
        $parent = Taxonomy::query()->whereKey($id)->value('parent_id');

        return $parent === null ? null : Cast::int($parent);
    }
}
