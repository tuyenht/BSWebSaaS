<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * routes.entry_id has carried a cascade FK + (site_id, entry_id) index since birth; the
 * product_id/taxonomy_id columns added alongside it got neither. Without the FK, hard-deleting
 * a product (Trash purge) or a taxonomy strands its route row — a 404 that permanently squats
 * the path under UNIQUE(site_id, locale, path), so the slug can never be reclaimed. Without the
 * index, every route lookup by target seq-scans at scale. Also gives products the partial index
 * its only hot storefront shape (published list ordered by published_at DESC, id DESC) sorts on.
 *
 * Every step is idempotent (constraint-existence guard / IF NOT EXISTS): with no surrounding
 * transaction (CONCURRENTLY forbids one) a mid-run failure must be safely re-runnable. Routes
 * already stranded by a hard-deleted target are purged first — they are exactly the dead 404s
 * the FKs exist to prevent, and they would fail FK validation. Expand-only; down() drops what
 * up() added.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(
            'DELETE FROM routes r WHERE r.product_id IS NOT NULL
             AND NOT EXISTS (SELECT 1 FROM products p WHERE p.id = r.product_id)'
        );
        DB::statement(
            'DELETE FROM routes r WHERE r.taxonomy_id IS NOT NULL
             AND NOT EXISTS (SELECT 1 FROM taxonomies t WHERE t.id = r.taxonomy_id)'
        );

        $this->addForeignKeyIfMissing(
            'routes_product_id_foreign',
            'ALTER TABLE routes ADD CONSTRAINT routes_product_id_foreign
             FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE'
        );
        $this->addForeignKeyIfMissing(
            'routes_taxonomy_id_foreign',
            'ALTER TABLE routes ADD CONSTRAINT routes_taxonomy_id_foreign
             FOREIGN KEY (taxonomy_id) REFERENCES taxonomies (id) ON DELETE CASCADE'
        );

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS routes_site_id_product_id_index ON routes (site_id, product_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS routes_site_id_taxonomy_id_index ON routes (site_id, taxonomy_id)');

        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS products_site_id_status_published_at_id_index
             ON products (site_id, status, published_at DESC, id DESC) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_site_id_status_published_at_id_index');
        DB::statement('DROP INDEX IF EXISTS routes_site_id_taxonomy_id_index');
        DB::statement('DROP INDEX IF EXISTS routes_site_id_product_id_index');
        DB::statement('ALTER TABLE routes DROP CONSTRAINT IF EXISTS routes_taxonomy_id_foreign');
        DB::statement('ALTER TABLE routes DROP CONSTRAINT IF EXISTS routes_product_id_foreign');
    }

    private function addForeignKeyIfMissing(string $constraintName, string $addConstraintSql): void
    {
        $exists = DB::selectOne(
            'SELECT 1 FROM pg_constraint WHERE conname = ? AND conrelid = to_regclass(?)',
            [$constraintName, 'routes']
        );

        if ($exists === null) {
            DB::statement($addConstraintSql);
        }
    }
};
