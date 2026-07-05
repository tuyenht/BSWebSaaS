<?php

namespace App\Modules\Catalog\Policies;

use App\Modules\Identity\Models\User;

/**
 * Defense layer #2 for the catalog — coarse view/manage (like media/forms), team-scoped by the
 * spatie guard (= current site). Shipped with the resource at birth so a write surface can never
 * appear ungated. Brochure hides price/ordering; managing the catalog itself is catalog.manage.
 */
final class CatalogPolicy
{
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

    public function update(User $user): bool
    {
        return $user->can('catalog.manage');
    }

    public function delete(User $user): bool
    {
        return $user->can('catalog.manage');
    }

    public function restore(User $user): bool
    {
        return $user->can('catalog.manage');
    }

    /** Permanent delete (empties the Trash row; cascades translations/images, frees the sku). */
    public function forceDelete(User $user): bool
    {
        return $user->can('catalog.manage');
    }
}
