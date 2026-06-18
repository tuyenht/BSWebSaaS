<?php

namespace App\Modules\Rbac\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\RoleHierarchyGuard;

/**
 * The single user-facing path to grant a role (design.md §15.3). Enforces the hierarchy
 * guard (grant ≤ self, within own permissions + team) then records an audit trail. System
 * callers (provisioning, bootstrap) assign roles directly and intentionally bypass this.
 */
final class AssignRole
{
    public function __construct(private readonly RoleHierarchyGuard $guard) {}

    public function handle(User $actor, User $target, Role $role): void
    {
        $this->guard->assertCanGrant($actor, $role);

        $target->assignRole($role);

        Audit::record('rbac.role.assigned', [
            'auditable_type' => $target::class,
            'auditable_id' => $target->getKey(),
            'meta' => ['role' => $role->name, 'role_id' => $role->getKey()],
        ]);
    }
}
