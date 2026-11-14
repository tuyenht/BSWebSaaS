<?php

namespace App\Modules\Catalog\Policies;

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Defense layer #2 for the catalog — coarse view/manage (like media/forms), team-scoped by the
 * spatie guard (= current site). Shipped with the resource at birth so a write surface can never
 * appear ungated. Brochure hides price/ordering; managing the catalog itself is catalog.manage.
 *
 * A member limited to some product categories (CategoryScopes::PRODUCTS) changes only the products
 * whose main category is inside those limits: asked about a product, update / delete / restore /
 * forceDelete also check that; asked about the class (a screen deciding what to offer, a bulk action
 * before it has its rows), they answer for catalog.manage alone and the product is asked about once
 * it is known. A product with no main category is outside every limit. Filing a product under a
 * category (create, a new main category) is fileUnder: the category must be inside the limits — a
 * limited member never files a product outside them, or under no category. A member not limited on
 * products is never asked about categories (no query).
 */
final readonly class CatalogPolicy
{
    public function __construct(private CategoryScopes $scopes) {}

    public function viewAny(User $user): bool
    {
        return $user->can('catalog.view');
    }

    public function view(User $user): bool
    {
        return $user->can('catalog.view');
    }

    public function create(User $user): bool
    {
        return $user->can('catalog.manage');
    }

    public function update(User $user, ?Product $product = null): Response
    {
        return $this->manageProduct($user, $product);
    }

    public function delete(User $user, ?Product $product = null): Response
    {
        return $this->manageProduct($user, $product);
    }

    public function restore(User $user, ?Product $product = null): Response
    {
        return $this->manageProduct($user, $product);
    }

    /** Permanent delete (empties the Trash row; cascades translations/images, frees the sku). */
    public function forceDelete(User $user, ?Product $product = null): Response
    {
        return $this->manageProduct($user, $product);
    }

    /** File a product under $categoryId as its main category (null: under none). */
    public function fileUnder(User $user, ?int $categoryId): Response
    {
        if (! $user->can('catalog.manage')) {
            return Response::deny();
        }

        return $this->scopes->coversId($user, $categoryId, CategoryScopes::PRODUCTS)
            ? Response::allow()
            : Response::deny($this->scopes->refusal($user, CategoryScopes::PRODUCTS));
    }

    private function manageProduct(User $user, ?Product $product): Response
    {
        if (! $user->can('catalog.manage')) {
            return Response::deny();
        }
        if ($product === null || ! $this->scopes->isLimited($user, CategoryScopes::PRODUCTS)) {
            return Response::allow();
        }

        return $this->scopes->coversId($user, $product->primaryCategory()?->id, CategoryScopes::PRODUCTS)
            ? Response::allow()
            : Response::deny($this->scopes->refusal($user, CategoryScopes::PRODUCTS));
    }
}
