<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Actions\Concerns\AuthorizesCatalogChange;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Routing\RouteSync;
use Illuminate\Support\Facades\DB;

/**
 * The one way a product's status changes, for the editor and for the products list alike.
 *
 * A product going live gets its public address written (RouteSync) and, the first time only, its
 * published_at; a draft or archived product keeps whatever address it had, so old links stay
 * stable. The whole change runs in one transaction: an address that belongs to another page or
 * category throws RouteConflictException and nothing is saved.
 */
final class ChangeProductStatus
{
    use AuthorizesCatalogChange;

    public const array STATUSES = ['draft', 'published', 'archived'];

    /**
     * Set the status and, when it is published, write the address of every language the product has.
     *
     * @throws RouteConflictException the address belongs to another page or category
     */
    public function handle(Product $product, string $status): Product
    {
        $this->authorizeCatalog('update', $product);

        return DB::transaction(function () use ($product, $status): Product {
            $product->forceFill(['status' => $status])->save();

            if ($status === 'published') {
                $this->publishEffects($product);
            }

            return $product;
        });
    }

    /**
     * What publishing adds to a saved product: the date it first went live (kept afterwards, the
     * storefront orders by it) and its address. With a locale and slug only that language is
     * written (the editor's save); without them every language the product has.
     *
     * @throws RouteConflictException
     */
    public function publishEffects(Product $product, ?string $locale = null, ?string $slug = null): void
    {
        $this->authorizeCatalog('update', $product);
        if ($product->published_at === null) {
            $product->forceFill(['published_at' => now()])->save();
        }

        $sync = app(RouteSync::class);
        $category = $product->primaryCategory();

        if ($locale !== null && $slug !== null) {
            $sync->syncProduct($product->id, $category, $locale, $slug);

            return;
        }

        // a caller that already loaded the translations (the bulk run) is not read again
        $translations = $product->relationLoaded('translations') ? $product->translations : $product->translations()->get(['locale', 'slug']);
        foreach ($translations as $translation) {
            $sync->syncProduct($product->id, $category, $translation->locale, $translation->slug);
        }
    }
}
