<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Policies\EntryPolicy;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;

/*
 * Architecture invariants (CLAUDE.md §6) — every rule below is enforced.
 */

// 1) Every Eloquent model whose table carries a site_id column IS a tenant model and MUST use
//    the BelongsToSite trait (the RLS global scope at the app layer). Auto-discovered across ALL
//    of app/ — not just app/Modules/*/Models — so a tenant model that forgets the trait fails CI
//    wherever it lives, including app/Models where a bare `make:model` puts it. The code-layer
//    twin of tests/Feature/Tenancy/RlsCoverageTest.php's DB-layer guard.
test('every tenant model uses the BelongsToSite trait', function (): void {
    // GLOBAL tables that legitimately carry a site_id column but are NOT tenant-scoped (the
    // app-layer mirror of RlsCoverageTest's documented exceptions): the RBAC authz pivots
    // scoped in-app by the spatie team key, the host-resolver table read before any tenant
    // context exists, and the append-only platform audit trail.
    $exemptTables = ['domains', 'roles', 'model_has_roles', 'model_has_permissions', 'audit_logs'];

    $offenders = [];

    // Blade views also live under app/ (app/Modules/Admin/resources/views); only files that
    // declare a class extending something are autoloaded, so no view or script is ever included.
    foreach (Finder::create()->files()->in(app_path())->name('*.php')->notName('*.blade.php') as $file) {
        if (preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+\w+\s+extends\s/m', $file->getContents()) !== 1) {
            continue;
        }

        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        /** @var class-string<Model> $class */
        if (new ReflectionClass($class)->isAbstract()) {
            continue;
        }

        $table = (new $class)->getTable();

        if (in_array($table, $exemptTables, true) || ! Schema::hasColumn($table, 'site_id')) {
            continue;
        }

        if (! in_array(BelongsToSite::class, class_uses_recursive($class), true)) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([]);
});

// 2) Every entry action must authorize through a Policy (RBAC). The slice is
//    Livewire-first (no controllers): the Entry model has a bound policy, and the
//    admin entry components authorize through it (the AuthorizesRequests arch rule
//    in ModuleBoundariesTest enforces the components actually call it).
test('every Entry action routes through EntryPolicy', function (): void {
    expect(Gate::getPolicyFor(Entry::class))->toBeInstanceOf(EntryPolicy::class);
});

// 3) Every workflow state transition must go through the workflow engine. The trail row IS the
//    transition's record, so keeping WorkflowLog engine-only means no module can move content
//    without passing the engine's guards (edge exists, origin matches, permission, note) — a
//    hand-rolled `WorkflowLog::create` elsewhere would be exactly the hard-coded transition
//    design.md §15.4 exists to prevent. Consumers call WorkflowEngine::apply() and persist the
//    state it returns onto their own subject.
arch('workflow transitions go through the engine')
    ->expect('App\\Modules\\Workflow\\Models\\WorkflowLog')
    ->toOnlyBeUsedIn('App\\Modules\\Workflow');
