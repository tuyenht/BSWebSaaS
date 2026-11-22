<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Exceptions\RoleChangeRejectedException;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\CustomRoleRules;
use App\Modules\Rbac\Support\MembershipLock;
use App\Modules\Shared\Support\Cast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * Move a custom role of this site to the trash (Role::TRASH_DAYS days, then `roles:purge-trash`) —
 * only once nobody holds it and no pending invitation grants it (INV-R4), so a role in the trash
 * never has a holder: restoring it gives nobody anything back by surprise, and the purge's FK
 * cascade strips nothing from anyone. The role keeps its id, permissions and the role it was copied
 * from, so RestoreCustomRole returns it exactly as it was.
 *
 * Like UpdateCustomRole it is not gated by the entitlement, so a downgraded site can still clean
 * up; on such a site RestoreCustomRole refuses, so the screen asks first and offers no undo
 * ({@see RestoreCustomRole::availableOnThisSite()}).
 *
 * A role already in the trash (a second click, or another manager a moment earlier) is left as it
 * is, and handle() says so by returning false. The permission catalog of every
 * worker drops the role at its next request (the registrar publishes a new catalog version when the
 * transaction commits).
 */
final readonly class DeleteCustomRole
{
    public function __construct(
        private CustomRoleRules $rules,
        private PermissionRegistrar $registrar,
    ) {}

    /**
     * @return bool true when this call moved the role to the trash, false when it was already there
     *
     * @throws AuthorizationException
     * @throws RoleChangeRejectedException
     */
    public function handle(User $actor, Role $role): bool
    {
        app(MembershipLock::class)->authorize($actor, Cast::int($role->site_id), 'roles.manage');   // never on someone's behalf, from outside the site, or without roles.manage there
        Gate::forUser($actor)->authorize('delete', $role);
        $this->rules->assertManageable($actor, $role);

        $moved = DB::transaction(function () use ($actor, $role): bool {
            // Lock the role row first: a grant or an invitation running now either committed before
            // this lock (and the checks below see it), or reads the row again under a share lock
            // after this transaction (RoleHierarchyGuard::lockLive) and finds it in the trash.
            $live = Role::query()->whereKey($role->getKey())->lockForUpdate()->first();
            if (! $live instanceof Role) {
                return false;
            }
            // the role as it is now: a manager above the actor may have raised its level meanwhile
            $this->rules->assertManageable($actor, $live);

            $held = DB::table('model_has_roles')->where('role_id', $live->getKey())->exists();
            $promised = Invitation::query()->pending()
                ->whereHas('roles', fn (Builder $roles): Builder => $roles->whereKey($live->getKey()))
                ->exists();

            if ($held || $promised) {
                throw new RoleChangeRejectedException('in_use');
            }

            $snapshot = [
                'name' => $live->getAttribute('name'),
                'level' => $live->level,
                'permissions' => $live->permissions->pluck('name')->all(),
            ];

            $live->delete();

            Audit::record('rbac.role.deleted', [
                'auditable_type' => Role::class,
                'auditable_id' => $live->getKey(),
                'meta' => $snapshot,
            ]);

            return true;
        });

        $this->registrar->forgetCachedPermissions();

        return $moved;
    }
}
