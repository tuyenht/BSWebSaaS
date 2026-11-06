<?php

namespace App\Modules\Cms\Components;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Contracts\Component;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Theme\Components\ListDisplay;
use App\Modules\Theme\Components\SectionProps;
use Illuminate\Database\Eloquent\Builder;

/**
 * A DATA-BOUND grid of published posts in ONE taxonomy (selected by its localized slug),
 * rendered as rich cards (image / title / short outcome / sector). Like LatestPostsComponent
 * it pulls from a different object than the page and is auto-scoped to the current site (RLS);
 * unlike it, it filters by taxonomy and carries a richer card. Reusable — drives the An Vinh
 * projects (du-an) grid, but takes any taxonomy_slug.
 *
 * `variant` picks the look: the default list, icon cards (services), project cards or news cards
 * (the same cards as the category list pages). Every card look reads the same query; news cards add
 * the site's time zone for their dates (one settings read).
 */
final readonly class EntryGridComponent implements Component
{
    /** Cards display ~480px wide; 640 (the next width on the Worker whitelist) keeps them crisp on retina. */
    private const int CARD_WIDTH = 640;

    /** The widths a card photo offers through srcset. */
    public const array CARD_SRCSET_WIDTHS = [320, 640, 960, 1280];

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
            'variant' => ['type' => 'enum', 'options' => SectionProps::ENTRY_GRID_VARIANTS, 'default' => 'list'],
            'description' => ['type' => 'textarea'],
            'link_label' => ['type' => 'text'],
            'view_all_label' => ['type' => 'text'],
            'view_all_url' => ['type' => 'url'],
        ] + SectionProps::LIST_DISPLAY;
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
        $variant = SectionProps::choice($props, 'variant', SectionProps::ENTRY_GRID_VARIANTS);

        // Resolve the taxonomy by its localized slug (RLS-scoped). Unknown/empty slug → empty grid.
        // Slugs are stored lower case; read as text the column compares with a leakproof operator,
        // which RLS lets the (site_id, locale, (slug::text)) index serve (a citext `=` it does not).
        $taxonomyTranslation = $slug !== ''
            ? TaxonomyTranslation::query()->where('locale', $locale)->whereRaw('slug::text = ?', [mb_strtolower($slug)])->first()
            : null;

        if ($taxonomyTranslation === null) {
            return ['props' => $props, 'items' => [], 'variant' => $variant, 'display' => null];
        }

        $taxonomyId = $taxonomyTranslation->taxonomy_id;

        $items = Entry::query()
            ->where('type', 'post')
            ->where('status', 'published')
            ->whereHas('taxonomies', fn (Builder $query) => $query->whereKey($taxonomyId))
            ->with(['translations', 'routes', 'sectors.translations'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            // A block with the display group reads one row more than it shows: that row is how "view
            // all" learns there is more, with no COUNT(*).
            ->limit($limit + (ListDisplay::applies($props) ? 1 : 0))
            ->get();

        $kind = ['icon-cards' => 'icon', 'project-cards' => 'project', 'news-cards' => 'news'][$variant] ?? null;
        $display = $kind !== null ? ListDisplay::plan($kind, $props, $items->count(), $limit) : null;
        if ($display !== null) {
            $items = $items->take($display['shown']);
        } elseif ($items->count() > $limit) {
            $items = $items->take($limit);
        }

        if ($variant !== 'list') {
            return ['props' => $props, 'items' => $this->cards($items, $locale, $variant), 'variant' => $variant, 'display' => $display];
        }

        $items = $items
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

        return ['props' => $props, 'items' => $items, 'variant' => $variant, 'display' => null];
    }

    /**
     * The card shape the shared card partial reads: title, link, photo (or glyph), excerpt, date and
     * a label. No id ever leaves the server.
     *
     * @param  iterable<Entry>  $entries
     * @return list<array{title: string, url: string, image: string, srcset: string|null, icon: string, excerpt: string, date: string, datetime: string, label: string}>
     */
    private function cards(iterable $entries, string $locale, string $variant): array
    {
        // Resolved per call, not injected: this component lives in a boot-time registry, and the
        // site's clock is request state (scoped).
        $time = $variant === 'news-cards' ? app(SiteTime::class) : null;
        $cards = [];
        foreach ($entries as $entry) {
            $translation = $entry->translationFor($locale);
            $route = $entry->routes->firstWhere('locale', $locale);
            $sector = $entry->sectors->first();
            $meta = $entry->meta;
            $image = isset($meta['image_url']) && is_string($meta['image_url']) ? $meta['image_url'] : '';
            $isKey = $image !== '' && ! str_contains($image, '://') && str_contains($image, '/');
            $isGlyph = $image !== '' && ! str_contains($image, '/') && ! str_contains($image, '.') && ! str_contains($image, ':');
            $published = $time?->forDisplay($entry->published_at);

            $cards[] = [
                'title' => $translation instanceof EntryTranslation ? $translation->title : '',
                'url' => $route !== null ? $route->path : '',
                'image' => $isGlyph ? '' : $this->resolveImage($image),
                'srcset' => $isKey ? $this->media->srcset($image, self::CARD_SRCSET_WIDTHS) : null,
                'icon' => $isGlyph ? $image : '',
                'excerpt' => $translation instanceof EntryTranslation ? ($translation->excerpt ?? '') : '',
                'date' => $published?->format('d/m/Y') ?? '',
                'datetime' => $published?->format('Y-m-d') ?? '',
                'label' => $sector !== null ? ($sector->translationFor($locale)->name ?? '') : '',
            ];
        }

        return $cards;
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
