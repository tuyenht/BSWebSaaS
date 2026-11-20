<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The indexes for the redirects trash and the new links (2085_000000), built CONCURRENTLY so reads
 * and writes go on while they build.
 *
 *   redirects_site_id_locale_from_path_live_unique — one LIVE redirect per (site, language, old
 *       address), as before, but a row in the trash no longer holds its address: it can be added
 *       again, and restoring a row whose address was taken meanwhile is refused by the screen. It
 *       also serves the storefront lookup (site_id, locale, from_path) with deleted_at IS NULL, the
 *       condition the model's scope adds. Built first; only then is the old full unique constraint
 *       dropped, so the table is never without a unique on its live rows.
 *   redirects_site_id_ulid_unique — the screen finds a row by its key. Unique: a NULL key (a row an
 *       older process wrote during the deploy, until `cms:fill-ulids`) does not collide.
 *   redirects_product_id_index, redirects_taxonomy_id_index — deleting a product or a category makes
 *       Postgres find its redirects with `WHERE product_id = $1` (ON DELETE SET NULL): no site_id, RLS
 *       does not apply, so only an index led by the column serves it. Partial on IS NOT NULL: most
 *       rows lead to a post or page. tests/Feature/Infrastructure/ForeignKeyIndexCoverageTest.php
 *       flags a foreign key left without one.
 *
 * Size (estimated, not measured): the redirects of a site are a few hundred rows at most, a large
 * site a few thousand; each index is tens of bytes a row.
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it). The
 * constraint drop takes the table lock for an instant, under a 5s lock_timeout.
 *
 * down() deletes the redirects in the trash (the full unique cannot hold a trashed row beside a live
 * one with the same address, and without deleted_at they would come back into service), builds the
 * full unique again CONCURRENTLY and turns it back into the constraint 2001 made, then drops the
 * rest.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const string OLD_UNIQUE = 'redirects_site_id_locale_from_path_unique';

    private const string LIVE_UNIQUE = 'redirects_site_id_locale_from_path_live_unique';

    /** @var array<string, string> index name → definition, built in this order */
    private const array INDEXES = [
        self::LIVE_UNIQUE => 'UNIQUE INDEX CONCURRENTLY IF NOT EXISTS '.self::LIVE_UNIQUE.' ON redirects (site_id, locale, from_path) WHERE deleted_at IS NULL',
        'redirects_site_id_ulid_unique' => 'UNIQUE INDEX CONCURRENTLY IF NOT EXISTS redirects_site_id_ulid_unique ON redirects (site_id, ulid)',
        'redirects_product_id_index' => 'INDEX CONCURRENTLY IF NOT EXISTS redirects_product_id_index ON redirects (product_id) WHERE product_id IS NOT NULL',
        'redirects_taxonomy_id_index' => 'INDEX CONCURRENTLY IF NOT EXISTS redirects_taxonomy_id_index ON redirects (taxonomy_id) WHERE taxonomy_id IS NOT NULL',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::INDEXES as $name => $definition) {
            $this->dropIfInvalid($name);
            DB::statement('CREATE '.$definition);
        }

        $this->briefly(fn () => DB::statement('ALTER TABLE redirects DROP CONSTRAINT IF EXISTS '.self::OLD_UNIQUE));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if ($this->hasDeletedAt()) {
            DB::statement('DELETE FROM redirects WHERE deleted_at IS NOT NULL');
        }

        if (! $this->constraintExists(self::OLD_UNIQUE)) {
            $this->dropIfInvalid(self::OLD_UNIQUE);
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS '.self::OLD_UNIQUE.' ON redirects (site_id, locale, from_path)');
            $this->briefly(fn () => DB::statement('ALTER TABLE redirects ADD CONSTRAINT '.self::OLD_UNIQUE.' UNIQUE USING INDEX '.self::OLD_UNIQUE));
        }

        foreach (array_reverse(array_keys(self::INDEXES)) as $name) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
    }

    private function dropIfInvalid(string $index): void
    {
        $invalid = DB::selectOne(
            'SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid',
            [$index],
        );

        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$index);
        }
    }

    /** One short transaction that gives up on a table lock after 5s instead of queueing requests. */
    private function briefly(Closure $step): void
    {
        DB::transaction(function () use ($step): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $step();
        });
    }

    private function constraintExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM pg_constraint WHERE conrelid = \'redirects\'::regclass AND conname = ?',
            [$name],
        ) !== null;
    }

    private function hasDeletedAt(): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = \'redirects\' AND column_name = \'deleted_at\'',
        ) !== null;
    }
};
