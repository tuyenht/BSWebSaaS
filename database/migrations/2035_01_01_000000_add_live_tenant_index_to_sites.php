<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `sites.max` counts a customer's websites that are not soft-deleted whenever ProvisionSite creates
 * one (`WHERE tenant_id = ? AND deleted_at IS NULL`), and `sites.tenant_id` had no index, so the
 * count read the whole `sites` table. Provisioning only, never a storefront request: the index is
 * there so the cost does not grow with the platform's number of websites.
 *
 * Partial on `deleted_at IS NULL`, the shape of the count, so it does not serve a lookup when a
 * customer is hard-deleted: customers are never hard-deleted (ForeignKeyIndexCoverageTest keeps
 * `sites.tenant_id` on its list for that reason).
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it).
 * Expand-only; down() drops what up() added.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const string INDEX = 'sites_tenant_id_live_index';

    public function up(): void
    {
        $invalid = DB::selectOne(
            'SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid',
            [self::INDEX],
        );

        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX.' ON sites (tenant_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
    }
};
