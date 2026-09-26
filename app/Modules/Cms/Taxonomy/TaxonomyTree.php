<?php

declare(strict_types=1);

namespace App\Modules\Cms\Taxonomy;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Shared\Support\Cast;

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

    private static function parentOf(int $id): ?int
    {
        $parent = Taxonomy::query()->whereKey($id)->value('parent_id');

        return $parent === null ? null : Cast::int($parent);
    }
}
