<?php

use Symfony\Component\Finder\Finder;

/*
 * Chokepoint guard for member category limits (member_category_scopes): a stray write could limit a
 * member nobody chose to limit, or quietly lift a limit ("no rows" reads as "every category"). Only
 * the model, the reader and the known writers may name the table or its model — every other module
 * asks CategoryScopes. Adding another file here means checking it keeps those rules.
 */
it('touches member category limits only through the reader and the known writers', function (): void {
    $allowed = [
        'app/Modules/Cms/Models/MemberCategoryScope.php',                  // the model
        'app/Modules/Cms/Support/CategoryScopes.php',                      // the reader (read only)
        'app/Modules/Cms/Actions/SetMemberCategoryScopes.php',             // guarded user-facing writer
        'app/Modules/Cms/Listeners/ForgetMemberCategoryScopes.php',        // left the site / never limited
        'app/Modules/Cms/Actions/Taxonomies/DeleteTaxonomy.php',           // moves rows onto a merge target
    ];

    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        // The model by its exact name (the listener's or action's class name is not the model).
        if (preg_match('/\bMemberCategoryScope\b|member_category_scopes/', $file->getContents()) === 1) {
            $rel = str_replace(base_path().'/', '', (string) $file->getRealPath());
            if (! in_array($rel, $allowed, true)) {
                $offenders[] = $rel;
            }
        }
    }

    expect($offenders)->toBe([]);
});
