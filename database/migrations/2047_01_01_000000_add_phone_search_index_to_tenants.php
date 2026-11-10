<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The platform customer list's search (Admin\Support\Customers\CustomerDirectory) also looks for
 * three or more digits anywhere in the customer's contact phone, `contact_phone LIKE '%…%'` on the
 * stored international form (+84901234567). A search by number therefore read every row of
 * `tenants` (8-12 ms at 50,000 customers, median 8.6 of five, growing with the table), where every
 * other part of the search is served by an index. A trigram GIN index (pg_trgm) on the column
 * serves it: the search always sends three digits or more, the shortest a trigram index can match.
 * Measured on 50,000 phones (one in five customers without one; median of five): "84901" (405
 * matches) 5.5 ms read whole (on a two-column copy; the real table is wider) → 0.37 ms on the index,
 * "123" 0.14 ms, for a 1.2 MB index. Partial on the customers that have a phone; `LIKE` never
 * matches a NULL, so the planner may use it for every phone search. `tenants` is platform-wide (D6), so the index leads with the searched column, the
 * exception the migration rules allow for D6 tables backed by measurements.
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it).
 * Expand-only; down() drops what up() added.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const string INDEX = 'tenants_contact_phone_trgm_index';

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
            .' ON tenants USING gin (contact_phone gin_trgm_ops) WHERE contact_phone IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
    }
};
