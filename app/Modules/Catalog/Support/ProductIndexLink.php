<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Tenancy\Context\ContentSections;
use App\Modules\Tenancy\Entitlement\Entitlement;

/**
 * The public address of the current site's product list, or null when it has none: the site must
 * be entitled to products, have a products section built on a category with an address in that
 * language, and at least one published product (the storefront lists products on that category
 * only then — before that it lists posts). The address is built like every storefront link
 * (the site route + PathBuilder's language prefix).
 *
 * Up to five reads (entitlement, section, product, route); callers ask only when they will show
 * the link. Everything is resolved per call, so it holds no request state under Octane.
 */
final class ProductIndexLink
{
    public function for(string $locale): ?string
    {
        if (! in_array('catalog', app(Entitlement::class)->entitledModuleKeys(), true)) {
            return null;
        }
        $root = app(ContentSections::class)->taxonomyIdOf('products');
        if ($root === null || ! Product::query()->where('status', 'published')->exists()) {
            return null;
        }
        $path = SiteRoute::query()->where('taxonomy_id', $root)->where('locale', $locale)->value('path');

        return is_string($path) && $path !== '' ? app(PathBuilder::class)->publicUrl($path, $locale) : null;
    }
}
