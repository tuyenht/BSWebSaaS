<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a parent row makes Postgres find its children with `WHERE <fk column> = $1`, a lookup
 * with no site_id that bypasses RLS. Our site_id-first `(site_id, fk)` indexes cannot serve it, so
 * each hard delete below scanned the whole child table, every site's rows, inside the request's
 * transaction (measured on 5M rows: 316 ms per lookup, 0.06 ms with an index led by the column).
 * These indexes lead with the foreign-key column for every parent the app hard-deletes:
 *   - entries: Trash → delete permanently, one or in bulk;
 *   - products: delete permanently;
 *   - media: empty trash, and the nightly media:purge-trash;
 *   - taxonomies: DeleteTaxonomy;
 *   - menu items: DeleteMenuItem, and DeleteMenu through its cascade;
 *   - custom roles: DeleteCustomRole.
 * A nullable column gets a partial `IS NOT NULL` index: the lookup's `=` implies the predicate, so
 * the planner still uses it, and rows without a parent stay out of it.
 * tests/Feature/Infrastructure/ForeignKeyIndexCoverageTest.php flags any foreign key left without one.
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it).
 * Expand-only; down() drops what up() added.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    /** @var array<string, array{string, string, bool}> index name => [table, column, nullable] */
    private const INDEXES = [
        'entry_revisions_entry_id_index' => ['entry_revisions', 'entry_id', false],
        'routes_entry_id_index' => ['routes', 'entry_id', true],
        'workflow_log_entry_id_index' => ['workflow_log', 'entry_id', true],
        'redirects_entry_id_index' => ['redirects', 'entry_id', true],
        'entries_parent_id_index' => ['entries', 'parent_id', true],
        'routes_product_id_index' => ['routes', 'product_id', true],
        'product_images_media_id_index' => ['product_images', 'media_id', false],
        'entry_taxonomies_taxonomy_id_index' => ['entry_taxonomies', 'taxonomy_id', false],
        'product_taxonomies_taxonomy_id_index' => ['product_taxonomies', 'taxonomy_id', false],
        'routes_taxonomy_id_index' => ['routes', 'taxonomy_id', true],
        'taxonomies_parent_id_index' => ['taxonomies', 'parent_id', true],
        'menu_items_parent_id_index' => ['menu_items', 'parent_id', true],
        'model_has_roles_role_id_index' => ['model_has_roles', 'role_id', false],
        'role_has_permissions_role_id_index' => ['role_has_permissions', 'role_id', false],
        'invitation_roles_role_id_index' => ['invitation_roles', 'role_id', false],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $index => [$table, $column, $nullable]) {
            $this->dropIfInvalid($index);

            DB::statement(sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s)%s',
                $index,
                $table,
                $column,
                $nullable ? " WHERE {$column} IS NOT NULL" : '',
            ));
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $index) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
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
