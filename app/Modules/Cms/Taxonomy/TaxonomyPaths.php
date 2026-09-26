<?php

declare(strict_types=1);

namespace App\Modules\Cms\Taxonomy;

use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the ltree `taxonomies.path` in step with `parent_id`, the source of truth: a category's path
 * is its ancestors' ids then its own, joined by dots (`12.45.78`). Structural and internal only —
 * public URLs are built from slugs (PathBuilder), never from this. A path is always computed from
 * the parent chain, never copied from a parent's stored path, because rows written before the
 * category write path existed carry none.
 *
 * Both writes are one statement each and touch only rows whose path is wrong, so re-running them is
 * free. The explicit `site_id` keeps every statement on the current site's index range (RLS is the
 * net underneath). Runs inside the tenant context.
 */
final readonly class TaxonomyPaths
{
    public function __construct(private CurrentSite $site) {}

    /** Rewrite the path of a category and every category under it. Returns the rows written. */
    public function refreshBranch(int $id): int
    {
        $site = $this->site->id();
        $root = implode('.', [...TaxonomyTree::ancestorsOf($id), $id]);

        return DB::update(<<<'SQL'
            WITH RECURSIVE branch (id, path) AS (
                SELECT id, CAST(? AS ltree) FROM taxonomies WHERE site_id = ? AND id = ?
                UNION ALL
                SELECT child.id, branch.path || child.id::text
                FROM taxonomies AS child JOIN branch ON child.parent_id = branch.id
                WHERE child.site_id = ?
            ) CYCLE id SET looped USING trail
            UPDATE taxonomies SET path = branch.path
            FROM branch
            WHERE taxonomies.site_id = ? AND taxonomies.id = branch.id AND NOT branch.looped
              AND taxonomies.path IS DISTINCT FROM branch.path
            SQL, [$root, $site, $id, $site, $site]);
    }

    /**
     * Rewrite every path of the current site from the top-level categories down. A category caught
     * in a parent loop is unreachable from any root and keeps what it has. Returns the rows written.
     */
    public function rebuild(): int
    {
        $site = $this->site->id();

        return DB::update(<<<'SQL'
            WITH RECURSIVE tree (id, path) AS (
                SELECT id, CAST(id::text AS ltree) FROM taxonomies WHERE site_id = ? AND parent_id IS NULL
                UNION ALL
                SELECT child.id, tree.path || child.id::text
                FROM taxonomies AS child JOIN tree ON child.parent_id = tree.id
                WHERE child.site_id = ?
            ) CYCLE id SET looped USING trail
            UPDATE taxonomies SET path = tree.path
            FROM tree
            WHERE taxonomies.site_id = ? AND taxonomies.id = tree.id AND NOT tree.looped
              AND taxonomies.path IS DISTINCT FROM tree.path
            SQL, [$site, $site, $site]);
    }
}
