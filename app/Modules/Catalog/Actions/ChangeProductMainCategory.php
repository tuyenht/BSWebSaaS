<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\UniqueViolation;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Support\CategoryItemCounts;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one way the MAIN category of products changes from the products list (one row or a selection).
 *
 * The main category is the first of a product's categories (pivot position 0); the others stay,
 * except that a category picked from among them becomes the main one and leaves its old place. The
 * category totals follow in the same transaction (assignCategories). A product that is published
 * moves its public address with the category (the permalink presets that put the category in it):
 * the route is re-composed, and the old address gets a 301 to the new one. Each product runs in its
 * own savepoint, so one that cannot move (its new address belongs to a page or another category)
 * leaves nothing half-done (the category totals included: each runs in its own count batch inside the
 * savepoint, so a rolled-back product leaves no total changed) and the rest carry on. The caller locks the products first (id order).
 * The products whose main category really moved get their content mark in one statement at the end,
 * in the caller's transaction (markMoved()): never one write per product.
 */
final class ChangeProductMainCategory
{
    /**
     * Give every product $to as its main category.
     *
     * @param  EloquentCollection<int, Product>  $products  live products with `taxonomies` and `translations` (locale, slug) loaded
     * @return array{moved: int, same: int, redirected: int, failed: int, before: array<string, list<string>>} `before`: the category ulids (main first) of each product that moved, to put them back
     */
    public function handle(EloquentCollection $products, Taxonomy $to): array
    {
        if ($to->vocabulary !== 'product_cat') {
            throw new InvalidArgumentException('The main category of a product is a product category.');
        }
        $routes = $this->routePaths(array_values($products->modelKeys()));
        $counts = app(CategoryItemCounts::class);
        $tally = ['moved' => 0, 'same' => 0, 'redirected' => 0, 'failed' => 0, 'before' => []];
        $marked = [];

        foreach ($products as $product) {
            $now = $this->categoryIds($product);
            if (($now[0] ?? null) === $to->id) {
                $tally['same']++;

                continue;
            }
            $list = [$to->id, ...array_values(array_diff($now, [$now[0] ?? 0, $to->id]))];
            $was = $this->categoryUlids($product, $now);   // before the change: it drops the loaded list

            try {
                $filed = $counts->batch(fn (): array => DB::transaction(fn (): array => $this->file($product, $list, $to, $routes[$product->id] ?? [])));
            } catch (RouteConflictException) {
                $tally['failed']++;

                continue;
            } catch (UniqueConstraintViolationException $e) {
                UniqueViolation::refusal($e);   // a known address clash is a refusal; anything else is rethrown
                $tally['failed']++;

                continue;
            }

            $tally['moved']++;
            $tally['redirected'] += $filed['redirected'] ? 1 : 0;
            $tally['before'][$product->ulid] = $was;
            if ($filed['mainChanged']) {
                $marked[] = $product->id;
            }
        }
        $this->markMoved($marked);

        return $tally;
    }

    /**
     * Mark the content of the products filed with file() whose main category changed, in one
     * statement in the caller's transaction (the page's breadcrumb shows the main category).
     *
     * @param  list<int>  $productIds
     */
    public function markMoved(array $productIds): void
    {
        Product::markContentChanged($productIds);
    }

    /**
     * File the product under $ids (main first) and, when it is published, move its address along.
     * Returns whether a published product's address moved (its old one now redirects) and whether its
     * main category changed. It leaves the content mark to the caller: markMoved() once for all the
     * products it filed.
     *
     * @param  list<int>  $ids
     * @param  array<string, string>  $before  the product's route path per language, before the change
     * @return array{redirected: bool, mainChanged: bool}
     */
    public function file(Product $product, array $ids, ?Taxonomy $main, array $before): array
    {
        $mainChanged = $product->assignCategories($ids, markContent: false);
        $product->touch();
        if ($product->status !== 'published') {
            return ['redirected' => false, 'mainChanged' => $mainChanged];
        }

        $moved = false;
        foreach ($product->translations as $translation) {
            $after = app(RouteSync::class)->syncProduct($product->id, $main, $translation->locale, $translation->slug);
            $moved = $moved || (isset($before[$translation->locale]) && $before[$translation->locale] !== $after);
        }

        return ['redirected' => $moved, 'mainChanged' => $mainChanged];
    }

    /**
     * The categories of a product (not its facet terms), main first, as ids. Reads the loaded list.
     *
     * @return list<int>
     */
    public function categoryIds(Product $product): array
    {
        $ids = [];
        foreach ($product->taxonomies as $taxonomy) {
            $pivot = $taxonomy->getRelation('pivot');
            $position = $pivot instanceof Pivot ? $pivot->getAttribute('position') : null;
            if (is_numeric($position) && (int) $position < Product::FACET_POSITION_BASE) {
                $ids[] = $taxonomy->id;
            }
        }

        return $ids;
    }

    /**
     * The route path of each product per language, in one read.
     *
     * @param  list<int|string>  $productIds
     * @return array<int, array<string, string>> product id → locale → path
     */
    public function routePaths(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $paths = [];
        foreach (SiteRoute::query()->whereIn('product_id', $productIds)->get(['product_id', 'locale', 'path']) as $route) {
            $paths[(int) $route->product_id][$route->locale] = $route->path;
        }

        return $paths;
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function categoryUlids(Product $product, array $ids): array
    {
        $byId = [];
        foreach ($product->taxonomies as $taxonomy) {
            $byId[$taxonomy->id] = $taxonomy->ulid;
        }

        return array_values(array_filter(array_map(fn (int $id): ?string => $byId[$id] ?? null, $ids)));
    }
}
