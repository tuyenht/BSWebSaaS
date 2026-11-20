<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The `deleting` form status (a form whose leads are deleted in batches): the status CHECK lists it,
 * the migration goes down and up again cleanly (CI's own database only: no DDL on the shared dev
 * database), and it refuses to go down while a form is still being deleted, changing nothing.
 */

beforeEach(function (): void {
    seedTenancy();
});

/** The statuses the forms status CHECK lists, as Postgres keeps it. */
function formStatusCheck(): string
{
    return (string) DB::connection('pgsql_admin')->selectOne(
        "select pg_get_constraintdef(oid) as def from pg_constraint where conrelid = 'forms'::regclass and conname = 'forms_status_check' and convalidated",
    )?->def;
}

/** @return Migration the migration under test */
function deletingStatusMigration(): Migration
{
    return require database_path('migrations/2081_01_01_000100_add_deleting_status_to_forms.php');
}

it('lets a form be deleting', function (): void {
    expect(formStatusCheck())->toContain("'active'")->toContain("'disabled'")->toContain("'deleting'");
});

it('refuses to go down while a form is being deleted, and changes nothing', function (): void {
    $admin = DB::connection('pgsql_admin');
    foreach ([['bao-gia', 'deleting'], ['tu-van', 'deleting'], ['lien-he', 'active']] as [$key, $status]) {
        $admin->table('forms')->insert(['ulid' => (string) Str::ulid(), 'site_id' => 1, 'key' => $key, 'name' => '{"vi":"x"}', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }
    $before = formStatusCheck();
    $migration = deletingStatusMigration();

    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin'); // migrations run as the privileged role, which sees every site's forms
    try {
        expect(fn () => DB::transaction(fn () => $migration->down()))
            ->toThrow(RuntimeException::class, '2 forms are still being deleted');
    } finally {
        DB::setDefaultConnection($original);
    }

    expect(formStatusCheck())->toBe($before)
        ->and($admin->table('forms')->where('status', 'deleting')->count())->toBe(2);
});

it('drops the status on the way down and lists it again on the way up, each twice', function (): void {
    $migration = deletingStatusMigration();
    $admin = DB::connection('pgsql_admin');
    $admin->table('forms')->insert(['ulid' => (string) Str::ulid(), 'site_id' => 1, 'key' => 'lien-he', 'name' => '{"vi":"x"}', 'status' => 'disabled', 'created_at' => now(), 'updated_at' => now()]);

    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        DB::transaction(fn () => $migration->down());
        DB::transaction(fn () => $migration->down());
        $down = formStatusCheck();
        $refused = rescue(fn () => $admin->transaction(fn () => $admin->table('forms')->update(['status' => 'deleting'])), 'refused', false);
        DB::transaction(fn () => $migration->up());
        DB::transaction(fn () => $migration->up());
    } finally {
        // whatever failed above, the status is listed again
        DB::transaction(fn () => $migration->up());
        DB::setDefaultConnection($original);
    }

    expect($down)->toContain("'active'")->toContain("'disabled'")->not->toContain("'deleting'")
        ->and($refused)->toBe('refused')
        ->and(formStatusCheck())->toContain("'deleting'");
})->skip(getenv('CI') !== 'true', 'drops and re-adds a CHECK: runs on CI\'s own database only');
