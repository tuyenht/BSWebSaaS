<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Exceptions\RoleChangeRejectedException;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\CustomRoleRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * Delete a custom role of this site — only once nobody holds it (INV-R4): deleting a held role
 * would silently strip those members' access through the FK cascade. Like UpdateCustomRole it is
 * not gated by the entitlement, so a downgraded site can still clean up.
 */
final readonly class DeleteCustomRole
{
    public function __construct(
        private CustomRoleRules $rules,
        private PermissionRegistrar $registrar,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws RoleChangeRejectedException
     */
    public function handle(User $actor, Role $role): void
    {
        Gate::forUser($actor)->authorize('delete', $role);
        $this->rules->assertManageable($actor, $role);

        DB::transaction(function () use ($role): void {
            // Lock the role row first: a concurrent grant inserts into model_has_roles, whose FK
            // takes a KEY SHARE lock on this row — so it either committed before this lock (and the
            // holder check below sees it) or waits until the role is gone (and its insert fails).
            Role::query()->whereKey($role->getKey())->lockForUpdate()->first();

            if (DB::table('model_has_roles')->where('role_id', $role->getKey())->exists()) {
                throw new RoleChangeRejectedException('in_use');
            }

            $snapshot = [
                'name' => $role->getAttribute('name'),
                'level' => $role->level,
                'permissions' => $role->permissions->pluck('name')->all(),
            ];

            $role->delete();

            Audit::record('rbac.role.deleted', [
                'auditable_type' => Role::class,
                'auditable_id' => $role->getKey(),
                'meta' => $snapshot,
            ]);
        });

        $this->registrar->forgetCachedPermissions();
    }
}
