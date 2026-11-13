<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Models\ContentSection;

/**
 * Read surface over the current site's content sections (content_sections: key, entry_type and the
 * category a section is built on) for feature modules, which must not reach into Tenancy\Models
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
     * The categories among $taxonomyIds that a section is built on.
     *
     * @param  list<int>  $taxonomyIds
     * @return list<int>
     */
    public function boundAmong(array $taxonomyIds): array
    {
        $bound = ContentSection::query()->whereIn('taxonomy_id', $taxonomyIds)->pluck('taxonomy_id')->all();

        return array_values(array_map(static fn (mixed $id): int => Cast::int($id), $bound));
    }

    /**
     * The section built directly on $taxonomyId (the first by display order when several are), or
     * null. `entry_type` is `page`, `post` or null.
     *
     * @return array{key: string, entry_type: string|null}|null
     */
    public function boundTo(int $taxonomyId): ?array
    {
        $section = ContentSection::query()
            ->whereNotNull('taxonomy_id')
            ->where('taxonomy_id', $taxonomyId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first(['key', 'entry_type']);

        if (! $section instanceof ContentSection) {
            return null;
        }

        return ['key' => $section->key, 'entry_type' => $section->entry_type];
    }

    /**
     * boundTo() for many categories in one query: for each of $taxonomyIds a section is built on,
     * that section (the first by display order when several are), keyed by the category's id.
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
        $rows = ContentSection::query()
            ->whereIn('taxonomy_id', array_values(array_unique($taxonomyIds)))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['key', 'entry_type', 'taxonomy_id']);
        foreach ($rows as $section) {
            $out[Cast::int($section->taxonomy_id)] ??= ['key' => $section->key, 'entry_type' => $section->entry_type];
        }

        return $out;
    }

    /**
     * The category the section with $key is built on, or null when there is no such section or it
     * is not built on one.
     */
    public function taxonomyIdOf(string $key): ?int
    {
        $id = ContentSection::query()->where('key', $key)->value('taxonomy_id');

        return $id === null ? null : Cast::int($id);
    }
}
