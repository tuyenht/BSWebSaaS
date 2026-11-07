<?php

declare(strict_types=1);

namespace App\Modules\Cms\Taxonomy;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Shared\Support\Cast;
use App\Modules\Theme\Rendering\TemplateCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Which list and detail layout a category really uses. The stored value is a layout name, null
 * (follow the theme) or TemplateCatalog::PARENT (follow the category above); this turns it into a
 * layout name that fits the category's role and kind, or null for the theme's default, BEFORE
 * anything looks a view up. A stored name of the wrong role or kind, or one the theme no longer
 * has, also comes out as null. A category that stores a plain name costs no query; one that
 * follows its parent costs one (the parent chain, read by parent_id with a depth cap, never by
 * path, which may be empty on old rows).
 */
final class CategoryTemplates
{
    /** The layout the category's list page renders on, null for the theme's default. */
    public function list(Taxonomy $category): ?string
    {
        return $this->resolve($category, 'list_template', TemplateCatalog::ROLE_LIST);
    }

    /**
     * The layout the category's posts render on, null for the theme's default. A product category
     * keeps no post layout.
     */
    public function detail(Taxonomy $category): ?string
    {
        return $this->resolve($category, 'detail_template', TemplateCatalog::ROLE_POST);
    }

    /**
     * The category a post's "see the open positions" link points to: the nearest one, from the
     * post's own category upwards, that itself stores a job list layout; the post's own category
     * when there is none.
     */
    public function jobListTarget(Taxonomy $category): Taxonomy
    {
        foreach ($this->chain($category, true) as $row) {
            $stored = $row['list_template'];
            if ($stored !== null && TemplateCatalog::isJob($stored) && TemplateCatalog::fits($stored, TemplateCatalog::ROLE_LIST, TemplateCatalog::kindOf($category->vocabulary))) {
                return $row['id'] === $category->id ? $category : (Taxonomy::query()->find($row['id']) ?? $category);
            }
        }

        return $category;
    }

    private function resolve(Taxonomy $category, string $column, string $role): ?string
    {
        $kind = TemplateCatalog::kindOf($category->vocabulary);
        $stored = $category->getAttribute($column);
        if (! is_string($stored) || $stored === '') {
            return null;
        }
        if ($stored !== TemplateCatalog::PARENT) {
            return TemplateCatalog::fits($stored, $role, $kind) ? $stored : null;
        }
        if ($category->parent_id === null) {
            return null;
        }

        foreach ($this->chain($category, false) as $row) {
            $value = $column === 'list_template' ? $row['list_template'] : $row['detail_template'];
            if ($value !== null && $value !== TemplateCatalog::PARENT && TemplateCatalog::fits($value, $role, $kind)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The category (when asked) and the ones above it, nearest first, in one read of this site's
     * rows; a cycle in bad data ends at the depth cap.
     *
     * @return list<array{id: int, list_template: string|null, detail_template: string|null}>
     */
    private function chain(Taxonomy $category, bool $withSelf): array
    {
        $start = $withSelf ? $category->id : $category->parent_id;
        if ($start === null) {
            return [];
        }

        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE chain (id, parent_id, list_template, detail_template, depth) AS (
                SELECT t.id, t.parent_id, t.list_template, t.detail_template, 1
                FROM taxonomies t
                WHERE t.site_id = ? AND t.id = ?
                UNION ALL
                SELECT up.id, up.parent_id, up.list_template, up.detail_template, chain.depth + 1
                FROM taxonomies up
                JOIN chain ON up.id = chain.parent_id
                WHERE up.site_id = ? AND chain.depth < ?
            )
            SELECT id, list_template, detail_template FROM chain ORDER BY depth
            SQL, [$category->site_id, $start, $category->site_id, TaxonomyRules::MAX_DEPTH]);

        $chain = [];
        foreach ($rows as $row) {
            $columns = Cast::arr((array) $row);
            $chain[] = [
                'id' => Cast::int($columns['id'] ?? null),
                'list_template' => Cast::stringOrNull($columns['list_template'] ?? null),
                'detail_template' => Cast::stringOrNull($columns['detail_template'] ?? null),
            ];
        }

        return $chain;
    }
}
