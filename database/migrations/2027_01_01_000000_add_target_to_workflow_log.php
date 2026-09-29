<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Says WHAT moved in a trail row: the entry itself, or a pending change to a live entry
 * (entry_drafts) travelling through the same workflow. Without it, a change going
 * "Bản nháp → Chờ duyệt" would read in the entry's trail as if the live entry left the site.
 *
 * Expand-only. A constant DEFAULT is a metadata-only ALTER on PostgreSQL 11+ (no rewrite), and
 * every existing row is an entry move, so the default is also the correct backfill. The CHECK
 * (D13: varchar + CHECK, no native enum) is added NOT VALID and then validated, the house pattern
 * for a CHECK on an existing table. The migration runs in one transaction, so the table lock
 * lasts until commit; workflow_log holds one row per approval move, so validation is brief.
 * Re-runnable after a mid-run failure (IF NOT EXISTS / DROP IF EXISTS).
 * No index: the trail reads through (site_id, entry_id, created_at).
 *
 * down() drops the constraint and the column; trail rows keep everything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE workflow_log ADD COLUMN IF NOT EXISTS target varchar(16) NOT NULL DEFAULT 'entry'");
        DB::statement('ALTER TABLE workflow_log DROP CONSTRAINT IF EXISTS workflow_log_target_check');
        DB::statement("ALTER TABLE workflow_log ADD CONSTRAINT workflow_log_target_check CHECK (target IN ('entry', 'change')) NOT VALID");
        DB::statement('ALTER TABLE workflow_log VALIDATE CONSTRAINT workflow_log_target_check');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('workflow_log', 'target')) {
            return;
        }

        DB::statement('ALTER TABLE workflow_log DROP CONSTRAINT IF EXISTS workflow_log_target_check');
        DB::statement('ALTER TABLE workflow_log DROP COLUMN target');
    }
};
