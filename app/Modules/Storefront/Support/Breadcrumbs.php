<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\PathBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * The breadcrumb trail of a storefront page: "Home / … / current". One trail feeds both the page's
 * BreadcrumbList JSON-LD (`url`, absolute) and the visible breadcrumb (`href`, the site path), so
 * the two always list the same levels. A category's levels come from its tree path (ids, root
 * first), a page's from its parent pages; a level with no name in the page's language, or a parent
 * page that is not published, is left out rather than linked to a page that does not open.
 *
 * A top-level category or page costs no query; a nested category costs one read for its
 * ancestors' names, a nested page one walk up its parents plus one read each for those pages,
 * their names and their addresses. Every read runs under the site's
 * row-level security and the models' site scope; the raw parent walk names the site explicitly.
 *
 * @phpstan-type Crumb array{name: string, url: string, href: string}
 */
final readonly class Breadcrumbs
{
    /** The deepest chain of parent pages walked (a loop in bad data stops here too). */
    private const int MAX_PAGE_DEPTH = 8;

    public function __construct(private PathBuilder $paths) {}

    /**
     * One level: its name, its site path in the page's language and that path as a full URL.
     *
     * @return Crumb
     */
    public function crumb(string $name, string $href): array
    {
        return ['name' => $name, 'url' => url($href), 'href' => $href];
    }

    /** @return Crumb */
    public function home(string $locale): array
    {
        return $this->crumb(__('storefront.home'), $this->paths->publicUrl('/', $locale));
    }

    /**
     * A category and the categories above it, root first, each linking to its list page. The
     * category's own translation is passed in when the caller already holds it (no read for it).
     *
     * @return list<Crumb>
     */
    public function category(Taxonomy $category, string $locale, ?string $name = null, ?string $slug = null): array
    {
        $crumbs = [];
        $ancestors = $this->ancestorIds($category);
        if ($ancestors !== []) {
            $names = TaxonomyTranslation::query()
                ->whereIn('taxonomy_id', $ancestors)
                ->where('locale', $locale)
                ->get(['taxonomy_id', 'name', 'slug'])
                ->keyBy('taxonomy_id');
            foreach ($ancestors as $id) {
                $translation = $names->get($id);
                if ($translation !== null && $translation->name !== '') {
                    $crumbs[] = $this->crumb($translation->name, $this->paths->publicUrl($this->paths->forTaxonomy($translation->slug), $locale));
                }
            }
        }

        if ($name === null || $slug === null) {
            $translation = $category->translationFor($locale);
            if ($translation === null) {
                return $crumbs;
            }
            [$name, $slug] = [$translation->name, $translation->slug];
        }
        $crumbs[] = $this->crumb($name, $this->paths->publicUrl($this->paths->forTaxonomy($slug), $locale));

        return $crumbs;
    }

    /**
     * The published parent pages of a page, root first (the page itself is not included).
     *
     * @return list<Crumb>
     */
    public function parentPages(Entry $page, string $locale): array
    {
        if ($page->getAttribute('parent_id') === null) {
            return [];
        }

        // Walk up the parent links in one read (depth-bounded, so a cycle in bad data ends).
        $chain = DB::select(<<<'SQL'
            WITH RECURSIVE chain (id, parent_id, depth) AS (
                SELECT parent.id, parent.parent_id, 1
                FROM entries parent
                WHERE parent.site_id = ? AND parent.id = ?
                UNION ALL
                SELECT up.id, up.parent_id, chain.depth + 1
                FROM entries up
                JOIN chain ON up.id = chain.parent_id
                WHERE up.site_id = ? AND chain.depth < ?
            )
            SELECT id FROM chain ORDER BY depth DESC
            SQL, [$page->site_id, $page->getAttribute('parent_id'), $page->site_id, self::MAX_PAGE_DEPTH]);
        $ids = array_values(array_unique(array_map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, array_column($chain, 'id'))));
        if ($ids === []) {
            return [];
        }

        $rows = Entry::query()
            ->whereIn('id', $ids)
            ->where('type', 'page')
            ->where('status', 'published')
            ->with([
                'translations' => fn (Relation $query) => $query->where('locale', $locale)->where('status', 'published'),
                'routes' => fn (Relation $query) => $query->where('locale', $locale),
            ])
            ->get(['id', 'site_id', 'type', 'parent_id'])
            ->keyBy('id');

        $crumbs = [];
        foreach ($ids as $id) {
            $row = $rows->get($id);
            $translation = $row?->translationFor($locale);
            $route = $row?->routes->first();
            if ($translation !== null && $route !== null && $route->path !== '/') {
                $crumbs[] = $this->crumb($translation->title, $this->paths->publicUrl($route->path, $locale));
            }
        }

        return $crumbs;
    }

    /**
     * The ids above a category, root first, from its tree path (`1.5.9` ends with its own id). A
     * path that was never written falls back to the direct parent.
     *
     * @return list<int>
     */
    private function ancestorIds(Taxonomy $category): array
    {
        if ($category->parent_id === null) {
            return [];
        }
        $labels = array_map('intval', explode('.', (string) $category->path));
        if (end($labels) === $category->id && count($labels) > 1) {
            return array_slice($labels, 0, -1);
        }

        return [$category->parent_id];
    }
}
