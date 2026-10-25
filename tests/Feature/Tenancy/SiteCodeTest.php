<?php

use App\Modules\Tenancy\Exceptions\SiteCodeIsPermanentException;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Every website carries a permanent 8-character code (`sites.code`) that prefixes its media storage
 * keys. Two websites with the same name used to share a key prefix (the slug of the name) and could
 * overwrite or delete each other's images; the code is issued by the database, is never reused, and
 * the application role cannot change it.
 */

const SITE_CODE_PATTERN = '/^[0-9a-hjkmnp-tv-z]{8}$/';

beforeEach(fn () => seedTenancy());

/** The SQLSTATE of the query that $run makes fail ('' when it does not fail). */
function sqlStateOf(Closure $run): string
{
    try {
        $run();
    } catch (QueryException $e) {
        return (string) ($e->errorInfo[0] ?? '');
    }

    return '';
}

/** The code the generator draws first after seeding the session's random sequence. */
function codeAfterSeed(Connection $connection, float $seed): string
{
    $connection->statement('select setseed(?)', [$seed]);

    return (string) $connection->scalar('select generate_site_code()');
}

it('gives every site, raw insert or model, an 8-character code from the media alphabet', function (): void {
    $viaModel = Site::query()->create(['ulid' => (string) Str::ulid(), 'tenant_id' => 1, 'name' => 'Công ty C', 'status' => 'active']);

    expect(siteCode(1))->toMatch(SITE_CODE_PATTERN)
        ->and(siteCode(2))->toMatch(SITE_CODE_PATTERN)
        ->and(siteCode(1))->not->toBe(siteCode(2))
        ->and($viaModel->code)->toMatch(SITE_CODE_PATTERN)
        ->and(siteCode($viaModel->id))->toBe($viaModel->code);
});

it('refuses a code outside the alphabet or of the wrong length', function (string $code, string $state): void {
    $insert = fn () => DB::connection('pgsql_admin')->table('sites')->insert([
        'ulid' => (string) Str::ulid(), 'tenant_id' => 1, 'name' => 'X', 'status' => 'active', 'code' => $code,
    ]);

    expect(sqlStateOf($insert))->toBe($state);
})->with([
    'upper case' => ['ABCDEFGH', '23514'],       // check_violation
    'letter i' => ['abcdefgi', '23514'],
    'letter o' => ['abcdefgo', '23514'],
    'seven chars' => ['abcdefg', '23514'],
    'nine chars' => ['abcdefghj', '22001'],      // longer than varchar(8)
]);

it('never issues a soft-deleted site\'s code again', function (): void {
    $admin = DB::connection('pgsql_admin');
    $admin->table('sites')->where('id', 2)->update(['deleted_at' => now()]);

    $reuse = fn () => $admin->table('sites')->insert([
        'ulid' => (string) Str::ulid(), 'tenant_id' => 1, 'name' => 'New', 'status' => 'active', 'code' => siteCode(2),
    ]);

    expect(sqlStateOf($reuse))->toBe('23505');   // unique_violation: the index covers deleted rows
});

it('skips a candidate held by a soft-deleted site', function (): void {
    $admin = DB::connection('pgsql_admin');

    [$first, $next] = $admin->transaction(function () use ($admin): array {
        $first = codeAfterSeed($admin, 0.25);
        $admin->table('sites')->where('id', 2)->update(['code' => $first, 'deleted_at' => now()]);

        return [$first, codeAfterSeed($admin, 0.25)];
    });

    expect($next)->toMatch(SITE_CODE_PATTERN)->not->toBe($first);
});

it('never issues a code that a storage key from before site codes starts with', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2036_01_01_000000_add_code_to_sites.php');
    $admin = DB::connection('pgsql_admin');
    $first = codeAfterSeed($admin, 0.25);

    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $migration->down();
        $admin->table('media')->insert([
            'site_id' => 1, 'ulid' => (string) Str::ulid(), 'collection' => 'library', 'hash' => str_repeat('a', 64),
            'storage_key' => "{$first}/library/anh.png", 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration->up();   // freezes the segment into the generator; media is never scanned again
        $next = codeAfterSeed($admin, 0.25);
    } finally {
        $admin->table('media')->where('storage_key', "{$first}/library/anh.png")->delete();
        $migration->down();
        $migration->up();
        DB::setDefaultConnection($original);
    }

    expect($next)->toMatch(SITE_CODE_PATTERN)->not->toBe($first);
});

it('refuses a code chosen by the caller when a site is created through the model', function (): void {
    $create = fn () => Site::query()->create([
        'ulid' => (string) Str::ulid(), 'tenant_id' => 1, 'name' => 'Công ty D', 'status' => 'active', 'code' => 'abcdefgh',
    ]);

    expect($create)->toThrow(SiteCodeIsPermanentException::class)
        ->and(DB::connection('pgsql_admin')->table('sites')->where('name', 'Công ty D')->exists())->toBeFalse();
});

it('does not let the application role change a code, while other columns stay writable', function (): void {
    $before = siteCode(1);

    expect(sqlStateOf(fn () => DB::table('sites')->where('id', 1)->update(['code' => 'zzzzzzzz'])))->toBe('42501')
        ->and(sqlStateOf(fn () => DB::table('sites')->where('id', 1)->update(['name' => 'A đổi tên'])))->toBe('')
        ->and(siteCode(1))->toBe($before);
});

it('grants the application role UPDATE on every sites column except code', function (): void {
    $columns = DB::connection('pgsql_admin')->table('information_schema.columns')
        ->where('table_schema', 'public')->where('table_name', 'sites')->pluck('column_name');

    $updatable = $columns->mapWithKeys(fn (string $column): array => [
        $column => (bool) DB::connection('pgsql_admin')->scalar("select has_column_privilege('app_user', 'sites', ?, 'UPDATE')", [$column]),
    ])->all();

    // A column added to sites later needs its own GRANT UPDATE (the table-level grant is gone).
    expect($updatable)->toHaveKey('code')
        ->and(array_keys(array_filter($updatable, fn (bool $can): bool => ! $can)))->toBe(['code']);
});

it('refuses a code change through the model before it reaches the database', function (): void {
    $site = Site::query()->findOrFail(1);
    $site->setAttribute('code', 'zzzzzzzz');

    expect(fn () => $site->save())->toThrow(SiteCodeIsPermanentException::class);

    $site->refresh();
    $site->name = 'Tên mới';
    $site->save();

    expect($site->fresh()?->name)->toBe('Tên mới');
});

it('backfills codes for sites that predate the column, and re-runs cleanly', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2036_01_01_000000_add_code_to_sites.php');
    $admin = DB::connection('pgsql_admin');

    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $migration->down();
        $admin->table('sites')->insert([
            'ulid' => (string) Str::ulid(), 'tenant_id' => 1, 'name' => 'Old', 'status' => 'active', 'deleted_at' => now(),
        ]);
        $migration->up();
        $codes = $admin->table('sites')->orderBy('id')->pluck('code')->all();
        $migration->up();   // re-runnable: nothing left to backfill, nothing changes
        $again = $admin->table('sites')->orderBy('id')->pluck('code')->all();
    } finally {
        DB::setDefaultConnection($original);
    }

    expect($codes)->toHaveCount(3)
        ->and(array_filter($codes, fn (mixed $code): bool => is_string($code) && preg_match(SITE_CODE_PATTERN, $code) === 1))->toHaveCount(3)
        ->and(array_unique($codes))->toHaveCount(3)
        ->and($again)->toBe($codes);
});
