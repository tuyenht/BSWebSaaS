<?php

use Symfony\Component\Finder\Finder;

/*
 * Chokepoint guard (design.md §15.3, §2): every role MUTATION in app code must route through
 * a guarded role action (AssignRole / RevokeRole) — a stray assignRole/removeRole/syncRoles
 * would bypass the role.level escalation guard. Only those actions + the two verified system
 * callers (bootstrap, provisioning) may mutate roles directly. Adding another caller fails this test.
 */

it('routes every role mutation through a guarded role action', function (): void {
    $allowed = [
        'app/Modules/Rbac/Actions/AssignRole.php',            // guarded grant chokepoint
        'app/Modules/Rbac/Actions/RevokeRole.php',            // guarded revoke chokepoint
        'app/Modules/Rbac/Console/BootstrapSuperAdmin.php',   // system bootstrap (verified)
        'app/Modules/Provisioning/Actions/ProvisionSite.php', // system provisioning (verified)
    ];

    $pattern = '/->\s*(assignRole|removeRole|syncRoles)\s*\(/';
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        if (preg_match($pattern, $file->getContents()) === 1) {
            $rel = str_replace(base_path().'/', '', (string) $file->getRealPath());
            if (! in_array($rel, $allowed, true)) {
                $offenders[] = $rel;
            }
        }
    }

    expect($offenders)->toBe([]);
});

/*
 * The same guard for what a role CARRIES. Once tenants can edit custom roles, a stray
 * givePermissionTo/syncPermissions would bypass the ceilings (site entitlement + the editor's own
 * permissions) and the system-role lock. Only the custom-role actions and the two system callers
 * that materialize templates (rbac:sync back-fill, provisioning) may change role permissions.
 */
it('routes every role-permission mutation through a guarded custom-role action', function (): void {
    $allowed = [
        'app/Modules/Rbac/Actions/CreateCustomRole.php',      // guarded: CustomRoleRules ceilings
        'app/Modules/Rbac/Actions/UpdateCustomRole.php',      // guarded: CustomRoleRules ceilings
        'app/Modules/Rbac/Console/SyncRbac.php',              // system: registry → platform + template roles
        'app/Modules/Provisioning/Actions/ProvisionSite.php', // system: template roles of a new site
    ];

    $pattern = '/->\s*(givePermissionTo|syncPermissions|revokePermissionTo)\s*\(/';
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        if (preg_match($pattern, $file->getContents()) === 1) {
            $rel = str_replace(base_path().'/', '', (string) $file->getRealPath());
            if (! in_array($rel, $allowed, true)) {
                $offenders[] = $rel;
            }
        }
    }

    expect($offenders)->toBe([]);
});
