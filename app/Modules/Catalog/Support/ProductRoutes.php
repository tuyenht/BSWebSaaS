<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\RouteSync;

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
    public function __construct(private RouteSync $routes) {}

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
