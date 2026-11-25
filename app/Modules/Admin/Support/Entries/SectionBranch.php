<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

/**
 * The categories a section files its posts under, as SQL: every category the section covers
 * (content_section_categories: one or several, side by side) and every category below each of them,
 * down the parent chain (the source of truth — the ltree path may not be written yet). Membership
 * follows SectionResolver, where the NEAREST covered category wins, so the walk stops at a
 * sub-category that another section covers: its posts belong to that section. The CYCLE clause ends
 * a parent loop.
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
     * A recursive CTE `branch (section_root, id)` from several categories at once (each one the root
     * of its own walk); rows with `looped` set closed a parent loop and are not members.
     *
     * @param  non-empty-list<int>  $rootIds
     * @return array{0: literal-string, 1: list<int>}
     */
    public static function with(int $siteId, array $rootIds): array
    {
        /** @var literal-string $marks placeholders only; the ids go in the bindings */
        $marks = implode(', ', array_fill(0, count($rootIds), '?'));

        return self::walk('section_root', "SELECT t.id, t.id FROM taxonomies AS t WHERE t.site_id = ? AND t.id IN ({$marks})", [$siteId, ...$rootIds], $siteId);
    }

    /**
     * A recursive CTE `branch (section_id, id)` for several sections at once: each section's covered
     * categories and what lies below them; rows with `looped` set are not members.
     *
     * @param  non-empty-list<int>  $sectionIds
     * @return array{0: literal-string, 1: list<int>}
     */
    public static function ofSections(int $siteId, array $sectionIds): array
    {
        /** @var literal-string $marks placeholders only; the ids go in the bindings */
        $marks = implode(', ', array_fill(0, count($sectionIds), '?'));

        return self::walk(
            'section_id',
            'SELECT csc.content_section_id, t.id FROM content_section_categories AS csc'
                ." JOIN taxonomies AS t ON t.id = csc.taxonomy_id AND t.site_id = ? WHERE csc.site_id = ? AND csc.content_section_id IN ({$marks})",
            [$siteId, $siteId, ...$sectionIds],
            $siteId,
        );
    }

    /**
     * The ids of one section's categories, as a subquery for `taxonomy_id IN (…)`.
     *
     * @return array{0: literal-string, 1: list<int>}
     */
    public static function idsOfSection(int $siteId, int $sectionId): array
    {
        [$sql, $bindings] = self::ofSections($siteId, [$sectionId]);

        return [$sql.'SELECT branch.id FROM branch WHERE NOT branch.looped', $bindings];
    }

    /**
     * @param  literal-string  $column  the first column's name
     * @param  literal-string  $anchor  the non-recursive term: (that column, a category id) rows
     * @param  list<int>  $anchorBindings
     * @return array{0: literal-string, 1: list<int>}
     */
    private static function walk(string $column, string $anchor, array $anchorBindings, int $siteId): array
    {
        $sql = <<<SQL
            WITH RECURSIVE branch ({$column}, id) AS (
                {$anchor}
                UNION ALL
                SELECT branch.{$column}, child.id
                FROM taxonomies AS child JOIN branch ON child.parent_id = branch.id
                WHERE child.site_id = ?
                  AND NOT EXISTS (SELECT 1 FROM content_section_categories AS csc WHERE csc.site_id = ? AND csc.taxonomy_id = child.id)
            ) CYCLE id SET looped USING trail

            SQL;

        return [$sql, [...$anchorBindings, $siteId, $siteId]];
    }
}
