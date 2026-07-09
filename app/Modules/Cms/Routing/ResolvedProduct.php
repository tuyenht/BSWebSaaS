<?php

declare(strict_types=1);

namespace App\Modules\Cms\Routing;

/**
 * A resolved storefront request that points at a catalog product — a THIN reference (product id +
 * locale), NOT the Product model. Cms routing never loads or references Catalog\Product, so the
 * module graph stays acyclic; the storefront (which may depend on Catalog) loads the product and
 * renders it, enforcing visibility (status = published) at load time.
 */
final readonly class ResolvedProduct
{
    public function __construct(
        public int $productId,
        public string $locale,
    ) {}
}
