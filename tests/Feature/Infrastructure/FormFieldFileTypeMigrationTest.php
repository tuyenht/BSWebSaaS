<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The `file` form field type («Tệp đính kèm»): the type CHECK lists it beside the six it had, the
 * migration goes down and up again cleanly (CI's own database only: no DDL on the shared dev
 * database), and it refuses to go down while a form still has a file field, changing nothing.
 */

beforeEach(function (): void {
    seedTenancy();
});

/** The types the form_fields type CHECK lists, as Postgres keeps it (validated). */
function fieldTypeCheck(): string
{
    return (string) DB::connection('pgsql_admin')->selectOne(
        "select pg_get_constraintdef(oid) as def from pg_constraint where conrelid = 'form_fields'::regclass and conname = 'form_fields_type_check' and convalidated",
    )?->def;
}

/** @return Migration the migration under test */
function fileTypeMigration(): Migration
{
    return require database_path('migrations/2090_01_01_000300_add_file_type_to_form_fields.php');
}

/** A form of site 1 with one field of each $types, written as the privileged role. */
function fileTypeForm(string $key, array $types): void
{
    $admin = DB::connection('pgsql_admin');
    $formId = $admin->table('forms')->insertGetId(['ulid' => (string) Str::ulid(), 'site_id' => 1, 'key' => $key, 'name' => '{"vi":"x"}', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    foreach ($types as $i => $type) {
        $admin->table('form_fields')->insert(['site_id' => 1, 'form_id' => $formId, 'key' => $type.'_'.$i, 'type' => $type, 'label' => '{"vi":"x"}', 'is_required' => false, 'options' => '{}', 'validation' => '{}', 'sort_order' => $i + 1, 'created_at' => now(), 'updated_at' => now()]);
    }
}

it('lets a form field be a file field, and still no unknown type', function (): void {
    fileTypeForm('bao-gia', ['text', 'email', 'tel', 'textarea', 'select', 'checkbox', 'file']);

    expect(fieldTypeCheck())->toContain("'file'")->toContain("'checkbox'")
        ->and(DB::connection('pgsql_admin')->table('form_fields')->where('type', 'file')->count())->toBe(1)
        ->and(fn () => fileTypeForm('khac', ['color']))->toThrow(QueryException::class, 'form_fields_type_check');
});

it('refuses to go down while a form has a file field, and changes nothing', function (): void {
    fileTypeForm('bao-gia', ['text', 'file', 'file']);
    $before = fieldTypeCheck();
    $migration = fileTypeMigration();

    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin'); // migrations run as the privileged role, which sees every site's fields
    try {
        expect(fn () => DB::transaction(fn () => $migration->down()))
            ->toThrow(RuntimeException::class, '2 form fields are file fields');
    } finally {
        DB::setDefaultConnection($original);
    }

    expect(fieldTypeCheck())->toBe($before)
        ->and(DB::connection('pgsql_admin')->table('form_fields')->where('type', 'file')->count())->toBe(2);
});

it('drops the type on the way down and lists it again on the way up, each twice', function (): void {
    $migration = fileTypeMigration();
    fileTypeForm('lien-he', ['text']);
    $admin = DB::connection('pgsql_admin');

    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        DB::transaction(fn () => $migration->down());
        DB::transaction(fn () => $migration->down());
        $down = fieldTypeCheck();
        $refused = rescue(fn () => $admin->transaction(fn () => $admin->table('form_fields')->update(['type' => 'file'])), 'refused', false);
        DB::transaction(fn () => $migration->up());
        DB::transaction(fn () => $migration->up());
    } finally {
        // whatever failed above, the type is listed again, and so are the types a later migration adds
        DB::transaction(fn () => $migration->up());
        $later = require database_path('migrations/2101_01_01_000000_widen_form_fields_type_check.php');
        DB::transaction(fn () => $later->up());
        DB::setDefaultConnection($original);
    }

    expect($down)->toContain("'text'")->toContain("'checkbox'")->not->toContain("'file'")
        ->and($refused)->toBe('refused')
        ->and(fieldTypeCheck())->toContain("'file'");
})->skip(getenv('CI') !== 'true', 'drops and re-adds a CHECK: runs on CI\'s own database only');
