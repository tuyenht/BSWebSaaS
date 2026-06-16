<?php

namespace App\Modules\Rbac\Console;

use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use Illuminate\Console\Command;
use Spatie\Permission\PermissionRegistrar;

/**
 * Materialize the permission catalog + platform roles from the registry. Idempotent
 * (findOrCreate + syncPermissions), so it is safe to run on every deploy.
 */
final class SyncRbac extends Command
{
    protected $signature = 'rbac:sync';

    protected $description = 'Sync permissions + platform roles from the registry (idempotent).';

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

        $registrar->forgetCachedPermissions();

        $this->info(sprintf(
            'RBAC synced: %d permissions, %d platform roles.',
            count(PermissionRegistry::permissions()),
            count(PermissionRegistry::platformRoles()),
        ));

        return self::SUCCESS;
    }
}
