<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Exceptions\RoleChangeRejectedException;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\CustomRoleRules;
use App\Modules\Rbac\Support\MembershipLock;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * Bring a custom role of this site back from the trash («Hoàn tác» after a delete, or the trash
 * list): the same row, so its id, permissions and the role it was copied from come back exactly as
 * they were. It had no holder when it went to the trash, so it gives nobody anything back.
 *
 * Checked again as for a new role: `roles.manage` on this site (never on someone's behalf, never
 * from outside it), a custom role of this site below the actor, every permission it holds one the
 * actor may grant (those outside the site's modules now are dropped), its name not taken by a live
 * role meanwhile, room under the per-site cap — and the site's plan must still include custom roles
 * (`rbac.custom_roles`): restoring adds a custom role back, which a plan without them does not
 * allow. Like a new role it comes back with no holders, so the category-limit veto on adding
 * permissions to a held role does not apply. Its stored name is kept even if it has become
 * reserved since, as an edit keeps it. A role that is no longer in the trash (a second click) is
 * returned as it is. A role in the trash that someone holds anyway is refused (`restore_in_use`).
 */
final readonly class RestoreCustomRole
{
    public function __construct(
        private CustomRoleRules $rules,
        private Entitlement $entitlement,
        private PermissionRegistrar $registrar,
    ) {}

    /**
     * Whether a role moved to the trash on this site can be brought back now. The screen asks before
     * a delete and offers no «Hoàn tác» when it cannot.
     */
    public function availableOnThisSite(): bool
    {
        return $this->entitlement->featureEnabled('rbac.custom_roles');
    }

    /**
     * @throws AuthorizationException
     * @throws RoleChangeRejectedException custom_roles_disabled · restore_name_taken · restore_in_use · too_many · gone
     */
    public function handle(User $actor, Role $role): Role
    {
        app(MembershipLock::class)->authorize($actor, Cast::int($role->site_id), 'roles.manage');   // never on someone's behalf, from outside the site, or without roles.manage there
        Gate::forUser($actor)->authorize('restore', $role);
        $this->rules->assertManageable($actor, $role);

        if (! $this->availableOnThisSite()) {
            throw new RoleChangeRejectedException('custom_roles_disabled');
        }

        $restored = DB::transaction(function () use ($actor, $role): Role {
            $this->rules->lockSite();
            $row = Role::withTrashed()->whereKey($role->getKey())->where('site_id', $this->rules->siteId())->lockForUpdate()->first();
            if (! $row instanceof Role) {
                throw new RoleChangeRejectedException('gone');   // purged meanwhile
            }
            $this->rules->assertManageable($actor, $row);
            if (! $row->trashed()) {
                return $row;
            }
            // Delete refuses a held role, but a holder can still reach one in the trash (an old worker
            // during a deploy, a code-only rollback, hand-written SQL). Restoring would hand that holder
            // the role's permissions again without anyone choosing to, so refuse. The row lock keeps a
            // new holder out until this transaction ends (its foreign key needs a share lock on it).
            if (DB::table('model_has_roles')->where('role_id', $row->getKey())->exists()) {
                throw new RoleChangeRejectedException('restore_in_use');
            }

            /** @var list<string> $held */
            $held = $row->permissions->pluck('name')->filter(fn (mixed $permission): bool => is_string($permission))->values()->all();
            $kept = $this->rules->restorable($actor, $held);
            $name = Cast::string($row->getAttribute('name'));
            $this->rules->assertNameFree($name, except: $row, reason: 'restore_name_taken');
            $this->rules->assertRoomForAnother();

            $row->restore();
            // stored exactly as audited: dropped ones go, and the implied ones the rules add come with them
            if (array_diff($held, $kept) !== [] || array_diff($kept, $held) !== []) {
                $row->syncPermissions($kept);
            }

            Audit::record('rbac.role.restored', [
                'auditable_type' => Role::class,
                'auditable_id' => $row->getKey(),
                'meta' => [
                    'name' => $name,
                    'level' => $row->level,
                    'permissions' => $kept,
                    'dropped' => array_values(array_diff($held, $kept)),
                ],
            ]);

            return $row;
        });

        $this->registrar->forgetCachedPermissions();

        return $restored;
    }
}
