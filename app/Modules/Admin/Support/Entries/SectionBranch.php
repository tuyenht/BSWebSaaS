<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

/**
 * The categories a section files its posts under, as SQL: the section's own category and every
 * category below it, down the parent chain (the source of truth — the ltree path may not be
 * written yet). Membership follows SectionResolver, where the NEAREST bound category wins, so the
 * walk stops at a sub-category that another section is built on: its posts belong to that
 * section. The CYCLE clause ends a parent loop.
 *
 * Every admin surface that lists or counts "a section's posts" (the posts list, its tabs and
 * facets, the dashboard, the approval settings) reads this one definition, so they agree with
 * each other and with the storefront, where a section's list shows its sub-categories' posts.
 * It is plain SQL with the site bound on every table, so it runs once per statement as an
 * uncorrelated subquery (no query of its own, none per row).
 */
final class SectionBranch
{
    /**
     * A recursive CTE `branch (root_id, id)` for several sections at once; rows with `looped` set
     * closed a parent loop and are not members.
     *
     * @param  non-empty-list<int>  $rootIds
     * @return array{0: literal-string, 1: list<int>}
     */
    public static function with(int $siteId, array $rootIds): array
    {
        /** @var literal-string $marks placeholders only; the ids go in the bindings */
        $marks = implode(', ', array_fill(0, count($rootIds), '?'));

        $sql = <<<SQL
            WITH RECURSIVE branch (root_id, id) AS (
                SELECT t.id, t.id FROM taxonomies AS t WHERE t.site_id = ? AND t.id IN ({$marks})
                UNION ALL
                SELECT branch.root_id, child.id
                FROM taxonomies AS child JOIN branch ON child.parent_id = branch.id
                WHERE child.site_id = ?
                  AND NOT EXISTS (SELECT 1 FROM content_sections AS cs WHERE cs.site_id = ? AND cs.taxonomy_id = child.id)
            ) CYCLE id SET looped USING trail

            SQL;

        return [$sql, [$siteId, ...$rootIds, $siteId, $siteId]];
    }

    /**
     * The ids of one section's categories, as a subquery for `taxonomy_id IN (…)`.
     *
     * @return array{0: literal-string, 1: list<int>}
     */
    public static function ids(int $siteId, int $rootId): array
    {
        [$sql, $bindings] = self::with($siteId, [$rootId]);

        return [$sql.'SELECT branch.id FROM branch WHERE NOT branch.looped', $bindings];
    }
}
