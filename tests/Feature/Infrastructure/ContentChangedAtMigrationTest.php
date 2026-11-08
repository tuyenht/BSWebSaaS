<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The migration of the hidden content mark (content_changed_at on entry_translations and
 * product_translations): up() adds a nullable timestamptz with no default, the app role may write
 * it, down() drops it, and each can run twice. Run in both directions from whatever state the
 * database is in, and always put back what it found: a migrated database (CI) gets the columns
 * back, one without them gets none left behind.
 *
 * CI only: dropping and re-adding a column on the shared dev database would wipe its marks, break
 * live saves in that window and leave PgBouncer holding stale plans for other sessions.
 */

/**
 * The content mark column of each translation table that has one, read on the privileged connection.
 *
 * @return list<object{table_name: string, data_type: string, is_nullable: string, column_default: string|null}>
 */
function contentMarkColumns(): array
{
    /** @var list<object{table_name: string, data_type: string, is_nullable: string, column_default: string|null}> */
    return DB::connection('pgsql_admin')->select(
        "select table_name, data_type, is_nullable, column_default from information_schema.columns
         where table_schema = current_schema() and column_name = 'content_changed_at'
           and table_name in ('entry_translations', 'product_translations') order by table_name"
    );
}

it('adds the content mark as a nullable timestamptz with no default the app may write, and drops it again', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2052_01_01_000000_add_content_changed_at_to_translations.php');
    $found = count(contentMarkColumns());
    expect($found)->toBeIn([0, 2]);

    $grants = fn (): ?object => DB::connection('pgsql_admin')->selectOne(
        "select has_column_privilege('app_user', 'entry_translations', 'content_changed_at', 'UPDATE') as entries,
                has_column_privilege('app_user', 'product_translations', 'content_changed_at', 'UPDATE') as products"
    );

    $dropped = null;
    // Migrations run on the privileged connection (ALTER TABLE needs the table's owner).
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        if ($found === 2) {
            $migration->down();
            $migration->down();
            $dropped = contentMarkColumns();
        }
        $migration->up();
        $migration->up();
        $added = contentMarkColumns();
        $granted = $grants();
        if ($found === 0) {
            $migration->down();
            $migration->down();
            $dropped = contentMarkColumns();
        }
    } finally {
        // Whatever failed above, the schema is left as it was found.
        $found === 2 ? $migration->up() : $migration->down();
        DB::setDefaultConnection($original);
    }

    expect($dropped)->toBe([])
        ->and(array_map(fn (object $column): string => $column->table_name, $added))->toBe(['entry_translations', 'product_translations'])
        ->and($granted?->entries)->toBeTrue()
        ->and($granted?->products)->toBeTrue()
        ->and(count(contentMarkColumns()))->toBe($found);
    foreach ($added as $column) {
        expect($column->data_type)->toBe('timestamp with time zone')
            ->and($column->is_nullable)->toBe('YES')
            ->and($column->column_default)->toBeNull();
    }
})->skip(getenv('CI') !== 'true', 'drops and re-adds a column: runs on CI\'s own database only');
