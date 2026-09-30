<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Exceptions\RoleChangeRejectedException;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\CustomRoleRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Rename / re-level / re-permission a custom role of this site that sits below the actor. System
 * roles are refused (INV-R4). Deliberately NOT gated by the `rbac.custom_roles` entitlement: a site
 * whose plan dropped the feature must still be able to tidy up the roles it already has. The audit
 * row carries the permission diff (INV-R6), so "who gave this group publish rights" is one query.
 */
final readonly class UpdateCustomRole
{
    public function __construct(private CustomRoleRules $rules) {}

    /**
     * @param  list<string>  $permissions
     *
     * @throws AuthorizationException
     * @throws RoleChangeRejectedException
     */
    public function handle(User $actor, Role $role, string $name, int $level, array $permissions): Role
    {
        app(SupportSession::class)->forbid();   // membership never changes on someone's behalf
        Gate::forUser($actor)->authorize('update', $role);
        $this->rules->assertManageable($actor, $role);

        $name = $this->rules->name($name, except: $role);
        $this->rules->assertLevel($actor, $level);

        /** @var list<string> $before */
        $before = $role->permissions->pluck('name')->filter(fn (mixed $permission): bool => is_string($permission))->values()->all();
        $granted = $this->rules->permissions($actor, $permissions, $before);

        $previousName = $role->getAttribute('name');
        $previousLevel = $role->level;

        DB::transaction(function () use ($role, $name, $level, $granted, $before, $previousName, $previousLevel): void {
            $role->forceFill(['name' => $name, 'level' => $level])->save();
            $role->syncPermissions($granted);

            Audit::record('rbac.role.updated', [
                'auditable_type' => Role::class,
                'auditable_id' => $role->getKey(),
                'meta' => [
                    'name' => $name,
                    'renamed_from' => $previousName !== $name ? $previousName : null,
                    'level' => ['from' => $previousLevel, 'to' => $level],
                    'added' => array_values(array_diff($granted, $before)),
                    'removed' => array_values(array_diff($before, $granted)),
                ],
            ]);
        });

        return $role->refresh();
    }
}
