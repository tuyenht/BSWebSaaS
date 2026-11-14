<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Catalog\Support\ProductIndexLink;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\PathBuilder;
use Illuminate\Database\Query\JoinClause;

/**
 * The "Sản phẩm" tab of the phone menu: the product category tree under the products section,
 * three levels of categories (a top row, its children and theirs), shaped like menu nodes so the drawer draws it with the same rows as the menu.
 *
 * Two reads for the whole tree (the categories below the products section, then their names and
 * addresses in the page's language and the site's default, from the routes), none at all unless the site has a product
 * list and a category under it. Names fall back to the site's default language per category, with
 * that language's address. A category with no route or no name in either language is left out together with its subtree. A SCOPED binding like ProductIndexLink: it keeps nothing between
 * requests (Octane), the readonly dependencies are the only state.
 */
final readonly class DrawerProducts
{
    /** Most categories read for the tree, the top level first. */
    public const int MAX_CATEGORIES = 200;

    /** Levels of categories shown under the products section. */
    private const int DEPTH = 3;

    public function __construct(
        private ProductIndexLink $products,
        private PathBuilder $paths,
    ) {}

    /**
     * @param  string  $requestPath  the page's prefixed path: the category it is on is marked
     * @return array{url: string, items: list<array<string, mixed>>}|null null when there is no product list or no category under it
     */
    public function tree(string $locale, string $default, string $requestPath): ?array
    {
        // The cheapest question first: a site with no products section stops after one read.
        $root = $this->products->catalogRoot();
        $url = $root === null ? null : $this->products->for($locale);
        if ($root === null || $url === null) {
            return null;
        }

        // The root's path is joined in (with its site) instead of read apart or inlined in the filters.
        $rows = Taxonomy::query()
            ->join('taxonomies as root', function (JoinClause $join) use ($root): void {
                $join->on('root.site_id', '=', 'taxonomies.site_id')->where('root.id', '=', $root);
            })
            ->whereRaw('taxonomies.path <@ root.path')
            ->whereRaw('nlevel(taxonomies.path) between nlevel(root.path) + 1 and nlevel(root.path) + ?', [self::DEPTH])
            ->orderByRaw('nlevel(taxonomies.path)')
            ->orderBy('taxonomies.position')
            ->orderBy('taxonomies.id')
            ->limit(self::MAX_CATEGORIES)
            ->get(['taxonomies.id', 'taxonomies.parent_id']);
        if ($rows->isEmpty()) {
            return null;
        }

        // One entry per category: its name and address in the page's language, else the default's. Only a
        // language that has both a non-empty name and a route counts, like a menu link to a category.
        /** @var array<int, array{locale: string, name: string, path: string}> $names */
        $names = [];
        $found = SiteRoute::query()
            ->join('taxonomy_translations as tt', function (JoinClause $join): void {
                $join->on('tt.taxonomy_id', '=', 'routes.taxonomy_id')
                    ->on('tt.locale', '=', 'routes.locale')
                    ->on('tt.site_id', '=', 'routes.site_id');
            })
            ->whereIn('routes.taxonomy_id', $rows->pluck('id')->all())
            ->whereIn('routes.locale', array_values(array_unique([$locale, $default])))
            ->where('tt.name', '<>', '')
            ->get(['routes.taxonomy_id', 'routes.locale', 'routes.path', 'tt.name']);
        foreach ($found as $route) {
            $id = (int) $route->taxonomy_id;
            $name = $route->getAttribute('name');   // from the joined translation
            if (is_string($name) && (! isset($names[$id]) || $route->locale === $locale)) {
                $names[$id] = ['locale' => $route->locale, 'name' => $name, 'path' => $route->path];
            }
        }

        $children = [];
        foreach ($rows as $row) {
            $children[(int) $row->parent_id][] = (int) $row->id;
        }

        $items = $this->level($children[$root] ?? [], 1, $children, $names, $locale, $default, $requestPath);

        return $items === [] ? null : ['url' => $url, 'items' => $items];
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, list<int>>  $children
     * @param  array<int, array{locale: string, name: string, path: string}>  $names
     * @return list<array<string, mixed>>
     */
    private function level(array $ids, int $depth, array $children, array $names, string $locale, string $default, string $requestPath): array
    {
        $items = [];
        foreach ($ids as $id) {
            $translation = $names[$id] ?? null;
            if ($translation === null) {
                continue;   // no name or no route in either language: the row is left out, with what is under it
            }
            $url = $this->paths->publicUrl($translation['path'], $translation['locale']);
            $sub = $depth < self::DEPTH ? $this->level($children[$id] ?? [], $depth + 1, $children, $names, $locale, $default, $requestPath) : [];

            $item = ['label' => $translation['name'], 'url' => $url, 'active' => $url === $requestPath, 'children' => $sub];
            if ($depth <= 2 && $sub !== []) {
                // A row with children opens its sub-list (a button); its own page is the first row inside.
                $item['url'] = '#';
                $item['active'] = false;
                array_unshift($item['children'], [
                    'label' => __('storefront.category_all', ['name' => $translation['name']]),
                    'url' => $url, 'active' => $url === $requestPath, 'children' => [],
                ]);
            }
            $items[] = $item;
        }

        return $items;
    }
}
