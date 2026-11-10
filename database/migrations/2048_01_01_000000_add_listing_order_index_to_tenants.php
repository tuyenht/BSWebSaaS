<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The platform customer list (Admin\Support\Customers\CustomerDirectory::page) shows the newest
 * customers first, ordered by `coalesce(created_at, '-infinity') desc, id desc` (a customer with
 * no created_at sorts as the oldest, so it still pages), and pages on the same pair. No index
 * served that order, so each page with no search read every customer and sorted them. A btree on
 * exactly that expression and id, partial on the customers not deleted (the model's soft delete
 * scope), reads the page's rows straight from the index. Measured on 50,000 customers (median of
 * three): the first page 11 ms → 0.02 ms, a later page of one status 10 ms → 0.06 ms, for a 1.5 MB
 * index. `tenants` is platform-wide (D6), so the index leads with the ordered expression, the
 * exception the migration rules allow for D6 tables backed by measurements. The expression must
 * stay word for word what page() orders by, or the planner stops using it.
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it).
 * Expand-only; down() drops what up() added.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const string INDEX = 'tenants_listing_order_index';

    public function up(): void
    {
        $invalid = DB::selectOne(
            'SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid',
            [self::INDEX],
        );

        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX
            ." ON tenants ((coalesce(created_at, '-infinity'::timestamptz)) DESC, id DESC) WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
    }
};
