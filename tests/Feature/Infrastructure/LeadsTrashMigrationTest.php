<?php

use Illuminate\Support\Facades\DB;

/*
 * The two migrations that give the leads of «Khách liên hệ» a Trash. up(): a nullable deleted_at
 * (timestamptz), then three partial indexes built CONCURRENTLY, the 2055 ones kept. down() drops
 * the indexes, then the column (a lead still in the Trash simply comes back into its group); up()
 * runs again on top of it, and twice in a row, as after a half-finished deploy.
 */

beforeEach(function (): void {
    seedTenancy();
});

function leadsTrashMigShape(): array
{
    $db = DB::connection('pgsql_admin');

    return [
        'column' => $db->selectOne("select data_type, is_nullable, column_default from information_schema.columns where table_name = 'form_submissions' and column_name = 'deleted_at'"),
        'indexes' => collect($db->select("select indexname, indexdef from pg_indexes where tablename = 'form_submissions' order by indexname"))->mapWithKeys(fn ($r): array => [$r->indexname => $r->indexdef])->all(),
        'invalid' => $db->selectOne("select count(*) as n from pg_index i join pg_class c on c.oid = i.indrelid where c.relname = 'form_submissions' and not i.indisvalid")->n,
    ];
}

it('rolls the leads Trash back and forward on a real database', function (): void {
    $column = require base_path('database/migrations/2089_01_01_000000_add_trash_to_form_submissions.php');
    $indexes = require base_path('database/migrations/2089_01_01_000100_add_trash_aware_lead_indexes.php');
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    $new = ['form_submissions_kept_created_idx', 'form_submissions_kept_status_created_idx', 'form_submissions_trash_idx'];
    $old = ['form_submissions_live_created_idx', 'form_submissions_status_created_idx'];

    try {
        $after = leadsTrashMigShape();
        expect($after['column']?->data_type)->toBe('timestamp with time zone')
            ->and($after['column']?->is_nullable)->toBe('YES')
            ->and($after['column']?->column_default)->toBeNull()
            ->and($after['indexes'])->toHaveKeys([...$new, ...$old])
            ->and($after['indexes']['form_submissions_trash_idx'])->toContain('WHERE (deleted_at IS NOT NULL)')
            ->and($after['invalid'])->toBe(0);

        // the indexes go first (they name the column), then the column; twice is harmless
        $indexes->down();
        $indexes->down();
        DB::transaction(fn () => $column->down());
        DB::transaction(fn () => $column->down());
        $before = leadsTrashMigShape();
        expect($before['column'])->toBeNull()
            ->and($before['indexes'])->not->toHaveKeys($new)
            ->and($before['indexes'])->toHaveKeys($old)
            ->and($before['invalid'])->toBe(0);

        DB::transaction(fn () => $column->up());
        DB::transaction(fn () => $column->up());   // re-run, as after a half-finished deploy
        $indexes->up();
        $indexes->up();
        expect(leadsTrashMigShape())->toEqual($after);
    } finally {
        // never leave the shared test database without the Trash, whatever failed above
        DB::transaction(fn () => $column->up());
        $indexes->up();
        DB::setDefaultConnection($previous);
    }
});
