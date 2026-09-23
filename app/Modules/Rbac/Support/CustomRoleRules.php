<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Exceptions\RoleChangeRejectedException;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The invariants every custom-role write shares (Create/Update/DeleteCustomRole), in one place so
 * the three actions cannot drift apart. The RBAC tables are global (D6 — no RLS): the checks here
 * are the ONLY thing standing between a tenant and another tenant's roles, so each one resolves
 * against the current site explicitly rather than trusting what the caller passed in.
 */
final readonly class CustomRoleRules
{
    public const int MAX_NAME_LENGTH = 60;

    public function __construct(
        private CurrentSite $currentSite,
        private RoleHierarchyGuard $guard,
        private RoleCeiling $ceiling,
    ) {}

    /** The current site — a custom role never exists outside one (never on platform team 0). */
    public function siteId(): int
    {
        if (! $this->currentSite->hasId()) {
            throw new AuthorizationException('Custom roles exist only inside a site.');
        }

        return $this->currentSite->id();
    }

    /**
     * $role may be edited or deleted by $actor: it belongs to THIS site, it is custom (INV-R4 —
     * system roles are locked) and it sits strictly below the actor (INV-R3).
     */
    public function assertManageable(User $actor, Role $role): void
    {
        if ((int) $role->site_id !== $this->siteId()
            || $role->is_system
            || $role->level >= $this->guard->levelCeiling($actor)) {
            throw new AuthorizationException('You may only change a custom role of this site that sits below your own level.');
        }
    }

    /** The name to store: trimmed, 1–60 chars, not reserved, unique on this site — case-insensitively. */
    public function name(string $raw, ?Role $except = null): string
    {
        $name = trim($raw);
        $length = mb_strlen($name);
        if ($length === 0 || $length > self::MAX_NAME_LENGTH) {
            throw new RoleChangeRejectedException('name_invalid');
        }

        $folded = mb_strtolower($name);
        if (in_array($folded, array_map(mb_strtolower(...), PermissionRegistry::reservedRoleNames()), true)) {
            throw new RoleChangeRejectedException('name_reserved');
        }

        // Folded in PHP, not SQL lower(): Postgres lowercases by the database's LC_CTYPE, and under a
        // C locale "Ế" would not fold. A site holds a handful of roles, so pulling the names is cheap.
        $siblings = Role::query()->where('site_id', $this->siteId());
        if ($except !== null) {
            $siblings->whereKeyNot($except->getKey());
        }

        $taken = $siblings->pluck('name')
            ->contains(fn (mixed $existing): bool => is_string($existing) && mb_strtolower($existing) === $folded);
        if ($taken) {
            throw new RoleChangeRejectedException('name_taken');
        }

        return $name;
    }

    /** INV-R3: 1 ≤ level < the actor's level ceiling. */
    public function assertLevel(User $actor, int $level): void
    {
        if ($level < 1 || $level >= $this->guard->levelCeiling($actor)) {
            throw new RoleChangeRejectedException('level_invalid');
        }
    }

    /**
     * The permission set to store (INV-R1/R2/R5). $requested is closed under implication and may
     * only reach what the actor can grant — plus whatever the role already holds that the actor
     * cannot touch, which is KEPT regardless of the request (they can neither add nor strip it).
     * Anything the role holds outside the SITE ceiling (a module since switched off) is dropped.
     *
     * @param  list<string>  $requested
     * @param  list<string>  $current  the role's permissions now ([] for a new role)
     * @return list<string>
     *
     * @throws AuthorizationException when $requested reaches beyond what the actor may grant
     */
    public function permissions(User $actor, array $requested, array $current = []): array
    {
        $siteCeiling = $this->ceiling->forSite();
        $grantable = $this->ceiling->grantableBy($actor, $siteCeiling);
        $untouchable = array_values(array_diff(array_intersect($current, $siteCeiling), $grantable));

        $normalized = PermissionRegistry::withImplied($requested);
        if (array_diff($normalized, $grantable, $untouchable) !== []) {
            throw new AuthorizationException('You may only grant permissions this site is entitled to and that you hold yourself.');
        }

        return array_values(array_unique([...array_intersect($normalized, $grantable), ...$untouchable]));
    }
}
