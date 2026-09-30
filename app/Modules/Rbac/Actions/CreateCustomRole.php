<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Exceptions\RoleChangeRejectedException;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\CustomRoleRules;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Create a site's own role — blank, or forked off an existing role of the same site (design.md
 * §15.3: system roles stay locked; wanting something different means a custom role, which keeps
 * `forked_from_role_id` as provenance). Gated by `roles.manage` AND the site's `rbac.custom_roles`
 * entitlement (default off). Every invariant lives in {@see CustomRoleRules}.
 */
final readonly class CreateCustomRole
{
    public function __construct(
        private CustomRoleRules $rules,
        private Entitlement $entitlement,
    ) {}

    /**
     * @param  list<string>  $permissions
     *
     * @throws AuthorizationException
     * @throws RoleChangeRejectedException
     */
    public function handle(User $actor, string $name, int $level, array $permissions, ?Role $forkFrom = null): Role
    {
        app(SupportSession::class)->forbid();   // membership never changes on someone's behalf
        Gate::forUser($actor)->authorize('create', Role::class);
        $siteId = $this->rules->siteId();

        if (! $this->entitlement->featureEnabled('rbac.custom_roles')) {
            throw new RoleChangeRejectedException('custom_roles_disabled');
        }

        if ($forkFrom !== null && (int) $forkFrom->site_id !== $siteId) {
            throw new AuthorizationException('A role can only be forked from a role of this site.');
        }

        $name = $this->rules->name($name);
        $this->rules->assertLevel($actor, $level);
        $granted = $this->rules->permissions($actor, $permissions);

        return DB::transaction(function () use ($name, $level, $granted, $forkFrom, $siteId): Role {
            $role = new Role;
            $role->forceFill([
                'name' => $name,
                'guard_name' => PermissionRegistry::GUARD,
                'site_id' => $siteId,
                'level' => $level,
                'is_system' => false,
                'forked_from_role_id' => $forkFrom?->getKey(),
            ])->save();
            $role->syncPermissions($granted);

            Audit::record('rbac.role.created', [
                'auditable_type' => Role::class,
                'auditable_id' => $role->getKey(),
                'meta' => [
                    'name' => $name,
                    'level' => $level,
                    'permissions' => $granted,
                    'forked_from' => $forkFrom?->getAttribute('name'),
                ],
            ]);

            return $role;
        });
    }
}
