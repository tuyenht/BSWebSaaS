<?php

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * Local runs reach PostgreSQL through PgBouncer in transaction mode, which keeps each prepared statement
 * on its pooled server connection and hands it to any later client that prepares the same text. After a
 * test changes a table's columns (a migration's down() and up()), a `select *` prepared before the change
 * answers "cached plan must not change result type" (SQLSTATE 0A000) in every later test, until that
 * server connection closes. The suite's TestCase closes the pooler's idle server connections after any
 * test that changed a column, but only on a private scratch stack (plans/tools/pest-scratch.sh): a plain
 * run on the shared dev database must not close that pool, so these skip there. CI talks to PostgreSQL
 * directly, where a statement dies with its connection, so these pass there either way; they guard the
 * scratch runs.
 */

it('changes a table\'s columns after a pooled `select *` read it', function (): void {
    if (TestCase::poolerWithoutStatementCleanup()) {
        $this->markTestSkipped('needs a private scratch stack: plans/tools/pest-scratch.sh');
    }

    $admin = DB::connection('pgsql_admin');
    $admin->statement('drop table if exists pooled_statement_probes');
    $admin->statement('create table pooled_statement_probes (id bigint primary key, label text not null)');
    $admin->statement("insert into pooled_statement_probes values (1, 'before')");
    $admin->statement('grant select on pooled_statement_probes to '.DB::connection()->getConfig('username'));

    $rows = DB::select('select * from pooled_statement_probes where id = ?', [1]);

    $admin->statement('alter table pooled_statement_probes add column note text');

    expect($rows)->toHaveCount(1)
        ->and(array_keys((array) $rows[0]))->toBe(['id', 'label']);
});

it('reads that table with the same `select *` in the next test, new columns included', function (): void {
    if (TestCase::poolerWithoutStatementCleanup()) {
        $this->markTestSkipped('needs a private scratch stack: plans/tools/pest-scratch.sh');
    }

    try {
        $rows = DB::select('select * from pooled_statement_probes where id = ?', [1]);

        expect($rows)->toHaveCount(1)
            ->and(array_keys((array) $rows[0]))->toBe(['id', 'label', 'note']);
    } finally {
        DB::connection('pgsql_admin')->statement('drop table if exists pooled_statement_probes');
    }
})->depends('it changes a table\'s columns after a pooled `select *` read it');
