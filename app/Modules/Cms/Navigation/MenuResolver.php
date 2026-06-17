<?php

namespace App\Modules\Cms\Navigation;

use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\TaxonomyTranslation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves the menu bound to a theme region for the active locale into a primitive tree
 * the theme renders (design.md §13). Picks the menu via menu_assignments (region × locale,
 * falling back to the site's default locale so an untranslated region is never a dead-end),
 * BATCH-resolves each internal target's URL through the routes table (no N+1), drops items
 * whose target no longer resolves (orphan-safe — D3 observer/sweep is a later step), and
 * builds a depth-guarded tree. Returns nested arrays (NOT Cms models) so the Theme module
 * stays Cms-agnostic. Every query is RLS-scoped to the current site.
 */
final class MenuResolver
{
    /** Nesting cap — also the cycle guard for a malformed parent_id chain. */
    private const MAX_DEPTH = 5;

    /**
     * @return list<array<string, mixed>> nodes: {label, url, active, attrs, children}
     */
    public function resolve(string $region, string $locale, string $defaultLocale, string $currentPath = '/'): array
    {
        [$menu, $effectiveLocale] = $this->menuFor($region, $locale, $defaultLocale);

        if ($menu === null) {
            return [];
        }

        // Resolve labels + URLs in the locale the chosen menu is actually assigned under: a
        // default-locale fallback menu must be rendered in the default locale, not the
        // requested one (otherwise every label/URL would miss and the menu would drop out).
        /** @var Collection<int, MenuItem> $items */
        $items = $menu->items()
            ->with('translations')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $urls = $this->resolveUrls($items, $effectiveLocale);
        $titles = $this->resolveFallbackTitles($items, $effectiveLocale);

        return $this->buildTree($items, null, $effectiveLocale, $currentPath, $urls, $titles, 0);
    }

    /**
     * The menu bound to the region for the active locale, plus the locale it was found
     * under — falling back to the site's default locale so an untranslated region is never
     * a dead-end.
     *
     * @return array{0: Menu|null, 1: string}
     */
    private function menuFor(string $region, string $locale, string $defaultLocale): array
    {
        $menu = $this->assignedMenu($region, $locale);
        if ($menu !== null) {
            return [$menu, $locale];
        }

        if ($defaultLocale !== $locale) {
            $menu = $this->assignedMenu($region, $defaultLocale);
            if ($menu !== null) {
                return [$menu, $defaultLocale];
            }
        }

        return [null, $locale];
    }

    private function assignedMenu(string $region, string $locale): ?Menu
    {
        return Menu::query()
            ->whereHas('assignments', fn (Builder $query) => $query
                ->where('region_key', $region)
                ->where('locale', $locale))
            ->first();
    }

    /**
     * @param  Collection<int, MenuItem>  $items
     * @return array<int, string|null> itemId → resolved URL (null = unresolved/orphan)
     */
    private function resolveUrls(Collection $items, string $locale): array
    {
        $entryIds = [];
        $taxonomyIds = [];
        foreach ($items as $item) {
            if ($item->routable_id === null) {
                continue;
            }
            if ($item->link_type === 'entry') {
                $entryIds[] = $item->routable_id;
            } elseif ($item->link_type === 'taxonomy') {
                $taxonomyIds[] = $item->routable_id;
            }
        }

        [$byEntry, $byTaxonomy] = $this->loadRoutePaths($entryIds, $taxonomyIds, $locale);

        $urls = [];
        foreach ($items as $item) {
            $urls[$item->id] = match ($item->link_type) {
                'entry' => $item->routable_id !== null ? ($byEntry[$item->routable_id] ?? null) : null,
                'taxonomy' => $item->routable_id !== null ? ($byTaxonomy[$item->routable_id] ?? null) : null,
                'url' => $this->sanitizeUrl($item->url),
                'dynamic' => $this->resolveDynamic($item->dynamic_key),
                default => null,
            };
        }

        return $urls;
    }

    /**
     * @param  list<int>  $entryIds
     * @param  list<int>  $taxonomyIds
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function loadRoutePaths(array $entryIds, array $taxonomyIds, string $locale): array
    {
        $byEntry = [];
        $byTaxonomy = [];

        if ($entryIds === [] && $taxonomyIds === []) {
            return [$byEntry, $byTaxonomy];
        }

        $routes = SiteRoute::query()
            ->where('locale', $locale)
            ->where(function (Builder $query) use ($entryIds, $taxonomyIds): void {
                if ($entryIds !== []) {
                    $query->whereIn('entry_id', $entryIds);
                }
                if ($taxonomyIds !== []) {
                    $query->orWhereIn('taxonomy_id', $taxonomyIds);
                }
            })
            ->get(['path', 'entry_id', 'taxonomy_id']);

        foreach ($routes as $route) {
            if ($route->entry_id !== null) {
                $byEntry[$route->entry_id] = $route->path;
            }
            if ($route->taxonomy_id !== null) {
                $byTaxonomy[$route->taxonomy_id] = $route->path;
            }
        }

        return [$byEntry, $byTaxonomy];
    }

    /**
     * Fallback display titles for items whose per-locale label is empty (§13: empty label →
     * routable's translated title). Batched per target type; runs no query when every item
     * already has a label.
     *
     * @param  Collection<int, MenuItem>  $items
     * @return array<int, string> itemId → fallback title
     */
    private function resolveFallbackTitles(Collection $items, string $locale): array
    {
        $entryItems = [];     // entryId => list<itemId>
        $taxonomyItems = [];  // taxonomyId => list<itemId>

        foreach ($items as $item) {
            if ($item->routable_id === null) {
                continue;
            }
            $translation = $item->translationFor($locale);
            if ($translation !== null && $translation->label !== '') {
                continue;
            }
            if ($item->link_type === 'entry') {
                $entryItems[$item->routable_id][] = $item->id;
            } elseif ($item->link_type === 'taxonomy') {
                $taxonomyItems[$item->routable_id][] = $item->id;
            }
        }

        $titles = [];

        if ($entryItems !== []) {
            $rows = EntryTranslation::query()
                ->whereIn('entry_id', array_keys($entryItems))
                ->where('locale', $locale)
                ->get(['entry_id', 'title']);
            foreach ($rows as $row) {
                foreach ($entryItems[$row->entry_id] as $itemId) {
                    $titles[$itemId] = $row->title;
                }
            }
        }

        if ($taxonomyItems !== []) {
            $rows = TaxonomyTranslation::query()
                ->whereIn('taxonomy_id', array_keys($taxonomyItems))
                ->where('locale', $locale)
                ->get(['taxonomy_id', 'name']);
            foreach ($rows as $row) {
                foreach ($taxonomyItems[$row->taxonomy_id] as $itemId) {
                    $titles[$itemId] = $row->name;
                }
            }
        }

        return $titles;
    }

    /**
     * @param  Collection<int, MenuItem>  $items
     * @param  array<int, string|null>  $urls
     * @param  array<int, string>  $titles
     * @return list<array<string, mixed>>
     */
    private function buildTree(Collection $items, ?int $parentId, string $locale, string $currentPath, array $urls, array $titles, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
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
        if ($translation !== null && $translation->label !== '') {
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
        if ($url === null) {
            return null;
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        // Site-relative paths and anchors are always safe.
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return $url;
        }
        // Absolute URLs: allow only safe schemes (blocks javascript:, data:, …).
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true) ? $url : null;
    }

    private function resolveDynamic(?string $key): ?string
    {
        return match ($key) {
            'home' => '/',
            default => null,
        };
    }
}
