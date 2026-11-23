<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A Trash for the leads of «Khách liên hệ»: moving a lead there keeps it 30 days
 * (`leads:purge-trash`), and it can be brought back exactly as it was (its status is not touched).
 * Expand-only: the new column is nullable with no default, so Postgres only changes the catalog
 * (no row is rewritten) and the code running before this deploy keeps working (it never writes a
 * trashed row and lists every lead, as it does today).
 *
 *   deleted_at — set when a lead goes to the Trash. Every screen reads leads through one entry
 *       (LeadListQuery::leads()), which keeps trashed rows out of every group, count, search, the
 *       bell's links and the CSV file. There is no model scope on purpose: «Xóa theo yêu cầu của
 *       khách» and the purge must still reach a lead in the Trash. RLS stays on site_id.
 *
 * The indexes that read the Trash and the lists without it come in the next migration (built
 * CONCURRENTLY, outside a transaction).
 *
 * Grants: the app role's rights are on whole tables (0000_01_01_000100), and a column added to a
 * table carries them, so app_user reads and writes deleted_at with nothing granted here.
 *
 * Locks: the ALTER takes the table lock for an instant and gives up after 5s rather than queue the
 * visitors' form submissions behind it (lock_timeout, local to this migration's transaction).
 *
 * down() drops the column: leads still in the Trash then come back into their groups. Nothing is
 * lost, and nothing can be purged by mistake on the way back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE form_submissions ADD COLUMN IF NOT EXISTS deleted_at timestamp(0) with time zone NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE form_submissions DROP COLUMN IF EXISTS deleted_at');
    }
};
