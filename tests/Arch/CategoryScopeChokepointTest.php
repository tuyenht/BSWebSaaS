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

/*
 * Every road that files an entry under categories on a person's behalf goes through
 * EntryCategoryWriter, which applies that person's category limits. Only the trait that defines
 * assignCategories(), the writer, the product editor (product categories are not limited) and the
 * provisioning seeders (they act for nobody) may call it. Adding a caller means checking it applies
 * the limits — or routing it through the writer.
 */
it('files entries under categories only through the writer that applies category limits', function (): void {
    $allowed = [
        'app/Modules/Cms/Concerns/HasCategoryTaxonomies.php',                          // the definition
        'app/Modules/Cms/Support/EntryCategoryWriter.php',                             // applies the limits
        'app/Modules/Admin/Livewire/Catalog/Editor.php',                               // products: not limited
        'app/Modules/Provisioning/Actions/SeedAnVinhContent.php',                      // seeder, no person
        'app/Modules/Provisioning/Actions/PromoteAnVinhProductPostsToCatalog.php',     // seeder, no person
    ];

    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        if (str_contains($file->getContents(), 'assignCategories(')) {
            $rel = str_replace(base_path().'/', '', (string) $file->getRealPath());
            if (! in_array($rel, $allowed, true)) {
                $offenders[] = $rel;
            }
        }
    }

    expect($offenders)->toBe([]);
});

/*
 * The same writer, reached around assignCategories(): writing the entries' taxonomy pivot directly
 * (sync, attach, detach…) would file a post without the category limits. Only the trait's own
 * sync() and the copy's facet terms (never a category) may.
 */
it('writes the entry taxonomy pivot directly only in the known places', function (): void {
    $allowed = [
        'app/Modules/Cms/Concerns/HasCategoryTaxonomies.php',   // assignCategories() itself
        'app/Modules/Cms/Actions/DuplicateEntry.php',           // copies facet terms only (positions ≥ FACET_POSITION_BASE)
    ];

    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        if (preg_match('/->(taxonomies|sectors)\(\)\s*->\s*(sync|syncWithoutDetaching|syncWithPivotValues|attach|detach|toggle|updateExistingPivot)\(|\$relation->sync\(/', $file->getContents()) === 1) {
            $rel = str_replace(base_path().'/', '', (string) $file->getRealPath());
            if (! in_array($rel, $allowed, true)) {
                $offenders[] = $rel;
            }
        }
    }

    expect($offenders)->toBe([]);
});
