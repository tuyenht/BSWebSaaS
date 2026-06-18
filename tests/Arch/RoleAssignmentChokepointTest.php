<?php

use Symfony\Component\Finder\Finder;

/*
 * Chokepoint guard (design.md §15.3, §2): every role MUTATION in app code must route through
 * the guarded AssignRole action — a stray assignRole/removeRole/syncRoles would bypass the
 * role.level escalation guard. Only the action itself + the two verified system callers
 * (bootstrap, provisioning) may mutate roles directly. Adding a fourth caller fails this test.
 */

it('routes every role mutation through the AssignRole chokepoint', function () {
    $allowed = [
        'app/Modules/Rbac/Actions/AssignRole.php',           // the guarded chokepoint
        'app/Modules/Rbac/Console/BootstrapSuperAdmin.php',  // system bootstrap (verified)
        'app/Modules/Provisioning/Actions/ProvisionSite.php', // system provisioning (verified)
    ];

    $pattern = '/->\s*(assignRole|removeRole|syncRoles)\s*\(/';
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        if (preg_match($pattern, (string) $file->getContents()) === 1) {
            $rel = str_replace(base_path().'/', '', (string) $file->getRealPath());
            if (! in_array($rel, $allowed, true)) {
                $offenders[] = $rel;
            }
        }
    }

    expect($offenders)->toBe([]);
});
