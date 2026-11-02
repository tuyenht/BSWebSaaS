<?php

namespace App\Modules\Rbac\Console;

use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Materialize the permission catalog + platform roles from the registry, then bring existing
 * tenant roles up to date. Two passes, both ADD-ONLY — a role only ever receives permissions,
 * nothing is revoked on deploy (revoking is a deliberate decision, never a sync side-effect):
 *
 *  - SYSTEM tenant roles get the template permissions they are missing. Site-type templates
 *    drift as the platform grows — a module shipped after a site was provisioned adds
 *    permissions its is_system roles never received (ProvisionSite materializes them once).
 *  - CUSTOM (non-system) roles are closed under PermissionRegistry::IMPLIES: a role holding a
 *    permission gains what that permission implies (`domains.manage` → `domains.view`). Saving
 *    a custom role already normalizes it this way; this heals roles saved before an implication
 *    was added, so a screen that checks the read permission never locks out a member who may
 *    change the same thing. Custom roles never receive template grants.
 *
 * Idempotent, so it is safe to run on every deploy.
 */
final class SyncRbac extends Command
{
    #[\Override]
    protected $signature = 'rbac:sync';

    #[\Override]
    protected $description = 'Sync permissions + platform roles, back-fill system tenant roles (idempotent).';

    public function handle(PermissionRegistrar $registrar): int
    {
        try {
            // findOrCreate reads the cached catalog: after migrate:fresh or a restored backup that cache
            // still lists rows the tables no longer hold, so nothing would be created and the role sync
            // below would fail on a missing permission.
            $registrar->forgetCachedPermissions();
            $registrar->setPermissionsTeamId(PermissionRegistry::PLATFORM_TEAM_ID);

            foreach (PermissionRegistry::permissions() as $name) {
                Permission::findOrCreate($name, PermissionRegistry::GUARD);
            }

            foreach (PermissionRegistry::platformRoles() as $name => $spec) {
                $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
                $role->forceFill(['level' => $spec['level'], 'is_system' => true])->save();

                $permissions = $spec['permissions'] === '*'
                    ? PermissionRegistry::permissions()
                    : $spec['permissions'];
                $role->syncPermissions($permissions);
            }

            $backfilled = $this->backfillSystemTenantRoles();
            $closed = $this->closeCustomRolesUnderImplies();

            $registrar->forgetCachedPermissions();

            $this->info(sprintf(
                'RBAC synced: %d permissions, %d platform roles, %d tenant grants back-filled, %d custom-role grants closed.',
                count(PermissionRegistry::permissions()),
                count(PermissionRegistry::platformRoles()),
                $backfilled,
                $closed,
            ));

            return self::SUCCESS;
        } finally {
            // Release the forced platform override — it otherwise WINS over the current-site
            // resolver for everything that runs next in this process (console/queue), e.g. a
            // provisioning call would silently create its tenant roles on team 0.
            app(TeamContext::class)->forget();
        }
    }

    /**
     * Grant every is_system tenant-template role the template permissions it is missing.
     * Template roles exist in two shapes (ProvisionSite inherits whatever team context the
     * registrar happens to carry): per-site rows (site_id = N — the prod/genesis shape) and
     * shared rows on the platform team (site_id = 0). Both are back-filled; template names
     * (owner/admin/editor/…) never collide with platform role names, and role_has_permissions
     * carries no team column, so grants are context-free. When tier-2 inc-4 formalizes
     * per-site copy-on-write roles, this back-fill follows that shape.
     */
    private function backfillSystemTenantRoles(): int
    {
        $granted = 0;

        // sites/site_types are global tables (D6) — readable on the app connection.
        /** @var array<int, string> $siteTypeBySiteId */
        $siteTypeBySiteId = DB::table('sites')
            ->join('site_types', 'sites.site_type_id', '=', 'site_types.id')
            ->pluck('site_types.key', 'sites.id')
            ->filter(fn ($key): bool => is_string($key))
            ->all();

        $everyTypeInUse = array_values(array_unique(array_values($siteTypeBySiteId)));

        foreach (Role::query()->where('is_system', true)->get() as $role) {
            $roleName = $role->getAttribute('name');
            $rawSiteId = $role->getAttribute('site_id');
            if (! is_string($roleName) || ! is_numeric($rawSiteId)) {
                continue;
            }

            // A per-site role follows its own site's type; a shared (team-0) role serves every
            // site, so every type in use contributes (GĐ1: only `corporate` exists).
            $siteId = (int) $rawSiteId;
            $typeKeys = $siteId === PermissionRegistry::PLATFORM_TEAM_ID
                ? $everyTypeInUse
                : array_filter([$siteTypeBySiteId[$siteId] ?? null], is_string(...));

            foreach ($typeKeys as $typeKey) {
                $spec = PermissionRegistry::tenantRoleTemplates($typeKey)[$roleName] ?? null;
                if ($spec === null) {
                    continue; // platform roles / names outside the template
                }

                $template = $spec['permissions'] === '*'
                    ? PermissionRegistry::TENANT_PERMISSIONS
                    : $spec['permissions'];

                $current = $role->permissions->pluck('name')
                    ->map(fn ($name): string => is_string($name) ? $name : '')
                    ->all();
                $missing = array_values(array_diff($template, $current));
                if ($missing !== []) {
                    $role->givePermissionTo($missing);
                    $granted += count($missing);
                }
            }
        }

        return $granted;
    }

    /**
     * Give every custom (non-system) role the permissions its own permissions imply and it lacks.
     * Only what IMPLIES derives from the role's current grants is added — never a template grant,
     * never a revocation. Roles are read by keyset, 500 at a time, each chunk with its permissions
     * in one eager load: every website's custom roles never sit in memory at once.
     */
    private function closeCustomRolesUnderImplies(): int
    {
        $granted = 0;

        Role::query()->where('is_system', false)->with('permissions')->chunkById(500, function (Collection $roles) use (&$granted): void {
            foreach ($roles as $role) {
                $current = array_values(array_map(
                    fn ($name): string => is_string($name) ? $name : '',
                    $role->permissions->pluck('name')->all(),
                ));
                $missing = array_values(array_diff(PermissionRegistry::withImplied($current), $current));

                if ($missing !== []) {
                    $role->givePermissionTo($missing);
                    $granted += count($missing);
                }
            }
        });

        return $granted;
    }
}
