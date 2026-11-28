<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Four more form field types («Số», «Ngày», «Chọn một», «Chọn nhiều»): the type CHECK lists them
 * beside the seven it had and still refuses any other word, the migration goes down and up again
 * cleanly (CI's own database only: no DDL on the shared dev database), and it refuses to go down
 * while a form still has a field of one of them, changing nothing.
 */

beforeEach(function (): void {
    seedTenancy();
});

/** The form_fields type CHECK as Postgres keeps it, when it is validated. */
function widenedTypeCheck(): string
{
    return (string) DB::connection('pgsql_admin')->selectOne(
        "select pg_get_constraintdef(oid) as def from pg_constraint where conrelid = 'form_fields'::regclass and conname = 'form_fields_type_check' and convalidated",
    )?->def;
}

/** @return Migration the migration under test */
function widenTypesMigration(): Migration
{
    return require database_path('migrations/2101_01_01_000000_widen_form_fields_type_check.php');
}

/** A form of site 1 with one field of each $types, written as the privileged role. */
function widenedTypesForm(string $key, array $types): void
{
    $admin = DB::connection('pgsql_admin');
    $formId = $admin->table('forms')->insertGetId(['ulid' => (string) Str::ulid(), 'site_id' => 1, 'key' => $key, 'name' => '{"vi":"x"}', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    foreach ($types as $i => $type) {
        $admin->table('form_fields')->insert(['site_id' => 1, 'form_id' => $formId, 'key' => $type.'_'.$i, 'type' => $type, 'label' => '{"vi":"x"}', 'is_required' => false, 'options' => '{}', 'validation' => '{}', 'sort_order' => $i + 1, 'created_at' => now(), 'updated_at' => now()]);
    }
}

it('lets a form field be a number, a date, one choice or several, and still no unknown type', function (): void {
    widenedTypesForm('dat-hen', ['text', 'number', 'date', 'radio', 'checkboxes', 'select', 'checkbox', 'file']);

    expect(widenedTypeCheck())->toContain("'number'")->toContain("'date'")->toContain("'radio'")->toContain("'checkboxes'")->toContain("'file'")
        ->and(DB::connection('pgsql_admin')->table('form_fields')->whereIn('type', ['number', 'date', 'radio', 'checkboxes'])->count())->toBe(4)
        ->and(fn () => widenedTypesForm('khac', ['color']))->toThrow(QueryException::class, 'form_fields_type_check');
});

it('refuses to go down while a form has a field of an added type, and changes nothing', function (): void {
    widenedTypesForm('dat-hen', ['text', 'radio', 'date', 'checkboxes']);
    $before = widenedTypeCheck();
    $migration = widenTypesMigration();

    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin'); // migrations run as the privileged role, which sees every site's fields
    try {
        expect(fn () => DB::transaction(fn () => $migration->down()))
            ->toThrow(RuntimeException::class, '3 form fields are number, date, one-choice or several-choice fields');
    } finally {
        DB::setDefaultConnection($original);
    }

    expect(widenedTypeCheck())->toBe($before)
        ->and(DB::connection('pgsql_admin')->table('form_fields')->where('type', 'radio')->count())->toBe(1);
});

it('drops the added types on the way down and lists them again on the way up, each twice', function (): void {
    $migration = widenTypesMigration();
    widenedTypesForm('lien-he', ['text', 'file']);
    $admin = DB::connection('pgsql_admin');

    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        DB::transaction(fn () => $migration->down());
        DB::transaction(fn () => $migration->down());
        $down = widenedTypeCheck();
        $refused = rescue(fn () => $admin->transaction(fn () => $admin->table('form_fields')->where('type', 'text')->update(['type' => 'radio'])), 'refused', false);
        DB::transaction(fn () => $migration->up());
        DB::transaction(fn () => $migration->up());
    } finally {
        // whatever failed above, the types are listed again
        DB::transaction(fn () => $migration->up());
        DB::setDefaultConnection($original);
    }

    expect($down)->toContain("'file'")->toContain("'checkbox'")->not->toContain("'radio'")->not->toContain("'number'")
        ->and($refused)->toBe('refused')
        ->and(widenedTypeCheck())->toContain("'radio'")->toContain("'checkboxes'")->toContain("'date'")->toContain("'number'");
})->skip(getenv('CI') !== 'true', 'drops and re-adds a CHECK: runs on CI\'s own database only');
