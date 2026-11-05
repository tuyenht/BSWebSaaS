<?php

declare(strict_types=1);

namespace App\Modules\Cms\Policies;

use App\Modules\Identity\Models\User;

/**
 * Defense layer #2 for menus: one coarse permission, `menus.manage`, team-scoped by the spatie
 * guard (= current site). Every menu write path (Cms\Actions\Menus) and the Trình đơn screen ask
 * through this policy; super_admin passes through Gate::before.
 */
final class MenuPolicy
{
    /** Any change to the site's menus, areas or menu items. */
    public function manage(User $user): bool
    {
        return $user->can('menus.manage');
    }

    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user): bool
    {
        return $this->manage($user);
    }

    public function restore(User $user): bool
    {
        return $this->manage($user);
    }
}
