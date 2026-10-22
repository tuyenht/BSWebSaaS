<?php

namespace App\Modules\Rbac\Console;

use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Materialize the permission catalog + platform roles from the registry, then back-fill the
 * per-site SYSTEM tenant roles. Site-type templates drift as the platform grows — a module
 * shipped after a site was provisioned adds permissions its is_system roles never received
 * (ProvisionSite only materializes the template once). The back-fill is ADD-ONLY: a role
 * receives the template permissions it is missing, and nothing is ever revoked on deploy
 * (revoking is a deliberate decision, never a sync side-effect). Custom (non-system) roles
 * are never touched. Idempotent, so it is safe to run on every deploy.
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

            $registrar->forgetCachedPermissions();

            $this->info(sprintf(
                'RBAC synced: %d permissions, %d platform roles, %d tenant grants back-filled.',
                count(PermissionRegistry::permissions()),
                count(PermissionRegistry::platformRoles()),
                $backfilled,
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
}
