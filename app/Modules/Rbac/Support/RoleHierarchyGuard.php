<?php

namespace App\Modules\Rbac\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Hierarchy guard for role grants (design.md §15.1/§15.3, §2). Enforces the no-escalation
 * invariant: an actor may grant a role only if it is STRICTLY BELOW their own level, every
 * permission it carries is one the actor personally holds, and it belongs to their current
 * site team. This is the first place `role.level` is compared; AssignRole is its only caller.
 *
 * Level is read via a fresh team-scoped QUERY (`roles()`, not the `->roles` property) — spatie
 * may have eager-loaded `->roles` at another team during a gate check, so the property can be
 * stale. Permission coverage is checked with `hasPermissionTo`, which is team-scoped + cached.
 */
final readonly class RoleHierarchyGuard
{
    public function __construct(
        private PlatformAccess $platform,
        private CurrentSite $currentSite,
    ) {}

    /** Highest role level the actor holds in the current team (0 if none). */
    public function maxLevel(User $actor): int
    {
        $max = $actor->roles()->max('level');

        return is_numeric($max) ? (int) $max : 0;
    }

    /**
     * A platform super_admin who holds no role on the current site: what they would be allowed to
     * see is not limited by a level here. They change nothing on it either (MembershipLock). A
     * super_admin who IS a member of the site is measured by that membership like anyone else.
     */
    public function bypasses(User $actor): bool
    {
        return $this->platform->isSuperAdmin($actor)
            && ! ($this->currentSite->hasId() && MembershipLock::holdsRoleOn($actor, $this->currentSite->id()));
    }

    /**
     * The level every role the actor creates or edits must stay STRICTLY below: their own highest
     * level here — or, for a platform super_admin with no role here, the top template level, so
     * nothing custom ever sits at or above the site's owner.
     */
    public function levelCeiling(User $actor): int
    {
        return $this->bypasses($actor)
            ? PermissionRegistry::topTenantLevel()
            : $this->maxLevel($actor);
    }

    /**
     * Roles in the current team the actor may grant: strictly below their level AND whose
     * permissions the actor fully holds. The actor never sees the rest (visibility-scope).
     *
     * @return Collection<int, Role>
     */
    public function assignableRoles(User $actor): Collection
    {
        $team = $this->currentSite->id();

        if ($this->bypasses($actor)) {
            return Role::query()->where('site_id', $team)->get();
        }

        return Role::query()
            ->where('site_id', $team)
            ->where('level', '<', $this->maxLevel($actor))
            ->with('permissions')
            ->get()
            ->filter(fn (Role $role): bool => $this->actorCovers($actor, $role))
            ->values();
    }

    /**
     * A platform super_admin grants any PLATFORM role (the operator console). A website's role is
     * granted only by a member of that website, within their own level there — a super_admin with
     * no role on the site is refused here too, not only by MembershipLock.
     *
     * @throws AuthorizationException when the grant would escalate beyond the actor.
     */
    public function assertCanGrant(User $actor, Role $target): void
    {
        if ($this->platform->isSuperAdmin($actor)) {
            if ($target->site_id === PermissionRegistry::PLATFORM_TEAM_ID) {
                return;
            }
            if ($this->bypasses($actor)) {
                throw new AuthorizationException(__('admin.staff.locked'));
            }
        }

        $sameTeam = $target->site_id !== null
            && $this->currentSite->hasId()
            && $target->site_id === $this->currentSite->id();

        if ($sameTeam
            && $target->level < $this->maxLevel($actor)
            && $this->actorCovers($actor, $target)
        ) {
            return;
        }

        throw new AuthorizationException('You may only grant a role below your own level, within the permissions you hold.');
    }

    /** True if the actor personally holds every permission the role would grant. */
    private function actorCovers(User $actor, Role $role): bool
    {
        foreach ($role->permissions as $permission) {
            // By name: spatie then reads the permission and the roles holding it from its cached
            // catalog. Passing the loaded model would lazy-load its roles, across every site, once per permission.
            try {
                if (! $actor->hasPermissionTo($permission->name)) {
                    return false;
                }
            } catch (PermissionDoesNotExist) {
                // a permission newer than this worker's catalog (between rbac:sync and the reload): refuse
                return false;
            }
        }

        return true;
    }
}
