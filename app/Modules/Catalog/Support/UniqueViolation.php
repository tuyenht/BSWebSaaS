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
        // The index is named in PostgreSQL's first line, before the key values and the SQL that
        // carry what the member typed: only that name is read, never the rest of the text.
        $index = preg_match('/unique constraint "([^"]+)"/', $e->getPrevious()?->getMessage() ?? $e->getMessage(), $m) === 1 ? $m[1] : null;

        if (in_array($index, ['routes_site_id_locale_path_unique', 'redirects_site_id_locale_from_path_unique'], true)) {
            return new ProductEditRefused(__('catalog.slug_conflict'), 'slug');
        }
        if ($index === 'products_site_sku_unique') {
            return new ProductEditRefused(__('catalog.sku_taken'), 'sku');
        }

        throw $e;
    }
}
