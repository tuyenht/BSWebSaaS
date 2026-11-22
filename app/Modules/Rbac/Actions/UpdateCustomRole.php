<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Events\CustomRolePermissionsAdding;
use App\Modules\Rbac\Exceptions\RoleChangeRejectedException;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\CustomRoleRules;
use App\Modules\Rbac\Support\MembershipLock;
use App\Modules\Shared\Support\Cast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * Rename / re-level / re-permission a custom role of this site that sits below the actor. System
 * roles are refused (INV-R4). Deliberately NOT gated by the `rbac.custom_roles` entitlement: a site
 * whose plan dropped the feature must still be able to tidy up the roles it already has. The audit
 * row carries the permission diff (INV-R6), so "who gave this group publish rights" is one query.
 * Adding permissions is announced first (CustomRolePermissionsAdding): a module keeping per-member
 * limits may refuse it, and nothing is written.
 *
 * The role is read again under the site's lock and its own row lock: a role moved to the trash
 * meanwhile is refused (`gone`), and an editor that passes the fingerprint it was opened with
 * ({@see CustomRoleRules::fingerprint()}) is refused when another manager saved the role since
 * (`changed_elsewhere`) rather than overwriting that save.
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
    public function handle(User $actor, Role $role, string $name, int $level, array $permissions, ?string $openedAs = null): Role
    {
        app(MembershipLock::class)->authorize($actor, Cast::int($role->site_id), 'roles.manage');   // never on someone's behalf, from outside the site, or without roles.manage there
        Gate::forUser($actor)->authorize('update', $role);
        $this->rules->assertManageable($actor, $role);

        $name = $this->rules->name($name, current: Cast::string($role->getAttribute('name')));
        $this->rules->assertLevel($actor, $level);

        return DB::transaction(function () use ($actor, $role, $name, $level, $permissions, $openedAs): Role {
            $this->rules->lockSite();
            $fresh = Role::query()->whereKey($role->getKey())->lockForUpdate()->first();
            if (! $fresh instanceof Role) {
                throw new RoleChangeRejectedException('gone');
            }
            $this->rules->assertManageable($actor, $fresh);
            if ($openedAs !== null && ! hash_equals(CustomRoleRules::fingerprint($fresh), $openedAs)) {
                throw new RoleChangeRejectedException('changed_elsewhere');
            }
            $this->rules->assertNameFree($name, except: $fresh);

            /** @var list<string> $before */
            $before = $fresh->permissions->pluck('name')->filter(fn (mixed $permission): bool => is_string($permission))->values()->all();
            $granted = $this->rules->permissions($actor, $permissions, $before);
            $previousName = $fresh->getAttribute('name');
            $previousLevel = $fresh->level;

            if (array_diff($granted, $before) !== []) {
                // e.g. an actor limited to some categories never makes the role work in every one
                Event::dispatch(new CustomRolePermissionsAdding($actor, $fresh, $before, $granted));
            }
            $fresh->forceFill(['name' => $name, 'level' => $level])->save();
            $fresh->syncPermissions($granted);

            Audit::record('rbac.role.updated', [
                'auditable_type' => Role::class,
                'auditable_id' => $fresh->getKey(),
                'meta' => [
                    'name' => $name,
                    'renamed_from' => $previousName !== $name ? $previousName : null,
                    'level' => ['from' => $previousLevel, 'to' => $level],
                    'added' => array_values(array_diff($granted, $before)),
                    'removed' => array_values(array_diff($before, $granted)),
                ],
            ]);

            return $fresh->refresh();
        });
    }
}
