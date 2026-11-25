<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * taxonomies and content_sections get UNIQUE (site_id, id), the key a same-site composite foreign key
 * aims at: the next migration's content_section_categories references taxonomies (site_id, id) and
 * content_sections (site_id, id), so a row can never pair one site with another site's category or
 * section, even written past the app (a superuser, a seeder). id is the primary key, so every
 * existing row already satisfies it; nothing is rewritten.
 *
 * taxonomies is read by every storefront page and grows with the sites, so each index is built
 * CONCURRENTLY (outside a transaction) and then attached as the constraint (UNIQUE USING INDEX),
 * which only takes a brief lock, in its own transaction with a 5 s lock wait. Re-runnable after a
 * mid-run failure: an index an interrupted build left INVALID is dropped and built again, and the
 * constraint is added only when missing. down() removes both (after the next migration's down(),
 * which drops the table that references them).
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    /** @var array<string, string> table => constraint (and index) name */
    private const array KEYS = [
        'taxonomies' => 'taxonomies_site_id_id_unique',
        'content_sections' => 'content_sections_site_id_id_unique',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::KEYS as $table => $name) {
            $invalid = DB::selectOne('SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid', [$name]);
            if ($invalid !== null) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            }
            DB::statement("CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$table} (site_id, id)");

            $exists = DB::selectOne('SELECT 1 FROM pg_constraint WHERE conrelid = to_regclass(?) AND conname = ?', [$table, $name]);
            if ($exists === null) {
                DB::transaction(function () use ($table, $name): void {
                    DB::statement("SET LOCAL lock_timeout = '2s'");
                    DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} UNIQUE USING INDEX {$name}");
                });
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::KEYS as $table => $name) {
            DB::transaction(function () use ($table, $name): void {
                DB::statement("SET LOCAL lock_timeout = '2s'");
                // dropping the constraint drops its index too
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            });
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }
};
