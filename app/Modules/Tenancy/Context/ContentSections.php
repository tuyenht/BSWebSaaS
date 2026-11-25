<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\ContentSectionCategory;
use Illuminate\Database\Query\JoinClause;

/**
 * Read surface over the current site's content sections (content_sections: key, entry_type; and the
 * categories each one covers, content_section_categories, a category belonging to one section at
 * most) for feature modules, which must not reach into Tenancy\Models
 * (app/Modules/README.md). Read-only; each method is one query, scoped to the current site by
 * BelongsToSite with RLS underneath. Holds no state, so it is safe to inject anywhere under Octane.
 */
final readonly class ContentSections
{
    /**
     * Keys of the sections that file posts, in display order. `entry_type != 'page'` in SQL, so a
     * section with no entry type is not one of them.
     *
     * @return list<string>
     */
    public function postSectionKeys(): array
    {
        $keys = ContentSection::query()
            ->where('entry_type', '!=', 'page')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('key')
            ->all();

        return array_values(array_map(static fn (mixed $key): string => Cast::string($key), $keys));
    }

    /**
     * The categories among $taxonomyIds that a section covers.
     *
     * @param  list<int>  $taxonomyIds
     * @return list<int>
     */
    public function boundAmong(array $taxonomyIds): array
    {
        $bound = ContentSectionCategory::query()->whereIn('taxonomy_id', $taxonomyIds)->pluck('taxonomy_id')->all();

        return array_values(array_map(static fn (mixed $id): int => Cast::int($id), $bound));
    }

    /**
     * The section that covers $taxonomyId itself (one at most), or null. `entry_type` is `page`,
     * `post` or null.
     *
     * @return array{key: string, entry_type: string|null}|null
     */
    public function boundTo(int $taxonomyId): ?array
    {
        return $this->boundToEach([$taxonomyId])[$taxonomyId] ?? null;
    }

    /**
     * boundTo() for many categories in one query: for each of $taxonomyIds a section covers, that
     * section, keyed by the category's id.
     *
     * @param  list<int>  $taxonomyIds
     * @return array<int, array{key: string, entry_type: string|null}>
     */
    public function boundToEach(array $taxonomyIds): array
    {
        if ($taxonomyIds === []) {
            return [];
        }

        $out = [];
        // The site scope sits on the root table; the join pins the section to the same site.
        $rows = ContentSectionCategory::query()
            ->join('content_sections as cs', fn (JoinClause $join) => $join->on('cs.id', '=', 'content_section_categories.content_section_id')
                ->on('cs.site_id', '=', 'content_section_categories.site_id'))
            ->whereIn('content_section_categories.taxonomy_id', array_values(array_unique($taxonomyIds)))
            ->orderBy('content_section_categories.taxonomy_id') // one stable order, whatever plan the database picks
            ->toBase()
            ->get(['cs.key', 'cs.entry_type', 'content_section_categories.taxonomy_id']);
        foreach ($rows as $row) {
            $out[Cast::int($row->taxonomy_id)] = ['key' => Cast::string($row->key), 'entry_type' => Cast::stringOrNull($row->entry_type)];
        }

        return $out;
    }

    /**
     * The FIRST category the section with $key covers (content_sections.taxonomy_id: the one a
     * single-category kind such as the catalog names), or null when there is no such section or it
     * covers none.
     */
    public function taxonomyIdOf(string $key): ?int
    {
        $id = ContentSection::query()->where('key', $key)->value('taxonomy_id');

        return $id === null ? null : Cast::int($id);
    }
}
