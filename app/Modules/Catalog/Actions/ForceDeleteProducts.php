<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Catalog\Actions\Concerns\AuthorizesCatalogChange;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductTrash;
use Illuminate\Support\Facades\DB;

/**
 * The one way products are deleted for good: from the Trash by a member (one row or a selection) and
 * by the nightly purge. The foreign keys remove the translations, gallery and routes with each row, so
 * the SKU and the address are free again; the category totals already left the trashed product's
 * categories when it went to the Trash. The caller picks the products (inside the website's context);
 * the action itself asks CatalogPolicy (forceDelete) of whoever is signed in before anything is
 * deleted, the purge running with nobody signed in. One audit row per call, best-effort.
 */
final class ForceDeleteProducts
{
    use AuthorizesCatalogChange;

    /**
     * @param  iterable<Product>  $products
     * @return int how many were deleted
     */
    public function handle(iterable $products, bool $scheduled = false): int
    {
        $this->authorizeCatalog('forceDelete');

        return DB::transaction(function () use ($products, $scheduled): int {
            $deleted = 0;
            foreach ($products as $product) {
                $product->forceDelete();
                $deleted++;
            }

            if ($deleted > 0) {
                Audit::record('catalog.product.purged', [
                    'meta' => ['products' => $deleted, 'scheduled' => $scheduled, 'trash_days' => ProductTrash::DAYS],
                ]);
            }

            return $deleted;
        });
    }
}
