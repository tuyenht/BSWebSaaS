<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Exceptions\RoleChangeRejectedException;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The invariants every custom-role write shares (Create/Update/Delete/RestoreCustomRole), in one
 * place so the actions cannot drift apart. The RBAC tables are global (D6 — no RLS): the checks here
 * are the ONLY thing standing between a tenant and another tenant's roles, so each one resolves
 * against the current site explicitly rather than trusting what the caller passed in.
 *
 * "Is this name free?" and "is there room for one more?" are only true inside the write's
 * transaction, after lockSite(): two saves racing on one site then run one after the other, and the
 * second sees the first's row (the database's unique is case-sensitive, the rule here is not).
 */
final readonly class CustomRoleRules
{
    public const int MAX_NAME_LENGTH = 60;

    /**
     * The most custom roles one website keeps (live ones; the trash does not count). Every role is
     * in the one permission catalog every worker loads, for all websites together, so a website
     * cannot grow it without bound.
     */
    public const int MAX_CUSTOM_ROLES = 50;

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
     * $role may be edited, deleted or restored by $actor: it belongs to THIS site, it is custom
     * (INV-R4 — system roles are locked) and it sits strictly below the actor (INV-R3).
     */
    public function assertManageable(User $actor, Role $role): void
    {
        if ((int) $role->site_id !== $this->siteId()
            || $role->is_system
            || $role->level >= $this->guard->levelCeiling($actor)) {
            throw new AuthorizationException('You may only change a custom role of this site that sits below your own level.');
        }
    }

    /**
     * The name to store: trimmed, composed (NFC, so a name typed on another keyboard compares equal),
     * 1–60 chars, and not the name or the shown label of a system or platform role in any language.
     * Whether it is free on this site is checked by assertNameFree() inside the write. $current: the
     * role's stored name — an edit that keeps it (whatever its case) is not refused for a name that
     * became reserved after the role got it.
     */
    public function name(string $raw, ?string $current = null): string
    {
        $name = self::compose(trim($raw));
        $length = mb_strlen($name);
        if ($length === 0 || $length > self::MAX_NAME_LENGTH) {
            throw new RoleChangeRejectedException('name_invalid');
        }

        $kept = $current !== null && self::fold($name) === self::fold($current);
        if (! $kept && in_array(self::fold($name), $this->reservedFolded(), true)) {
            throw new RoleChangeRejectedException('name_reserved');
        }

        return $name;
    }

    /**
     * Serialize the custom-role writes of this site until the transaction ends. Call it first in
     * the write's transaction, before the name and room checks.
     */
    public function lockSite(): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['custom-roles:'.$this->siteId()]);
    }

    /**
     * No live role of this site carries $name, whatever its case ($except: the role being renamed).
     * Folded in PHP, not SQL lower(): Postgres lowercases by the database's LC_CTYPE, and under a C
     * locale "Ế" would not fold. A site holds at most a few dozen roles, so pulling the names is
     * cheap. A role in the trash does not hold its name.
     */
    public function assertNameFree(string $name, ?Role $except = null, string $reason = 'name_taken'): void
    {
        $siblings = Role::query()->where('site_id', $this->siteId());
        if ($except !== null) {
            $siblings->whereKeyNot($except->getKey());
        }

        $folded = self::fold($name);
        $taken = $siblings->pluck('name')
            ->contains(fn (mixed $existing): bool => is_string($existing) && self::fold($existing) === $folded);
        if ($taken) {
            throw new RoleChangeRejectedException($reason);
        }
    }

    /** One more live custom role still fits under MAX_CUSTOM_ROLES on this site. */
    public function assertRoomForAnother(): void
    {
        $count = Role::query()->where('site_id', $this->siteId())->where('is_system', false)->count();
        if ($count >= self::MAX_CUSTOM_ROLES) {
            throw new RoleChangeRejectedException('too_many');
        }
    }

    /**
     * What an editor loaded: name, level and permissions. A save that carries the fingerprint it was
     * opened with is refused when the role changed since (two managers editing one role).
     */
    public static function fingerprint(Role $role): string
    {
        $permissions = array_values(array_filter($role->permissions->pluck('name')->all(), is_string(...)));
        sort($permissions);

        return hash('sha256', json_encode([$role->getAttribute('name'), $role->level, $permissions], JSON_THROW_ON_ERROR));
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

    /**
     * The permissions a role coming back from the trash may keep: the actor must be able to grant
     * every one it holds, exactly as for a new role with those permissions (INV-R2); the ones outside
     * the site's ceiling now (a module switched off meanwhile) are dropped, as an edit drops them.
     *
     * @param  list<string>  $held  the trashed role's permissions
     * @return list<string>
     *
     * @throws AuthorizationException when the role holds a permission the actor may not grant
     */
    public function restorable(User $actor, array $held): array
    {
        return $this->permissions($actor, array_values(array_intersect($held, $this->ceiling->forSite())));
    }

    /**
     * Every reserved name, folded: the technical names (code resolves those: `assignRole('owner')`,
     * the platform gate) and the label each one is shown by in every admin language, since a custom
     * role is shown by its raw name and «Quản trị» would read exactly like the system role.
     *
     * @return list<string>
     */
    private function reservedFolded(): array
    {
        $translator = app('translator');
        $locales = array_map(static fn (string $path): string => basename($path, '.json'), glob(lang_path('*.json')) ?: []);

        $reserved = [];
        foreach (PermissionRegistry::reservedRoleNames() as $technical) {
            $reserved[] = self::fold($technical);
            foreach ($locales as $locale) {
                $label = $translator->get('rbac.role.'.$technical, [], $locale, false);
                if (is_string($label) && $label !== 'rbac.role.'.$technical) {
                    $reserved[] = self::fold($label);
                }
            }
        }

        return array_values(array_unique($reserved));
    }

    private static function compose(string $text): string
    {
        $composed = class_exists('Normalizer') ? \Normalizer::normalize($text, \Normalizer::FORM_C) : false;

        return is_string($composed) ? $composed : $text;
    }

    private static function fold(string $text): string
    {
        return mb_strtolower(self::compose(trim($text)));
    }
}
