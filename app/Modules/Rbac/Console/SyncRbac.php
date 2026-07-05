<?php

namespace App\Modules\Rbac\Console;

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
    }

    /**
     * Grant the is_system tenant-template roles the template permissions they are missing.
     * Today these roles are GLOBAL definitions on the platform team (site_id = 0) shared by
     * every site — ProvisionSite findOrCreates them there; users get per-site assignments via
     * model_has_roles.site_id. When tier-2 inc-4 moves to per-site copy-on-write roles, this
     * back-fill moves with it. Every site_type in use contributes its templates.
     */
    private function backfillSystemTenantRoles(): int
    {
        $granted = 0;

        // site_types is a global table (D6) — readable on the app connection.
        $typeKeys = DB::table('site_types')->pluck('key');

        foreach ($typeKeys as $typeKey) {
            if (! is_string($typeKey)) {
                continue;
            }

            foreach (PermissionRegistry::tenantRoleTemplates($typeKey) as $name => $spec) {
                $role = Role::query()
                    ->where('name', $name)
                    ->where('site_id', PermissionRegistry::PLATFORM_TEAM_ID)
                    ->where('is_system', true)
                    ->first();

                if ($role === null) {
                    continue; // template role never materialized — ProvisionSite's job, not sync's
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
