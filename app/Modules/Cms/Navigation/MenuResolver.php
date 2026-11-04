<?php

namespace App\Modules\Cms\Navigation;

use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\MenuItemTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\PathBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves the menu bound to a theme region for the active locale into a primitive tree
 * the theme renders (design.md §13). Picks the menu via menu_assignments (region × locale,
 * falling back to the site's default locale so an untranslated region is never a dead-end),
 * BATCH-resolves each internal target's URL through the routes table in one query (no N+1),
 * drops items whose target a visitor cannot open — no route, or a page taken offline or
 * trashed, whose route row stays behind — and builds a depth-guarded tree. Link kinds owned
 * by other modules (Catalog's `product`) come in through {@see MenuTargets}, so Cms imports
 * none of them. Returns nested arrays (NOT Cms models) so the Theme module stays
 * Cms-agnostic. Every query is RLS-scoped to the current site.
 */
final readonly class MenuResolver
{
    /** Nesting cap — also the cycle guard for a malformed parent_id chain. */
    public function __construct(
        private PathBuilder $paths,
        private MenuTargets $targets,
    ) {}

    /**
     * @return list<array<string, mixed>> nodes: {label, url, active, attrs, children}
     */
    public function resolve(string $region, string $locale, string $defaultLocale, string $currentPath = '/'): array
    {
        return $this->resolveMany([$region], $locale, $defaultLocale, $currentPath)[$region] ?? [];
    }

    /**
     * Several regions at once (the header's and the footer's menus): one assignments query for all
     * of them, one items query (+ translations) for every chosen menu, and the URL/title lookups
     * batched per language — so a second region costs no extra round trips for its lookups.
     *
     * @param  list<string>  $regions
     * @return array<string, list<array<string, mixed>>> region → its tree (empty when no menu is bound)
     */
    public function resolveMany(array $regions, string $locale, string $defaultLocale, string $currentPath = '/'): array
    {
        $regions = array_values(array_unique($regions));
        $trees = array_fill_keys($regions, []);
        $chosen = $this->menusFor($regions, $locale, $defaultLocale);

        if ($chosen === []) {
            return $trees;
        }

        /** @var Collection<int, MenuItem> $items */
        $items = MenuItem::query()
            ->whereIn('menu_id', array_values(array_unique(array_column($chosen, 'menu'))))
            ->with('translations')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        // Resolve labels + URLs in the locale each chosen menu is actually assigned under: a
        // default-locale fallback menu must be rendered in the default locale, not the
        // requested one (otherwise every label/URL would miss and the menu would drop out).
        $lookups = [];   // effective locale → [urls, titles]
        foreach (array_unique(array_column($chosen, 'locale')) as $effective) {
            $menuIds = array_column(array_filter($chosen, fn (array $pick): bool => $pick['locale'] === $effective), 'menu');
            $subset = $items->whereIn('menu_id', $menuIds);
            $lookups[$effective] = [$this->resolveUrls($subset, $effective), $this->resolveFallbackTitles($subset, $effective)];
        }

        foreach ($chosen as $region => $pick) {
            [$urls, $titles] = $lookups[$pick['locale']];
            $menuItems = $items->where('menu_id', $pick['menu'])->values();
            $trees[$region] = $this->buildTree($menuItems, null, $pick['locale'], $currentPath, $urls, $titles, 0);
        }

        return $trees;
    }

    /**
     * The menu each region shows for the active locale, plus the locale it was found under. For
     * each locale in turn — the active one, then the site's default, so an untranslated region is
     * never a dead-end — a region shows its own menu, or, when it follows another region
     * ("Dùng trình đơn chung"), that region's own menu in the SAME locale: the follow is read
     * before the fallback to the default language. One hop only: a followed region that itself
     * follows (or shows nothing) leaves the region to the next locale. One query for every region
     * and every region they follow. A region with no menu in either locale is left out.
     *
     * @param  list<string>  $regions
     * @return array<string, array{menu: int, locale: string}>
     */
    private function menusFor(array $regions, string $locale, string $defaultLocale): array
    {
        $locales = array_values(array_unique([$locale, $defaultLocale]));
        $assignments = MenuAssignment::query()
            ->whereIn('locale', $locales)
            ->where(fn (Builder $query) => $query
                ->whereIn('region_key', $regions)
                ->orWhereIn('region_key', MenuAssignment::query()
                    ->whereIn('region_key', $regions)
                    ->whereIn('locale', $locales)
                    ->whereNotNull('same_as_region')
                    ->select('same_as_region')))
            ->orderBy('id')
            ->get(['region_key', 'locale', 'menu_id', 'same_as_region']);

        /** @var array<string, array<string, MenuAssignment>> $rows region → locale → its row */
        $rows = [];
        foreach ($assignments as $assignment) {
            $rows[$assignment->region_key][$assignment->locale] ??= $assignment;
        }

        $chosen = [];
        foreach ($regions as $region) {
            foreach ($locales as $candidate) {
                $row = $rows[$region][$candidate] ?? null;
                if ($row !== null && $row->menu_id === null && $row->same_as_region !== null) {
                    $row = $rows[$row->same_as_region][$candidate] ?? null;
                }
                if ($row !== null && $row->menu_id !== null) {
                    $chosen[$region] = ['menu' => $row->menu_id, 'locale' => $candidate];
                    break;
                }
            }
        }

        return $chosen;
    }

    /**
     * @param  Collection<int, MenuItem>  $items
     * @return array<int, string|null> itemId → resolved URL (null = unresolved/orphan/unreachable)
     */
    private function resolveUrls(Collection $items, string $locale): array
    {
        $targets = [];   // link type → target ids
        foreach ($items as $item) {
            if ($item->routable_id !== null && $this->routeColumn($item->link_type) !== null) {
                $targets[$item->link_type][] = $item->routable_id;
            }
        }

        $paths = $this->loadRoutePaths($targets, $locale);

        $urls = [];
        foreach ($items as $item) {
            $urls[$item->id] = match (true) {
                $item->link_type === 'url' => $this->sanitizeUrl($item->url),   // external link — never locale-prefixed
                $item->link_type === 'dynamic' => $this->internalUrl($this->resolveDynamic($item->dynamic_key), $locale),
                $item->routable_id !== null => $this->internalUrl($paths[$item->link_type][$item->routable_id] ?? null, $locale),
                default => null,
            };
        }

        return $urls;
    }

    /** Apply the locale prefix to an internal (site-relative) path via the single chokepoint. */
    private function internalUrl(?string $path, string $locale): ?string
    {
        return $path === null ? null : $this->paths->publicUrl($path, $locale);
    }

    /** The routes column naming a link kind's target; null for a kind with no route (url, dynamic, unknown). */
    private function routeColumn(string $linkType): ?string
    {
        return match ($linkType) {
            'entry' => 'entry_id',
            'taxonomy' => 'taxonomy_id',
            default => $this->targets->all()[$linkType]['column'] ?? null,
        };
    }

    /**
     * One routes query for every internal target in the menu, each kind narrowed to targets a
     * visitor can open in this locale (see reachable()).
     *
     * @param  array<string, list<int>>  $targets  link type → target ids
     * @return array<string, array<int, string>> link type → [target id → unprefixed path]
     */
    private function loadRoutePaths(array $targets, string $locale): array
    {
        $columns = [];   // link type → routes column
        foreach (array_keys($targets) as $linkType) {
            $column = $this->routeColumn($linkType);
            if ($column !== null) {
                $columns[$linkType] = $column;
            }
        }

        if ($columns === []) {
            return [];
        }

        $routes = SiteRoute::query()
            ->where('locale', $locale)
            ->where(function (Builder $query) use ($targets, $columns, $locale): void {
                foreach ($columns as $linkType => $column) {
                    $query->orWhere(function (Builder $branch) use ($linkType, $column, $targets, $locale): void {
                        $branch->whereIn($column, $targets[$linkType]);
                        $this->reachable($branch, $linkType, $locale);
                    });
                }
            })
            ->get(['path', ...array_values($columns)]);

        $paths = [];
        foreach ($routes as $route) {
            foreach ($columns as $linkType => $column) {
                $targetId = $route->getAttribute($column);
                if (is_numeric($targetId)) {
                    $paths[$linkType][(int) $targetId] = $route->path;
                }
            }
        }

        return $paths;
    }

    /**
     * Narrow one kind's routes to targets a visitor can open. A page taken offline or moved to the
     * Trash keeps its route row, so a bare route lookup would put a 404 in the main navigation:
     * an entry must be published AND have its translation in this locale published — the same test
     * the storefront applies before rendering. A category needs its translation in this locale. A
     * kind another module registered (Catalog's product) applies its own rule.
     *
     * @param  Builder<SiteRoute>  $routes
     */
    private function reachable(Builder $routes, string $linkType, string $locale): void
    {
        if ($linkType === 'entry') {
            $routes->whereHas('entry', fn (Builder $entry) => $entry
                ->where('status', 'published')
                ->whereHas('translations', fn (Builder $translation) => $translation
                    ->where('locale', $locale)
                    ->where('status', 'published')));

            return;
        }

        if ($linkType === 'taxonomy') {
            $routes->whereHas('taxonomy', fn (Builder $taxonomy) => $taxonomy
                ->whereHas('translations', fn (Builder $translation) => $translation->where('locale', $locale)));

            return;
        }

        $registered = $this->targets->all()[$linkType]['reachable'] ?? null;
        if ($registered !== null) {
            $registered($routes);
        }
    }

    /**
     * Fallback display titles for items whose per-locale label is empty (§13: empty label →
     * routable's translated title). Batched per target kind; runs no query when every item
     * already has a label.
     *
     * @param  Collection<int, MenuItem>  $items
     * @return array<int, string> itemId → fallback title
     */
    private function resolveFallbackTitles(Collection $items, string $locale): array
    {
        $unlabelled = [];   // link type → [target id → list<item id>]

        foreach ($items as $item) {
            if ($item->routable_id === null) {
                continue;
            }
            $translation = $item->translationFor($locale);
            if ($translation !== null && $translation->label !== '') {
                continue;
            }
            $unlabelled[$item->link_type][$item->routable_id][] = $item->id;
        }

        $titles = [];
        foreach ($unlabelled as $linkType => $itemsByTarget) {
            foreach ($this->targetTitles($linkType, array_keys($itemsByTarget), $locale) as $targetId => $title) {
                foreach ($itemsByTarget[$targetId] ?? [] as $itemId) {
                    $titles[$itemId] = $title;
                }
            }
        }

        return $titles;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string> target id → its name in the locale
     */
    private function targetTitles(string $linkType, array $ids, string $locale): array
    {
        $titles = [];

        if ($linkType === 'entry') {
            foreach (EntryTranslation::query()->whereIn('entry_id', $ids)->where('locale', $locale)->get(['entry_id', 'title']) as $row) {
                $titles[$row->entry_id] = $row->title;
            }

            return $titles;
        }

        if ($linkType === 'taxonomy') {
            foreach (TaxonomyTranslation::query()->whereIn('taxonomy_id', $ids)->where('locale', $locale)->get(['taxonomy_id', 'name']) as $row) {
                $titles[$row->taxonomy_id] = $row->name;
            }

            return $titles;
        }

        $registered = $this->targets->all()[$linkType]['titles'] ?? null;

        return $registered !== null ? $registered($ids, $locale) : [];
    }

    /**
     * @param  Collection<int, MenuItem>  $items
     * @param  array<int, string|null>  $urls
     * @param  array<int, string>  $titles
     * @return list<array<string, mixed>>
     */
    private function buildTree(Collection $items, ?int $parentId, string $locale, string $currentPath, array $urls, array $titles, int $depth): array
    {
        if ($depth >= MenuRules::MAX_DEPTH) {
            return [];
        }

        $nodes = [];
        foreach ($items as $item) {
            if ($item->parent_id !== $parentId) {
                continue;
            }

            $url = $urls[$item->id] ?? null;
            if ($url === null) {
                continue;   // unresolved/orphan target → omit (no dead link)
            }

            $label = $this->labelFor($item, $locale, $titles);
            if ($label === '') {
                continue;   // no usable label → omit
            }

            $nodes[] = [
                'label' => $label,
                'url' => $url,
                'active' => $url === $currentPath,
                'attrs' => $this->attrs($item),
                'children' => $this->buildTree($items, $item->id, $locale, $currentPath, $urls, $titles, $depth + 1),
            ];
        }

        return $nodes;
    }

    /**
     * @param  array<int, string>  $titles
     */
    private function labelFor(MenuItem $item, string $locale, array $titles): string
    {
        $translation = $item->translationFor($locale);
        if ($translation instanceof MenuItemTranslation && $translation->label !== '') {
            return $translation->label;
        }

        return $titles[$item->id] ?? '';
    }

    /**
     * @return array<string, string>
     */
    private function attrs(MenuItem $item): array
    {
        $attrs = [];
        $target = $item->meta['target'] ?? null;
        if ($target === '_blank') {
            $attrs['target'] = '_blank';
            $attrs['rel'] = 'noopener noreferrer';   // tab-nabbing guard for external links
        }

        return $attrs;
    }

    private function sanitizeUrl(?string $url): ?string
    {
        return MenuRules::safeUrl($url);
    }

    private function resolveDynamic(?string $key): ?string
    {
        return $key === null ? null : (MenuRules::DYNAMIC[$key] ?? null);
    }
}
