<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which support session a row belongs to (design.md v2.34): the console mints a ulid when it issues
 * a support sign-in link; the "issued" row, the "entered" row, every row the staff member writes
 * on the website and the "ended" row all carry it, so the activity log can show "every action of
 * this session". A real column, not a meta key: the log filters on it (graduation rule).
 *
 * Expand-only. A nullable column with no default is a metadata-only ALTER (no rewrite); rows
 * written before sessions had a code, or outside any session, keep NULL. No index yet: the only
 * reader is the session filter of the activity log, indexed once that screen exists and is
 * measured. audit_logs takes a write on most requests, so the brief exclusive lock gives up after
 * 5s instead of queueing requests behind it; the migration is re-runnable (IF NOT EXISTS).
 *
 * down() drops the column; rows keep their impersonator, and lose which session they belonged to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS support_session_ulid char(26) NULL');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('audit_logs', 'support_session_ulid')) {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE audit_logs DROP COLUMN IF EXISTS support_session_ulid');
    }
};
