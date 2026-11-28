<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\PermalinkItem;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\DB;

/**
 * Re-syncs the public address of products after a category they are filed under was renamed, moved
 * or deleted — Catalog's side of Cms's CategoryDependents. Like `catalog:sync-routes`, only published
 * products are touched, in the languages they already have an address in; drafts keep theirs until
 * they are published again. RouteSync leaves a 301 from every address that moves.
 *
 * One batch of products costs a fixed number of reads (the primary category chain is eager-loaded),
 * plus RouteSync's per-address upsert. Runs inside the caller's transaction and tenant context.
 */
final readonly class ProductRoutes
{
    public function __construct(
        private RouteSync $routes,
        private PathBuilder $paths,
    ) {}

    /**
     * The website's published products for the «Đường dẫn» screen: how many, counted up to $cap (a
     * capped count, never a full scan), and the ids of the first $sample to compose examples from.
     *
     * @return array{count: int, ids: list<int>}
     */
    public function permalinkSample(int $cap, int $sample): array
    {
        $query = Product::query()->where('status', 'published');

        return [
            'count' => DB::query()->fromSub((clone $query)->select('products.id')->limit($cap)->toBase(), 'c')->count(),
            'ids' => array_values(array_map(static fn (mixed $id): int => Cast::int($id), (clone $query)->orderBy('id')->limit($sample)->pluck('id')->all())),
        ];
    }

    /**
     * The published products among $productIds as items a permalink run moves (Cms's PermalinkKinds):
     * each one's slugs, names and stored addresses per language, and its compose / move / rename
     * steps through PathBuilder and RouteSync. A fixed number of reads for the batch.
     *
     * @param  list<int>  $productIds
     * @return list<PermalinkItem>
     */
    public function permalinkItems(array $productIds): array
    {
        $products = Product::query()
            ->whereKey($productIds)
            ->where('status', 'published')
            ->with([...PathBuilder::primaryCategoryRelations(), 'translations:id,product_id,locale,slug,name'])
            ->orderBy('id')
            ->get(['id']);

        $stored = SiteRoute::query()
            ->whereIn('product_id', $products->modelKeys())
            ->get(['product_id', 'locale', 'path'])
            ->groupBy('product_id');

        $items = [];
        foreach ($products as $product) {
            $primary = $product->primaryCategory();
            $slugs = [];
            $names = [];
            foreach ($product->translations as $translation) {
                $slugs[$translation->locale] = (string) $translation->slug;
                $names[$translation->locale] = (string) $translation->name;
            }
            $routes = [];
            foreach ($stored->get($product->id, []) as $route) {
                $routes[(string) $route->locale] = (string) $route->path;
            }
            $id = $product->id;

            $items[] = new PermalinkItem(
                type: 'product',
                column: 'product_id',
                id: $id,
                slugs: $slugs,
                titles: $names,
                routes: $routes,
                compose: fn (string $locale, string $slug): string => $this->paths->forProduct($primary, $slug, $locale),
                sync: fn (string $locale, string $slug): string => $this->routes->syncProduct($id, $primary, $locale, $slug),
                rename: static function (string $locale, string $slug) use ($product): void {
                    $translation = $product->translations->firstWhere('locale', $locale);
                    if ($translation instanceof ProductTranslation) {
                        $translation->update(['slug' => $slug]);
                    }
                },
            );
        }

        return $items;
    }

    /**
     * @param  list<int>  $productIds
     * @return int addresses moved
     *
     * @throws RouteConflictException a product's new address already belongs to something else
     */
    public function resync(array $productIds): int
    {
        $products = Product::query()
            ->whereKey($productIds)
            ->where('status', 'published')
            ->with([...PathBuilder::primaryCategoryRelations(), 'translations:id,product_id,locale,slug'])
            ->get(['id']);

        $stored = SiteRoute::query()
            ->whereIn('product_id', $products->modelKeys())
            ->get(['product_id', 'locale', 'path'])
            ->groupBy('product_id');

        $moved = 0;
        foreach ($products as $product) {
            $primary = $product->primaryCategory();

            foreach ($stored->get($product->id, []) as $route) {
                $translation = $product->translationFor($route->locale);
                if ($translation !== null && $this->routes->syncProduct($product->id, $primary, $route->locale, $translation->slug) !== $route->path) {
                    $moved++;
                }
            }
        }

        return $moved;
    }
}
