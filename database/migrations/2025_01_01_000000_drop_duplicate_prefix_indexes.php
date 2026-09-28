<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Four indexes repeat the leading column of a longer index on the same table, so they serve no
 * lookup it cannot while every write still maintains them (ADR-18: each index is a write cost):
 *   - mediables (media_id), under UNIQUE (media_id, mediable_type, mediable_id, collection);
 *   - roles (site_id), under UNIQUE (site_id, name, guard_name);
 *   - model_has_roles and model_has_permissions (site_id), under primary keys led by site_id.
 * tests/Feature/Infrastructure/DuplicateIndexTest.php keeps new duplicates out.
 *
 * CONCURRENTLY forbids a transaction, so both directions must be re-runnable after a mid-run
 * failure: IF [NOT] EXISTS, and down() first drops an index an interrupted build left INVALID
 * (IF NOT EXISTS alone would keep it). down() rebuilds each index as 0003 and 2006 created it.
 */
return new class extends Migration
{
    /** DROP/CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    /** @var array<string, string> index name => table (column), as first created */
    private const INDEXES = [
        'mediables_media_id_index' => 'mediables (media_id)',
        'roles_site_id_index' => 'roles (site_id)',
        'model_has_roles_site_id_index' => 'model_has_roles (site_id)',
        'model_has_permissions_site_id_index' => 'model_has_permissions (site_id)',
    ];

    public function up(): void
    {
        foreach (array_keys(self::INDEXES) as $index) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $index => $definition) {
            $invalid = DB::selectOne(
                'SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid',
                [$index],
            );

            if ($invalid !== null) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
            }

            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON {$definition}");
        }
    }
};
