<?php

namespace App\Modules\Rbac\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Models\Role;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

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
     * Roles in the current team the actor may grant: strictly below their level AND whose
     * permissions the actor fully holds. The actor never sees the rest (visibility-scope).
     *
     * @return Collection<int, Role>
     */
    public function assignableRoles(User $actor): Collection
    {
        $team = $this->currentSite->id();

        if ($this->platform->isSuperAdmin($actor)) {
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
     * @throws AuthorizationException when the grant would escalate beyond the actor.
     */
    public function assertCanGrant(User $actor, Role $target): void
    {
        if ($this->platform->isSuperAdmin($actor)) {
            return;
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
            if (! $actor->hasPermissionTo($permission)) {
                return false;
            }
        }

        return true;
    }
}
