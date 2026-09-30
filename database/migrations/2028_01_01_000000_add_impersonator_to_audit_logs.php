<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who really acted when platform staff sign in as a website member to help (design.md v2.33):
 * actor_id stays the member whose account was used, impersonator_id / impersonator_label name the
 * staff member behind it, so a site's activity log reads "<staff> on behalf of <member>" and can
 * be filtered to support work. Real columns, not meta keys: the log filters on them (graduation rule).
 *
 * Expand-only. Nullable columns with no default are a metadata-only ALTER (no rewrite); every
 * existing row was written by its own actor, so NULL is the correct value for history. No foreign
 * key, like actor_id: the row must outlive the staff account. No index yet — the only reader is
 * the support filter of the activity log, indexed once that screen exists and is measured.
 * audit_logs takes a write on most requests, so the brief exclusive lock gives up after 5s instead
 * of queueing requests behind it; the migration is re-runnable (IF NOT EXISTS).
 *
 * down() drops both columns; rows keep who the account was, and lose who stood behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS impersonator_id bigint NULL, ADD COLUMN IF NOT EXISTS impersonator_label varchar(255) NULL');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('audit_logs', 'impersonator_id')) {
            return;
        }

        DB::statement('ALTER TABLE audit_logs DROP COLUMN IF EXISTS impersonator_id, DROP COLUMN IF EXISTS impersonator_label');
    }
};
