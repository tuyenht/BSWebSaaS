<?php

namespace App\Modules\Cms\Components;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Contracts\Component;
use Illuminate\Database\Eloquent\Builder;

/**
 * A DATA-BOUND grid of published posts in ONE taxonomy (selected by its localized slug),
 * rendered as rich cards (image / title / short outcome / sector). Like LatestPostsComponent
 * it pulls from a different object than the page and is auto-scoped to the current site (RLS);
 * unlike it, it filters by taxonomy and carries a richer card. Reusable — drives the An Vinh
 * projects (du-an) grid, but takes any taxonomy_slug.
 */
final readonly class EntryGridComponent implements Component
{
    /** Cards display ~480px wide; 640 (the next width on the Worker whitelist) keeps them crisp on retina. */
    private const int CARD_WIDTH = 640;

    public function __construct(private MediaUrlResolver $media) {}

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
        // Slugs are stored lower case; read as text the column compares with a leakproof operator,
        // which RLS lets the (site_id, locale, (slug::text)) index serve (a citext `=` it does not).
        $taxonomyTranslation = $slug !== ''
            ? TaxonomyTranslation::query()->where('locale', $locale)->whereRaw('slug::text = ?', [mb_strtolower($slug)])->first()
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
                    'title' => $translation instanceof EntryTranslation ? $translation->title : '',
                    'url' => $route !== null ? $route->path : '',
                    'image' => $this->resolveImage($image),
                    'short_outcome' => $translation instanceof EntryTranslation ? ($translation->excerpt ?? '') : '',
                    'sector' => $sectorTranslation !== null ? $sectorTranslation->name : '',
                ];
            })
            ->all();

        return ['props' => $props, 'items' => $items];
    }

    /**
     * A card image is one of three shapes: a content-hash storage KEY (no scheme, has a slash) →
     * derive a width-sized Worker URL via the resolver (single switch point, no domain stored); a
     * glyph key (no slash) → leave it for the blade to render as an inline icon; an absolute URL
     * (has a scheme) → leave it as-is. Forged values degrade safely: a key-shaped string just
     * becomes a same-origin media URL (the Worker 404s a non-existent key — never an open redirect).
     */
    private function resolveImage(string $image): string
    {
        if ($image === '' || str_contains($image, '://') || ! str_contains($image, '/')) {
            return $image;
        }

        return $this->media->url($image, self::CARD_WIDTH);
    }
}
