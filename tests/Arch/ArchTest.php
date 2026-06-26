<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Policies\EntryPolicy;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/*
 * Architecture invariants (CLAUDE.md §6).
 * Placeholders for now — each is promoted from ->todo() to a real arch
 * expectation as the relevant layer lands. They MUST be enforced before
 * the feature that needs them ships.
 */

// 1) Every Eloquent model whose table carries a site_id column IS a tenant model and MUST use
//    the BelongsToSite trait (the RLS global scope at the app layer). Auto-discovered from
//    app/Modules so a new tenant model that forgets the trait fails CI here — the code-layer
//    twin of tests/Feature/Tenancy/RlsCoverageTest.php's DB-layer guard.
test('every tenant model uses the BelongsToSite trait', function () {
    // GLOBAL tables that legitimately carry a site_id column but are NOT tenant-scoped (the
    // app-layer mirror of RlsCoverageTest's documented exceptions): the RBAC authz pivots
    // scoped in-app by the spatie team key, the host-resolver table read before any tenant
    // context exists, and the append-only platform audit trail.
    $exemptTables = ['domains', 'roles', 'model_has_roles', 'model_has_permissions', 'audit_logs'];

    $offenders = [];

    foreach (glob(app_path('Modules/*/Models/*.php')) ?: [] as $file) {
        $class = sprintf(
            'App\\Modules\\%s\\Models\\%s',
            basename(dirname($file, 2)),
            basename($file, '.php'),
        );

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        /** @var class-string<Model> $class */
        if ((new ReflectionClass($class))->isAbstract()) {
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
test('every Entry action routes through EntryPolicy', function () {
    expect(Gate::getPolicyFor(Entry::class))->toBeInstanceOf(EntryPolicy::class);
});

// 3) Every workflow state transition must go through the workflow engine.
test('workflow transitions go through the engine')
    ->todo('Enforce once the workflow engine exists.');
