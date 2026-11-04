<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The fetch of a menu's live items, now that removed items wait in a trash (menu_items.deleted_at,
 * 2050_000000): the storefront menu (MenuResolver), the admin tree and the tree edits read
 * `site_id = ? AND menu_id IN (…) AND deleted_at IS NULL`, some by parent and position too, and
 * this index holds only the live rows, so trashed ones never cost a read. The predicate is written
 * exactly as the model's soft-delete scope sends it (a literal, so a generic plan under PgBouncer's
 * prepared statements still matches it). Leads with site_id.
 *
 * The older non-partial index menu_items_site_id_menu_id_parent_id_position_index STAYS. It is the
 * one that serves the composite (site_id, menu_id) FK's cascade when a menu is deleted, which must
 * reach trashed items too (a partial index does not count for that lookup; see
 * ForeignKeyIndexCoverageTest), and it serves the trash list of a menu. Replacing it with a shorter
 * (site_id, menu_id) index is a later step, taken on pg_stat evidence.
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it).
 * Expand-only; down() drops what up() added.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const string INDEX = 'menu_items_live_tree_idx';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->dropIfInvalid(self::INDEX);

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX.' ON menu_items (site_id, menu_id, parent_id, position) WHERE deleted_at IS NULL');
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
