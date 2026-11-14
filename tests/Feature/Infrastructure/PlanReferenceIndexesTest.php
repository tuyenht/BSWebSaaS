<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Deleting a plan (the "Hoàn tác" of a plan just added or copied) makes Postgres look up the rows
 * that point at it: tenants.plan_id (SET NULL), tenant_history.from_plan_id and to_plan_id
 * (RESTRICT). Each lookup has its own partial index led by the column, so a delete never scans
 * those tables whole. The definitions are pinned here, the planner is shown taking them for the
 * lookups the delete runs, and the migration goes down and up again cleanly (CI's own database
 * only: dropping indexes on the shared dev database is not allowed).
 */

const PLAN_REFERENCE_INDEXES = [
    'tenant_history_from_plan_id_index' => 'CREATE INDEX tenant_history_from_plan_id_index ON public.tenant_history USING btree (from_plan_id) WHERE (from_plan_id IS NOT NULL)',
    'tenant_history_to_plan_id_index' => 'CREATE INDEX tenant_history_to_plan_id_index ON public.tenant_history USING btree (to_plan_id) WHERE (to_plan_id IS NOT NULL)',
    'tenants_plan_id_index' => 'CREATE INDEX tenants_plan_id_index ON public.tenants USING btree (plan_id) WHERE (plan_id IS NOT NULL)',
];

/** @return array<string, string> index name => definition, of the plan reference indexes that are valid */
function planReferenceIndexes(): array
{
    $rows = DB::connection('pgsql_admin')->select(
        'select ix.indexname, ix.indexdef from pg_indexes ix
         join pg_index i on i.indexrelid = to_regclass(ix.schemaname || \'.\' || ix.indexname)
         where ix.schemaname = current_schema() and i.indisvalid and ix.indexname in (?, ?, ?)
         order by ix.indexname',
        array_keys(PLAN_REFERENCE_INDEXES),
    );

    $found = [];
    foreach ($rows as $row) {
        $found[(string) $row->indexname] = (string) $row->indexdef;
    }

    return $found;
}

it('gives each key pointing at plans a partial index led by its column', function (): void {
    expect(planReferenceIndexes())->toBe(PLAN_REFERENCE_INDEXES);
});

it('serves the lookups a plan delete runs with those indexes', function (string $lookup, string $index): void {
    $plan = DB::connection('pgsql_admin')->transaction(function () use ($lookup): string {
        // a handful of test rows would make a sequential scan cheapest; the point is that the
        // index can serve the lookup, as it will on a table of real size
        DB::connection('pgsql_admin')->statement('SET LOCAL enable_seqscan = off');

        return implode("\n", array_map(
            fn (object $row): string => (string) $row->{'QUERY PLAN'},
            DB::connection('pgsql_admin')->select('EXPLAIN '.$lookup),
        ));
    });

    expect($plan)->toContain($index)->not->toContain('Seq Scan');
})->with([
    // what ON DELETE SET NULL / RESTRICT run (RI_FKey_setnull_del, ri_restrict)
    'tenants.plan_id' => ['UPDATE ONLY tenants SET plan_id = NULL WHERE plan_id = 1', 'tenants_plan_id_index'],
    'tenant_history.from_plan_id' => ['SELECT 1 FROM ONLY tenant_history x WHERE from_plan_id = 1 FOR KEY SHARE OF x', 'tenant_history_from_plan_id_index'],
    'tenant_history.to_plan_id' => ['SELECT 1 FROM ONLY tenant_history x WHERE to_plan_id = 1 FOR KEY SHARE OF x', 'tenant_history_to_plan_id_index'],
]);

it('drops the indexes on the way down and builds them again on the way up, each twice', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2059_01_01_000000_add_plan_reference_indexes.php');

    // CONCURRENTLY needs the privileged connection and no open transaction
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $migration->down();
        $migration->down();
        $dropped = planReferenceIndexes();
        $migration->up();
        $migration->up();
    } finally {
        // whatever failed above, the indexes are left in place
        $migration->up();
        DB::setDefaultConnection($original);
    }

    expect($dropped)->toBe([])
        ->and(planReferenceIndexes())->toBe(PLAN_REFERENCE_INDEXES);
})->skip(getenv('CI') !== 'true', 'drops and re-adds indexes: runs on CI\'s own database only');
