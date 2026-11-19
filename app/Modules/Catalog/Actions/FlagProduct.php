<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Actions\Concerns\AuthorizesCatalogChange;
use App\Modules\Catalog\Models\Product;
use InvalidArgumentException;

/**
 * The one way the labels «Nổi bật» / «Bán chạy» go on a product or come off: the products list (its
 * row menu and «Hoàn tác») and the product editor both write them here, asked of CatalogPolicy about
 * the product itself (`update`, with the member's category limits). A label is named by its key
 * (`featured`, `best`), never by its column, so a browser cannot reach another column.
 */
final class FlagProduct
{
    use AuthorizesCatalogChange;

    /** Each label's key and the column it is stored in. */
    public const array FLAGS = ['featured' => 'is_featured', 'best' => 'is_bestseller'];

    /**
     * Set the labels on the product without saving it, for a caller that saves the row with its other
     * fields in one write. Returns each label's state before.
     *
     * @param  array<string, bool>  $flags  label key => on
     * @return array<string, bool>
     */
    public function fill(Product $product, array $flags): array
    {
        $this->authorizeCatalog('update', $product);

        return $this->set($product, $flags);
    }

    /**
     * Set the labels and save the product (nothing is written when they already are so). Returns
     * each label's state before.
     *
     * @param  array<string, bool>  $flags  label key => on
     * @return array<string, bool>
     */
    public function handle(Product $product, array $flags): array
    {
        $this->authorizeCatalog('update', $product);
        $was = $this->set($product, $flags);
        $product->save();

        return $was;
    }

    /**
     * @param  array<string, bool>  $flags  label key => on
     * @return array<string, bool>
     */
    private function set(Product $product, array $flags): array
    {
        $was = [];
        foreach ($flags as $flag => $on) {
            $column = self::FLAGS[$flag] ?? throw new InvalidArgumentException("Unknown product label [{$flag}].");
            $was[$flag] = (bool) $product->getAttribute($column);
            $product->forceFill([$column => $on]);
        }

        return $was;
    }
}
