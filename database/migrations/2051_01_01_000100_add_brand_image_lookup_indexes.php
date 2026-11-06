<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The lookups that purging a picture makes on the four brand image columns of site_profiles
 * (2051_000000). Media is hard-deleted when its trash is emptied and by the nightly
 * media:purge-trash, and each FK's ON DELETE SET NULL then finds the profile using it with
 * `WHERE <column> = $1`: no site_id and no RLS, so a site_id-led index cannot serve it (the
 * foreign-key exception, .claude/rules/migrations.md; ForeignKeyIndexCoverageTest checks it). Each
 * index is led by its column and partial on `IS NOT NULL`: the lookup's `=` implies the predicate,
 * and profiles without that image stay out of the index.
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it).
 * Expand-only; down() drops what up() added.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const array COLUMNS = ['logo_media_id', 'logo_dark_media_id', 'favicon_media_id', 'og_image_media_id'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::COLUMNS as $column) {
            $index = "site_profiles_{$column}_index";
            $this->dropIfInvalid($index);
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON site_profiles ({$column}) WHERE {$column} IS NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::COLUMNS as $column) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS site_profiles_{$column}_index");
        }
    }

    private function dropIfInvalid(string $index): void
    {
        $invalid = DB::selectOne(
            'SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid',
            [$index],
        );

        if ($invalid !== null) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }
};
