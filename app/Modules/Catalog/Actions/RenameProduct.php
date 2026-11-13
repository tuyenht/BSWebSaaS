<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Actions\Concerns\AuthorizesCatalogChange;
use App\Modules\Catalog\Exceptions\ProductEditRefused;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Catalog\Support\ProductSearchWords;
use App\Modules\Shared\Support\PlainText;

/**
 * The one rule for a product's name, for the editor and for the products list alike: required,
 * at most 200 characters, one line without control characters. Only the given language's
 * translation changes; the address is never touched by a rename.
 */
final class RenameProduct
{
    use AuthorizesCatalogChange;

    public const int MAX = 200;

    /**
     * The name as it will be stored.
     *
     * @throws ProductEditRefused empty or too long
     */
    public function clean(string $input): string
    {
        $name = self::tidy($input);

        if ($name === '') {
            throw new ProductEditRefused(__('catalog.name_required'));
        }
        if (mb_strlen($name) > self::MAX) {
            throw new ProductEditRefused(__('catalog.name_too_long', ['max' => self::MAX]));
        }

        return $name;
    }

    /**
     * The one-line name with every kind of space and zero-width mark trimmed from both ends (the
     * browser's trim() strips them too), so a name that only looks empty is empty here as well.
     */
    public static function tidy(string $input): string
    {
        return preg_replace('/^[\p{Z}\x{200B}-\x{200D}\x{FEFF}]+|[\p{Z}\x{200B}-\x{200D}\x{FEFF}]+$/u', '', PlainText::line($input)) ?? '';
    }

    /**
     * Save the name in $locale and return the name that was stored, in the caller's transaction: the
     * product row is locked before its translation is written (ProductSearchWords::lock).
     *
     * @throws ProductEditRefused empty, too long, or the product has no text in that language
     */
    public function handle(Product $product, string $input, string $locale): string
    {
        $this->authorizeCatalog('update', $product);
        $name = $this->clean($input);

        ProductSearchWords::lock($product->site_id, $product->id);
        $translation = $product->translations()->where('locale', $locale)->first();
        if (! $translation instanceof ProductTranslation) {
            throw new ProductEditRefused(__('catalog.row_gone'));
        }
        if ($translation->name !== $name) {
            $translation->update(['name' => $name]);
            $product->touch();
        }

        return $name;
    }
}
