<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Catalog\Support\ProductIndexLink;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\PathBuilder;

/**
 * The "Sản phẩm" tab of the phone menu: the product category tree under the products section,
 * three levels of categories (a top row, its children and theirs), shaped like menu nodes so the drawer draws it with the same rows as the menu.
 *
 * Two reads for the whole tree (the categories below the products section, then their names and
 * slugs in the page's language and the site's default), none at all unless the site has a product
 * list and a category under it. Names fall back to the site's default language per category, with
 * that language's address. A SCOPED binding like ProductIndexLink: it keeps nothing between
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

        $rootPath = '(select root.path from taxonomies root where root.id = ?)';
        $rows = Taxonomy::query()
            ->whereRaw('taxonomies.path <@ '.$rootPath, [$root])
            ->whereRaw('nlevel(taxonomies.path) between nlevel('.$rootPath.') + 1 and nlevel('.$rootPath.') + ?', [$root, $root, self::DEPTH])
            ->orderByRaw('nlevel(taxonomies.path)')
            ->orderBy('position')
            ->orderBy('id')
            ->limit(self::MAX_CATEGORIES)
            ->get(['id', 'parent_id']);
        if ($rows->isEmpty()) {
            return null;
        }

        // One translation per category: the page's language, else the default's.
        /** @var array<int, TaxonomyTranslation> $names */
        $names = [];
        $found = TaxonomyTranslation::query()
            ->whereIn('taxonomy_id', $rows->pluck('id')->all())
            ->whereIn('locale', array_values(array_unique([$locale, $default])))
            ->get(['taxonomy_id', 'locale', 'name', 'slug']);
        foreach ($found as $translation) {
            $id = (int) $translation->taxonomy_id;
            if (! isset($names[$id]) || $translation->locale === $locale) {
                $names[$id] = $translation;
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
     * @param  array<int, TaxonomyTranslation>  $names
     * @return list<array<string, mixed>>
     */
    private function level(array $ids, int $depth, array $children, array $names, string $locale, string $default, string $requestPath): array
    {
        $items = [];
        foreach ($ids as $id) {
            $translation = $names[$id] ?? null;
            if ($translation === null || $translation->name === '') {
                continue;
            }
            $own = $translation->locale === $locale ? $locale : $default;
            $url = $this->paths->publicUrl($this->paths->forTaxonomy($translation->slug), $own);
            $sub = $depth < self::DEPTH ? $this->level($children[$id] ?? [], $depth + 1, $children, $names, $locale, $default, $requestPath) : [];

            $item = ['label' => $translation->name, 'url' => $url, 'active' => $url === $requestPath, 'children' => $sub];
            if ($depth === 1 && $sub !== []) {
                // A top row with children opens its sub-list (a button); its own page is the first row inside.
                $item['url'] = '#';
                $item['active'] = false;
                array_unshift($item['children'], [
                    'label' => __('storefront.category_all', ['name' => $translation->name]),
                    'url' => $url, 'active' => $url === $requestPath, 'children' => [],
                ]);
            }
            $items[] = $item;
        }

        return $items;
    }
}
