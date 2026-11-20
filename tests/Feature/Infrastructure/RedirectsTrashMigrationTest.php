<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The two migrations that give redirects a Trash, a source and links to products and categories.
 * up(): the columns with a validated CHECK and SET NULL foreign keys, every row keyed (ulid) and
 * the rows RouteSync wrote marked auto; one LIVE row per (site, language, old address) through a
 * partial unique index, the old full unique constraint gone, and an index led by each new foreign
 * key. down() undoes both in reverse, deleting only the rows in the Trash (the full unique could not
 * hold them), and up() runs again on top of it.
 */

beforeEach(function (): void {
    seedTenancy();
    seedHomepage(1);
});

/** @return Migration the migration file, fresh */
function rtmMigration(string $file): Migration
{
    return require database_path('migrations/'.$file.'.php');
}

/** Runs $step on the privileged connection, as migrations run. */
function rtmAsMigrator(Closure $step): mixed
{
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        return $step();
    } finally {
        DB::setDefaultConnection($original);
    }
}

/** @return array{columns: list<string>, indexes: list<string>, constraints: list<string>} */
function rtmShape(): array
{
    $db = DB::connection('pgsql_admin');

    return [
        'columns' => $db->table('information_schema.columns')->where('table_schema', DB::raw('current_schema()'))
            ->where('table_name', 'redirects')->whereIn('column_name', ['deleted_at', 'source', 'product_id', 'taxonomy_id', 'ulid'])
            ->orderBy('column_name')->pluck('column_name')->all(),
        'indexes' => $db->table('pg_indexes')->where('tablename', 'redirects')->where('indexname', 'like', 'redirects_%')
            ->orderBy('indexname')->pluck('indexname')->all(),
        'constraints' => array_map(fn (object $r): string => (string) $r->conname, $db->select(
            "select conname from pg_constraint where conrelid = 'redirects'::regclass and contype in ('u', 'c', 'f') order by conname"
        )),
    ];
}

it('adds the trash, the source and the links, and one live row per old address', function (): void {
    $shape = rtmShape();

    expect($shape['columns'])->toBe(['deleted_at', 'product_id', 'source', 'taxonomy_id', 'ulid'])
        ->and($shape['indexes'])->toContain('redirects_site_id_locale_from_path_live_unique', 'redirects_site_id_ulid_unique', 'redirects_product_id_index', 'redirects_taxonomy_id_index')
        ->and($shape['indexes'])->not->toContain('redirects_site_id_locale_from_path_unique')
        ->and($shape['constraints'])->toContain('redirects_source_check', 'redirects_product_id_foreign', 'redirects_taxonomy_id_foreign')
        ->and($shape['constraints'])->not->toContain('redirects_site_id_locale_from_path_unique');

    $valid = DB::connection('pgsql_admin')->select(
        "select conname, convalidated from pg_constraint where conrelid = 'redirects'::regclass and conname in ('redirects_source_check', 'redirects_product_id_foreign', 'redirects_taxonomy_id_foreign')"
    );
    expect(collect($valid)->every(fn (object $c): bool => $c->convalidated === true))->toBeTrue();

    $def = DB::connection('pgsql_admin')->table('pg_indexes')->where('indexname', 'redirects_site_id_locale_from_path_live_unique')->value('indexdef');
    expect((string) $def)->toContain('UNIQUE')->toContain('(site_id, locale, from_path)')->toContain('WHERE (deleted_at IS NULL)');

    // the CHECK holds the source to the two values
    $insert = fn (string $source) => DB::connection('pgsql_admin')->table('redirects')->insert([
        'site_id' => 1, 'locale' => 'vi', 'from_path' => '/x-'.$source, 'to_path' => '/y', 'status_code' => 301, 'source' => $source,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $insert('auto');
    expect(fn () => $insert('robot'))->toThrow(QueryException::class);
});

it('rolls back to the old shape, dropping only the rows in the Trash, and migrates again', function (): void {
    $entry = (int) DB::connection('pgsql_admin')->table('entries')->where('site_id', 1)->value('id');
    $db = DB::connection('pgsql_admin');
    $row = fn (string $from, string $to, ?string $trashed, ?int $entryId = null) => $db->table('redirects')->insert([
        'site_id' => 1, 'locale' => 'vi', 'from_path' => $from, 'to_path' => $to, 'status_code' => 301, 'entry_id' => $entryId,
        'deleted_at' => $trashed, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $row('/cu', '/moi', null, $entry);
    $row('/cu', '/cu-hon', now()->subDay()->toDateTimeString().'+00');
    $row('/tay', '/dau-do', null);

    rtmAsMigrator(function (): void {
        rtmMigration('2085_01_01_000100_make_redirects_unique_on_live_rows')->down();
        rtmMigration('2085_01_01_000000_add_trash_source_and_targets_to_redirects')->down();
    });

    $before = rtmShape();
    expect($before['columns'])->toBe([])
        ->and($before['constraints'])->toContain('redirects_site_id_locale_from_path_unique')
        ->and($before['indexes'])->not->toContain('redirects_site_id_locale_from_path_live_unique', 'redirects_product_id_index')
        ->and($db->table('redirects')->orderBy('id')->pluck('to_path')->all())->toBe(['/moi', '/dau-do']);

    try {
        rtmAsMigrator(function (): void {
            rtmMigration('2085_01_01_000000_add_trash_source_and_targets_to_redirects')->up();
            rtmMigration('2085_01_01_000100_make_redirects_unique_on_live_rows')->up();
        });
    } finally {
        // whatever happened above, leave the schema the rest of the suite expects
        rtmAsMigrator(function (): void {
            rtmMigration('2085_01_01_000000_add_trash_source_and_targets_to_redirects')->up();
            rtmMigration('2085_01_01_000100_make_redirects_unique_on_live_rows')->up();
        });
    }

    expect(rtmShape()['columns'])->toBe(['deleted_at', 'product_id', 'source', 'taxonomy_id', 'ulid'])
        ->and(rtmShape()['constraints'])->not->toContain('redirects_site_id_locale_from_path_unique');
    $rows = $db->table('redirects')->orderBy('id')->get(['to_path', 'source', 'ulid']);
    expect($rows->pluck('source')->all())->toBe(['auto', 'manual'])
        ->and($rows->every(fn (object $r): bool => is_string($r->ulid) && strlen($r->ulid) === 26))->toBeTrue();
});
