<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Exceptions\ProductEditRefused;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Turns a unique-index violation that a race can still produce into the plain refusal for it:
 * a public path (or a redirect from it) taken meanwhile is an address conflict, a SKU taken
 * meanwhile is a SKU message. Any other violation is a real fault and is rethrown untouched.
 */
final class UniqueViolation
{
    /**
     * @throws UniqueConstraintViolationException when the violated index is none of the known ones
     */
    public static function refusal(UniqueConstraintViolationException $e): ProductEditRefused
    {
        $text = $e->getMessage();
        for ($previous = $e->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
            $text .= ' '.$previous->getMessage();
        }

        if (str_contains($text, 'routes_site_id_locale_path_unique') || str_contains($text, 'redirects_site_id_locale_from_path_unique')) {
            return new ProductEditRefused(__('catalog.slug_conflict'), 'slug');
        }
        if (str_contains($text, 'products_site_sku_unique')) {
            return new ProductEditRefused(__('catalog.sku_taken'), 'sku');
        }

        throw $e;
    }
}
