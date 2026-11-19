<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\DB;

/**
 * The rules of a site's page tree, read through `parent_id`: at most MAX_DEPTH levels, the Home page
 * (HomePages) has no sub-pages, and a page goes with its whole branch. Walks go one query per level;
 * a parent loop in bad data ends a walk instead of spinning. Runs inside the tenant context.
 */
final class PageRules
{
    /** A page tree holds at most three levels: a top page, its sub-pages and theirs. */
    public const int MAX_DEPTH = 3;

    /** The deepest branch one walk reads (subPages()): far beyond MAX_DEPTH, for trees made before it held. */
    public const int WALK_DEPTH = 12;

    /** The most pages one walk reads (subPages()). */
    public const int WALK_SIZE = 1000;

    /** Hard stop for a walk up a parent chain a loop could have corrupted. */
    private const int CHAIN_LIMIT = 32;

    /**
     * Serialise changes to a site's page tree until the surrounding transaction ends: a move takes it
     * before its checks, so two moves of different pages can never each pass the loop and depth checks
     * against a tree the other is changing. Transaction-scoped, so it is safe behind PgBouncer.
     */
    public static function lock(int $siteId): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['pages:'.$siteId]);
    }

    /** Is this page the website's Home page (HomePages)? */
    public static function isHome(Entry $page): bool
    {
        return HomePages::is($page);
    }

    /**
     * The ids of the pages above a page, nearest first, the page itself excluded. A parent in the Trash
     * still counts: the page stays under it, and restoring it brings the level back.
     *
     * @return list<int>
     */
    public static function ancestorsOf(Entry $page): array
    {
        $ancestors = [];
        $seen = [$page->id => true];
        $parent = $page->parent_id;

        while ($parent !== null && ! isset($seen[$parent]) && count($ancestors) < self::CHAIN_LIMIT) {
            $ancestors[] = $parent;
            $seen[$parent] = true;
            $next = Entry::withTrashed()->whereKey($parent)->value('parent_id');
            $parent = $next === null ? null : Cast::int($next);
        }

        return $ancestors;
    }

    /** The level a page sits at: 1 for a top page. */
    public static function levelOf(Entry $page): int
    {
        return count(self::ancestorsOf($page)) + 1;
    }

    /**
     * Levels in a page's branch, the page included: 1 for a page without sub-pages. Sub-pages in the
     * Trash count: a trashed sub-page keeps its parent, so moving the parent deeper and then restoring
     * the sub-page would otherwise make a level too many. Null when the branch is too big to read
     * (subPages()).
     */
    public static function heightOf(Entry $page): ?int
    {
        $levels = self::subPages($page, self::WALK_SIZE, withTrashed: true);

        return $levels === null ? null : count($levels) + 1;
    }

    /**
     * The pages under this one, level by level (index 0: its sub-pages, 1: theirs, …), each level in
     * id order, with a cap on depth and size against a looped or huge tree. A branch past the cap — more
     * than $cap pages in all, or deeper than WALK_DEPTH levels — is null: the whole branch is too big to
     * act on at once, so no part of it is ever moved without the rest.
     *
     * @return list<list<Entry>>|null
     */
    public static function subPages(Entry $page, int $cap, bool $withTrashed = false): ?array
    {
        $levels = [];
        $found = 0;
        $seen = [$page->id => true];
        $frontier = [$page->id];
        for ($depth = 0; $frontier !== []; $depth++) {
            $room = $cap - $found;
            // one more than there is room for: finding it means the branch is over the cap
            $children = ($withTrashed ? Entry::withTrashed() : Entry::query())
                ->where('type', 'page')->whereIn('parent_id', $frontier)->orderBy('id')
                ->limit($room + 1)
                ->get(['id', 'site_id', 'ulid', 'type', 'status', 'parent_id', 'author_id', 'deleted_at', 'updated_at']);
            // past the size cap, or a level below the deepest one a branch may have (a WALK_DEPTH-level
            // branch passes: the next read only looks for pages under it)
            if ($children->count() > $room || ($depth >= self::WALK_DEPTH && $children->isNotEmpty())) {
                return null;
            }
            $frontier = [];
            $level = [];
            foreach ($children as $child) {
                if (! isset($seen[$child->id])) {
                    $seen[$child->id] = true;
                    $level[] = $child;
                    $frontier[] = $child->id;
                }
            }
            if ($level !== []) {
                $levels[] = $level;
                $found += count($level);
            }
        }

        return $levels;
    }
}
