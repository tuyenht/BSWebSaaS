<?php

namespace App\Modules\Cms\Components;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Contracts\Component;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Theme\Components\ListDisplay;
use App\Modules\Theme\Components\SectionProps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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

    /** The looks that can carry tabs: the card looks (the plain list and the rows have no tab panel). */
    private const array TAB_VARIANTS = ['icon-cards', 'project-cards', 'news-cards'];

    /** How many sub-categories the tab read looks at. */
    private const int TAB_CHILDREN_READ = 12;

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
            'limit' => ['type' => 'int', 'default' => 6, 'min' => ListDisplay::LIMIT_MIN, 'max' => ListDisplay::LIMIT_MAX],
            'variant' => ['type' => 'enum', 'options' => SectionProps::ENTRY_GRID_VARIANTS, 'default' => 'list'],
            'tabs' => ['type' => 'enum', 'options' => SectionProps::ENTRY_GRID_TABS, 'default' => 'off'],
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
        $limit = is_numeric($rawLimit) ? min(ListDisplay::LIMIT_MAX, max(ListDisplay::LIMIT_MIN, (int) $rawLimit)) : 6;
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

        // Tabs: one per published sub-category, drawn only on the card looks and only from two up.
        $tabs = SectionProps::choice($props, 'tabs', SectionProps::ENTRY_GRID_TABS) !== 'off' && in_array($variant, self::TAB_VARIANTS, true)
            ? $this->tabs($taxonomyId, $locale, $limit, $props, $variant)
            : null;
        if ($tabs !== null) {
            return ['props' => $props, 'items' => [], 'variant' => $variant, 'display' => null, 'tabs' => $tabs];
        }

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
        // The alternating rows are always one column of rows, so only the "view all" part of the group counts.
        $display = match (true) {
            $kind !== null => ListDisplay::plan($kind, $props, $items->count(), $limit),
            $variant === 'zigzag' && ListDisplay::applies($props) => ListDisplay::plan('news', ['layout' => 'grid'] + $props, $items->count(), $limit),
            default => null,
        };
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
     * The tabs of the block: the published sub-categories of the block's category, in their own order,
     * each with its newest posts as cards. Three reads for the whole block, never one per tab: the
     * sub-categories (with their names in the page language), the ranked posts of all of them
     * (ROW_NUMBER per sub-category, one row more than shown so a tab learns it has more without a
     * COUNT), and the posts themselves with their eager loads. "Has posts" is read from the ranked
     * rows, not from a category counter (which counts drafts). Null when fewer than two sub-categories
     * have posts: the block is then drawn as a plain list. The `filter` choice draws like `auto` here.
     *
     * @param  array<string, mixed>  $props
     * @return list<array{name: string, items: list<array<string, mixed>>, view_all: bool}>|null
     */
    private function tabs(int $taxonomyId, string $locale, int $limit, array $props, string $variant): ?array
    {
        // A row of tabs is a handful of names; the read is bounded even when a category has many children.
        $children = TaxonomyTranslation::query()
            ->join('taxonomies', 'taxonomies.id', '=', 'taxonomy_translations.taxonomy_id')
            ->where('taxonomies.parent_id', $taxonomyId)
            ->where('taxonomy_translations.locale', $locale)
            ->orderBy('taxonomies.position')
            ->orderBy('taxonomies.id')
            ->limit(self::TAB_CHILDREN_READ)
            ->get(['taxonomy_translations.taxonomy_id', 'taxonomy_translations.name']);
        if ($children->count() < SectionProps::TABS_MIN) {
            return null;
        }

        $ranked = $this->rankedTabPosts(array_values(array_map(fn (mixed $id): int => Cast::int($id), $children->pluck('taxonomy_id')->all())), $limit + 1);
        $named = $children->filter(fn (TaxonomyTranslation $child): bool => isset($ranked[(int) $child->taxonomy_id]))->take(SectionProps::TABS_MAX)->values();
        if ($named->count() < SectionProps::TABS_MIN) {
            return null;
        }

        $entries = Entry::query()
            ->whereIn('id', array_values(array_unique(array_merge(...$named->map(fn (TaxonomyTranslation $child): array => $ranked[(int) $child->taxonomy_id])->all()))))
            ->with(['translations', 'routes', 'sectors.translations'])
            ->get()
            ->keyBy('id');

        $kind = ['icon-cards' => 'icon', 'project-cards' => 'project', 'news-cards' => 'news'][$variant];
        $tabs = [];
        foreach ($named as $child) {
            $ids = $ranked[(int) $child->taxonomy_id];
            // Every tab is a grid; the rail of the display group does not reach into a hidden panel.
            $display = ListDisplay::applies($props) ? ListDisplay::plan($kind, ['layout' => 'grid'] + $props, count($ids), $limit) : null;
            $tabs[] = [
                'name' => $child->name,
                'items' => $this->cards(array_filter(array_map(fn (int $id): ?Entry => $entries->get($id), array_slice($ids, 0, $limit))), $locale, $variant),
                'view_all' => $display['view_all'] ?? true,
            ];
        }

        return $tabs;
    }

    /**
     * The newest published posts of each sub-category, at most $rows per sub-category: category id →
     * post ids, newest first. One query. A post in two sub-categories is in both lists. The single
     * place that decides which posts a tab may show (a later condition, such as "not expired", is added here).
     *
     * @param  list<int>  $taxonomyIds
     * @return array<int, list<int>>
     */
    private function rankedTabPosts(array $taxonomyIds, int $rows): array
    {
        $posts = Entry::query()
            ->join('entry_taxonomies', 'entry_taxonomies.entry_id', '=', 'entries.id')
            ->where('entries.type', 'post')
            ->where('entries.status', 'published')
            ->whereIn('entry_taxonomies.taxonomy_id', $taxonomyIds)
            ->select(['entries.id as entry_id', 'entry_taxonomies.taxonomy_id'])
            ->selectRaw('row_number() over (partition by entry_taxonomies.taxonomy_id order by entries.published_at desc, entries.id desc) as tab_rank');

        $ranked = [];
        foreach (DB::query()->fromSub($posts, 'ranked')->where('tab_rank', '<=', $rows)->orderBy('taxonomy_id')->orderBy('tab_rank')->get(['entry_id', 'taxonomy_id']) as $row) {
            $ranked[Cast::int($row->taxonomy_id)][] = Cast::int($row->entry_id);
        }

        return $ranked;
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
