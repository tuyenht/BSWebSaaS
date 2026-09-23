<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Registry\ModuleCatalog;

/**
 * The two ceilings a custom role lives under (design.md §15.3, owner's model 06/07):
 *   INV-R1 — the SITE: only permissions owned by a module the site is entitled to;
 *   INV-R2 — the EDITOR: only permissions they hold themselves (no self-escalation by proxy).
 * A platform super_admin holds no tenant role, so only the site bounds them.
 */
final readonly class RoleCeiling
{
    public function __construct(
        private Entitlement $entitlement,
        private PlatformAccess $platform,
    ) {}

    /**
     * Tenant permissions any role on the current site may carry (INV-R1). Fail-closed: no site
     * context or no entitled module → empty.
     *
     * @return list<string>
     */
    public function forSite(): array
    {
        $entitled = $this->entitlement->entitledModuleKeys();

        $prefixes = [];
        foreach (ModuleCatalog::all() as $module) {
            if ($module['permission_prefix'] !== null && in_array($module['key'], $entitled, true)) {
                $prefixes[] = $module['permission_prefix'];
            }
        }

        return array_values(array_filter(
            PermissionRegistry::TENANT_PERMISSIONS,
            fn (string $permission): bool => in_array(PermissionRegistry::prefixOf($permission), $prefixes, true),
        ));
    }

    /**
     * What $actor may add to — or strip from — a role on the current site: the site ceiling ∩ the
     * actor's own permissions (INV-R2).
     *
     * @param  list<string>  $siteCeiling  {@see forSite()}, passed in when the caller already has it
     * @return list<string>
     */
    public function grantableBy(User $actor, array $siteCeiling): array
    {
        if ($this->platform->isSuperAdmin($actor)) {
            return $siteCeiling;
        }

        return array_values(array_filter(
            $siteCeiling,
            fn (string $permission): bool => $actor->hasPermissionTo($permission),
        ));
    }
}
