<?php

use Illuminate\Support\Facades\DB;

/*
 * The two migrations that give posts, pages and products an end time. up(): a nullable expires_at
 * (timestamptz) and a CHECK that it is after published_at (added NOT VALID, validated by the second
 * file), then a partial index per table built CONCURRENTLY for the every-minute sweep. down() drops
 * the indexes, then the CHECK and the column; up() runs again on top of it, and twice in a row, as
 * after a half-finished deploy.
 */

beforeEach(function (): void {
    seedTenancy();
});

function expiresAtMigShape(): array
{
    $db = DB::connection('pgsql_admin');
    $shape = [];
    foreach (['entries', 'products'] as $table) {
        $shape[$table] = [
            'column' => $db->selectOne("select data_type, is_nullable, column_default from information_schema.columns where table_name = ? and column_name = 'expires_at'", [$table]),
            'check' => $db->selectOne('select convalidated, pg_get_constraintdef(oid) as def from pg_constraint where conrelid = ?::regclass and conname = ?', [$table, $table.'_expires_after_publish']),
            'index' => $db->selectOne('select indexdef from pg_indexes where tablename = ? and indexname = ?', [$table, $table.'_expiry_due_idx'])?->indexdef,
            'invalid' => $db->selectOne('select count(*) as n from pg_index i join pg_class c on c.oid = i.indrelid where c.relname = ? and not i.indisvalid', [$table])->n,
        ];
    }

    return $shape;
}

it('rolls the end time back and forward on a real database', function (): void {
    $column = require base_path('database/migrations/2095_01_01_000000_add_expires_at_to_entries_and_products.php');
    $indexes = require base_path('database/migrations/2095_01_01_000100_add_expiry_due_indexes.php');
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');

    try {
        $after = expiresAtMigShape();
        foreach (['entries', 'products'] as $table) {
            expect($after[$table]['column']?->data_type)->toBe('timestamp with time zone')
                ->and($after[$table]['column']?->is_nullable)->toBe('YES')
                ->and($after[$table]['column']?->column_default)->toBeNull()
                ->and($after[$table]['check']?->convalidated)->toBeTrue()
                ->and($after[$table]['check']?->def)->toContain('expires_at > published_at')
                ->and($after[$table]['index'])->toContain('(site_id, expires_at)')
                ->and($after[$table]['index'])->toContain("status)::text = 'published'::text")
                ->and($after[$table]['index'])->toContain('deleted_at IS NULL')
                ->and($after[$table]['invalid'])->toBe(0);
        }

        $indexes->down();
        $indexes->down();
        $column->down();
        $column->down();
        foreach (expiresAtMigShape() as $table) {
            expect($table['column'])->toBeNull()->and($table['check'])->toBeNull()->and($table['index'])->toBeNull();
        }

        $column->up();
        $column->up();   // re-run, as after a half-finished deploy
        $indexes->up();
        $indexes->up();
        expect(expiresAtMigShape())->toEqual($after);
    } finally {
        // never leave the shared test database without the end time, whatever failed above
        $column->up();
        $indexes->up();
        DB::setDefaultConnection($previous);
    }
});
