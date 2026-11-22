<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Policies;

use App\Modules\Identity\Models\User;

/**
 * Defense layer #2 for the RBAC Console — `roles.manage`, team-scoped by the spatie guard (= the
 * current site). Coarse on purpose: WHICH role a manager may touch (same site, custom, below
 * their level, within their permissions) is enforced by CustomRoleRules in the actions, so a
 * caller that skips the policy still cannot escalate. Shipped with the write path at birth.
 * Deleting moves a custom role to the trash; restoring brings it back. The daily purge of the trash
 * is a system job with no user (`roles:purge-trash`), so it has no ability here.
 */
final class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('roles.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('roles.manage');
    }

    public function update(User $user): bool
    {
        return $user->can('roles.manage');
    }

    public function delete(User $user): bool
    {
        return $user->can('roles.manage');
    }

    public function restore(User $user): bool
    {
        return $user->can('roles.manage');
    }
}
