<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions\Concerns;

use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * The one check every catalog action makes before it writes: the signed-in person must be allowed
 * $ability by CatalogPolicy (super_admin passes through Gate::before), asked about the product itself
 * when there is one, so the rule holds however the action was reached. With nobody signed in — the
 * console, the nightly Trash purge, provisioning — there is nobody to ask, the same rule the category
 * actions follow (EditsTaxonomyTree::authorizeManage). A member limited to some product categories
 * changes only products inside them (CatalogPolicy, asked about the product) and files a product only
 * under a category inside them (authorizeFiling).
 */
trait AuthorizesCatalogChange
{
    /**
     * @param  Product|class-string<Product>  $subject
     *
     * @throws AuthorizationException
     */
    private function authorizeCatalog(string $ability, Product|string $subject = Product::class): void
    {
        $user = Auth::user();
        if ($user instanceof User) {
            Gate::forUser($user)->authorize($ability, $subject);
        }
    }

    /**
     * The main category a product is being filed under must be inside the signed-in person's product
     * limits (CatalogPolicy::fileUnder); null — no category — is outside every limit.
     *
     * @throws AuthorizationException
     */
    private function authorizeFiling(?int $categoryId): void
    {
        $user = Auth::user();
        if ($user instanceof User) {
            Gate::forUser($user)->authorize('fileUnder', [Product::class, $categoryId]);
        }
    }
}
