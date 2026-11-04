<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The lookup that purging a picture makes on taxonomies.cover_media_id (2050_000000). Media is
 * hard-deleted when its trash is emptied and by the nightly media:purge-trash, and the FK's
 * ON DELETE SET NULL then finds the categories using it with `WHERE cover_media_id = $1`: no
 * site_id and no RLS, so a site_id-led index cannot serve it (the foreign-key exception,
 * .claude/rules/migrations.md; ForeignKeyIndexCoverageTest checks it). Led by the column and
 * partial on `IS NOT NULL`: the lookup's `=` implies the predicate, and categories without a
 * picture stay out of the index.
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it).
 * Expand-only; down() drops what up() added.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const string INDEX = 'taxonomies_cover_media_id_index';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->dropIfInvalid(self::INDEX);

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX.' ON taxonomies (cover_media_id) WHERE cover_media_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
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
