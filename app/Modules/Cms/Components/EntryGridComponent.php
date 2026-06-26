<?php

namespace App\Modules\Cms\Components;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Shared\Contracts\Component;
use Illuminate\Database\Eloquent\Builder;

/**
 * A DATA-BOUND grid of published posts in ONE taxonomy (selected by its localized slug),
 * rendered as rich cards (image / title / short outcome / sector). Like LatestPostsComponent
 * it pulls from a different object than the page and is auto-scoped to the current site (RLS);
 * unlike it, it filters by taxonomy and carries a richer card. Reusable — drives the An Vinh
 * projects (du-an) grid, but takes any taxonomy_slug.
 */
final class EntryGridComponent implements Component
{
    public function view(): string
    {
        return 'theme::sections.entry_grid';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function schema(): array
    {
        // `locale` is server-resolved (site default), not author-set → not in the schema.
        return [
            'heading' => ['type' => 'text'],
            'taxonomy_slug' => ['type' => 'text'],
            'limit' => ['type' => 'int', 'default' => 6],
        ];
    }

    /**
     * @param  array<string, mixed>  $props  supports: heading, taxonomy_slug, limit, locale
     * @return array<string, mixed>
     */
    public function data(array $props): array
    {
        // Coerce like LatestPostsComponent: Livewire number inputs hydrate as strings; clamp
        // 1..50 so a careless/forged limit can't issue an unbounded query.
        $rawLimit = $props['limit'] ?? 6;
        $limit = is_numeric($rawLimit) ? min(50, max(1, (int) $rawLimit)) : 6;
        $locale = is_string($props['locale'] ?? null) ? $props['locale'] : 'vi';
        $slug = is_string($props['taxonomy_slug'] ?? null) ? $props['taxonomy_slug'] : '';

        // Resolve the taxonomy by its localized slug (RLS-scoped). Unknown/empty slug → empty grid.
        $taxonomyTranslation = $slug !== ''
            ? TaxonomyTranslation::query()->where('locale', $locale)->where('slug', $slug)->first()
            : null;

        if ($taxonomyTranslation === null) {
            return ['props' => $props, 'items' => []];
        }

        $taxonomyId = $taxonomyTranslation->taxonomy_id;

        $items = Entry::query()
            ->where('type', 'post')
            ->where('status', 'published')
            ->whereHas('taxonomies', fn (Builder $query) => $query->whereKey($taxonomyId))
            ->with(['translations', 'routes', 'sectors.translations'])
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get()
            ->map(function (Entry $entry) use ($locale): array {
                $translation = $entry->translationFor($locale);
                $route = $entry->routes->firstWhere('locale', $locale);
                $sector = $entry->sectors->first();
                $sectorTranslation = $sector !== null ? $sector->translationFor($locale) : null;
                $meta = $entry->meta;
                $image = isset($meta['image_url']) && is_string($meta['image_url']) ? $meta['image_url'] : '';

                return [
                    'title' => $translation !== null ? $translation->title : '',
                    'url' => $route !== null ? $route->path : '',
                    'image' => $image,
                    'short_outcome' => $translation !== null ? ($translation->excerpt ?? '') : '',
                    'sector' => $sectorTranslation !== null ? $sectorTranslation->name : '',
                ];
            })
            ->all();

        return ['props' => $props, 'items' => $items];
    }
}
