<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Deleting a form (DeleteForm) makes Postgres delete its leads with `WHERE form_id = $1`
 * (ON DELETE CASCADE), a lookup with no site_id. The index led by form_id serves it: its
 * definition is pinned here, the planner is shown taking it, and the migration goes down and up
 * again cleanly (CI's own database only: dropping indexes on the shared dev database is not allowed).
 */

const FORM_ID_INDEX = 'form_submissions_form_id_index';

const FORM_ID_INDEX_DEF = 'CREATE INDEX form_submissions_form_id_index ON public.form_submissions USING btree (form_id)';

/** The definition of the form_id index when it exists and is valid, else null. */
function formIdIndex(): ?string
{
    $row = DB::connection('pgsql_admin')->selectOne(
        'select ix.indexdef from pg_indexes ix
         join pg_index i on i.indexrelid = to_regclass(ix.schemaname || \'.\' || ix.indexname)
         where ix.schemaname = current_schema() and i.indisvalid and ix.indexname = ?',
        [FORM_ID_INDEX],
    );

    return $row === null ? null : (string) $row->indexdef;
}

it('gives form_submissions.form_id an index led by the column', function (): void {
    expect(formIdIndex())->toBe(FORM_ID_INDEX_DEF);
});

it('serves the lookup a form delete runs with that index', function (): void {
    $plan = DB::connection('pgsql_admin')->transaction(function (): string {
        // a handful of test rows would make a sequential scan cheapest; the point is that the
        // index can serve the lookup, as it will on a table of real size
        DB::connection('pgsql_admin')->statement('SET LOCAL enable_seqscan = off');

        return implode("\n", array_map(
            fn (object $row): string => (string) $row->{'QUERY PLAN'},
            // what ON DELETE CASCADE runs (RI_FKey_cascade_del)
            DB::connection('pgsql_admin')->select('EXPLAIN DELETE FROM ONLY form_submissions WHERE form_id = 1'),
        ));
    });

    expect($plan)->toContain(FORM_ID_INDEX)->not->toContain('Seq Scan');
});

it('drops the index on the way down and builds it again on the way up, each twice', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2081_01_01_000000_add_form_id_index_to_form_submissions.php');

    // CONCURRENTLY needs the privileged connection and no open transaction
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $migration->down();
        $migration->down();
        $dropped = formIdIndex();
        $migration->up();
        $migration->up();
    } finally {
        // whatever failed above, the index is left in place
        $migration->up();
        DB::setDefaultConnection($original);
    }

    expect($dropped)->toBeNull()
        ->and(formIdIndex())->toBe(FORM_ID_INDEX_DEF);
})->skip(getenv('CI') !== 'true', 'drops and re-adds an index: runs on CI\'s own database only');
