<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Events;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Models\Role;

/**
 * $actor is about to give the custom role $role permissions it does not hold yet. Rbac only
 * announces it; a module that keeps per-member limits refuses the change when it would widen the
 * role's holders past what $actor may hand out, by throwing RoleChangeRejectedException.
 * Dispatched synchronously inside the updating transaction, before anything is written, so a
 * refusal changes nothing.
 */
final readonly class CustomRolePermissionsAdding
{
    /**
     * @param  list<string>  $before  the role's permissions now
     * @param  list<string>  $after  the permissions it would hold
     */
    public function __construct(
        public User $actor,
        public Role $role,
        public array $before,
        public array $after,
    ) {}
}
