<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * site_notifications is vacuumed once 1 % of it changed (dead rows) or was inserted, instead of the
 * server default 20 %. The Notifications page search reads the feed indexes' entries only (2071), and
 * the table is visited only for a page the visibility map no longer marks all-visible; vacuum sets
 * those bits again. Rewriting one large website's notices (a backfill of search_text, 1.35 M row
 * versions on an 11.8 M-row copy) clears the bit of nearly every page, every website's included,
 * because all websites' rows share pages. At 20 % that never starts an autovacuum (2.36 M needed), so
 * a search for a word found nowhere stayed at 144-192 ms for days; at 1 % autovacuum started after
 * 8.5 s and searches were under 150 ms again from about 110 s (plans/reports/
 * notifications-search-covering-measure-261114/autovacuum-1pct-timeline-site3.txt).
 *
 * Every vacuum still reads the table's indexes in full; at today's size that is a few runs a day.
 *
 * ALTER TABLE ... SET (autovacuum_*) takes SHARE UPDATE EXCLUSIVE: it never blocks a visitor's reads
 * or writes, but it waits for a running vacuum, and an anti-wraparound one is not cancelled for it,
 * so the wait is capped at 5 s (the migration then fails and is simply run again). Only storage
 * parameters change (no column, no result type), so PgBouncer needs no restart. down() puts the
 * server defaults back. No data changes.
 */
return new class extends Migration
{
    /** Each statement runs in its own short transaction with its lock timeout (below). */
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('ALTER TABLE site_notifications SET (autovacuum_vacuum_scale_factor = 0.01, autovacuum_vacuum_insert_scale_factor = 0.01)');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('ALTER TABLE site_notifications RESET (autovacuum_vacuum_scale_factor, autovacuum_vacuum_insert_scale_factor)');
        });
    }
};
