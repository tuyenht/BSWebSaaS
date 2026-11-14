<?php

use App\Modules\Identity\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The migration of the member's job title and phone on users. up() adds nullable varchars with no
 * default the app role may write, a validated CHECK that holds the phone to one international form,
 * and runs twice; down() never drops what a member typed: while a row holds one it refuses, naming
 * the count, and drops nothing.
 */

/**
 * The two columns as the catalog describes them, read on the privileged connection.
 *
 * @return list<object{column_name: string, data_type: string, character_maximum_length: int|null, is_nullable: string, column_default: string|null}>
 */
function profileFieldColumns(): array
{
    /** @var list<object{column_name: string, data_type: string, character_maximum_length: int|null, is_nullable: string, column_default: string|null}> */
    return DB::connection('pgsql_admin')->select(
        "select column_name, data_type, character_maximum_length, is_nullable, column_default from information_schema.columns
         where table_schema = current_schema() and table_name = 'users' and column_name in ('job_title', 'phone')
         order by column_name"
    );
}

function profileFieldsMigration(): Migration
{
    return require database_path('migrations/2064_01_01_000000_add_job_title_and_phone_to_users.php');
}

/** Runs $step on the privileged connection, as migrations run. */
function asProfileFieldsMigrator(Closure $step): mixed
{
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        return $step();
    } finally {
        DB::setDefaultConnection($original);
    }
}

it('adds both fields as nullable varchars with no default that the app may write, and a validated phone check', function (): void {
    $columns = profileFieldColumns();

    expect(array_map(fn (object $column): array => [$column->column_name, $column->data_type, $column->character_maximum_length, $column->is_nullable, $column->column_default], $columns))
        ->toBe([
            ['job_title', 'character varying', 80, 'YES', null],
            ['phone', 'character varying', 16, 'YES', null],
        ]);

    $granted = DB::connection('pgsql_admin')->selectOne(
        "select has_column_privilege('app_user', 'users', 'job_title', 'UPDATE') as titles,
                has_column_privilege('app_user', 'users', 'phone', 'UPDATE') as phones"
    );
    expect($granted?->titles)->toBeTrue()->and($granted?->phones)->toBeTrue();

    $check = DB::connection('pgsql_admin')->selectOne(
        "select convalidated, pg_get_constraintdef(oid) as def from pg_constraint where conname = 'users_phone_e164_check' and conrelid = 'users'::regclass"
    );
    expect($check?->convalidated)->toBeTrue()
        ->and($check?->def)->toBe("CHECK (((phone)::text ~ '^\\+[1-9][0-9]{7,14}$'::text))");

    // No index: nothing filters or sorts on either.
    $indexed = DB::connection('pgsql_admin')->selectOne(
        "select count(*) as n from pg_indexes where tablename = 'users' and (indexdef like '%job_title%' or indexdef like '%phone%')"
    );
    expect((int) $indexed?->n)->toBe(0);

    // A second up() on a migrated database changes nothing.
    asProfileFieldsMigrator(fn () => profileFieldsMigration()->up());
    expect(profileFieldColumns())->toEqual($columns);
});

it('refuses a phone in any other form, whoever writes it', function (string $phone): void {
    $user = User::factory()->create();

    expect(fn () => DB::connection('pgsql_admin')->table('users')->where('id', $user->id)->update(['phone' => $phone]))
        ->toThrow(QueryException::class, 'users_phone_e164_check');
})->with(['0901234567', '+0901234567', '+84 901234567', '+8490', '84901234567']);

it('refuses to roll back while a job title or a phone is set, and drops nothing', function (string $column, string $value): void {
    $user = User::factory()->create();
    DB::connection('pgsql_admin')->table('users')->update(['job_title' => null, 'phone' => null]);
    DB::connection('pgsql_admin')->table('users')->where('id', $user->id)->update([$column => $value]);

    try {
        expect(fn () => asProfileFieldsMigrator(fn () => profileFieldsMigration()->down()))
            ->toThrow(RuntimeException::class, "Refused: 1 row(s) of users.{$column} still hold what a member typed.")
            ->and(profileFieldColumns())->toHaveCount(2);
    } finally {
        DB::connection('pgsql_admin')->table('users')->where('id', $user->id)->update([$column => null]);
    }
})->with([
    'job title' => ['job_title', 'Kế toán'],
    'phone' => ['phone', '+84901234567'],
]);

it('drops both columns and the check when nothing is set, and up() puts them back', function (): void {
    DB::connection('pgsql_admin')->table('users')->update(['job_title' => null, 'phone' => null]);
    $migration = profileFieldsMigration();

    try {
        asProfileFieldsMigrator(function () use ($migration): void {
            $migration->down();
            $migration->down();
        });
        $dropped = profileFieldColumns();
        $check = DB::connection('pgsql_admin')->selectOne("select count(*) as n from pg_constraint where conname = 'users_phone_e164_check'");
    } finally {
        // Whatever failed above, the schema is left as it was found.
        asProfileFieldsMigrator(fn () => $migration->up());
    }

    expect($dropped)->toBe([])->and((int) $check?->n)->toBe(0)->and(profileFieldColumns())->toHaveCount(2);
})->skip(getenv('CI') !== 'true', 'drops and re-adds columns: runs on CI\'s own database only');
