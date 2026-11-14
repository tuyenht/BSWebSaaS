<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The search box of the Notifications page reads index entries only. The two indexes the page lists
 * by also carry the search line (search_text, 2063) as an INCLUDE column, so a search walks the
 * reader's slice newest first as an Index Only Scan: site_id/user_id (and kind) are index conditions
 * (leakproof), `search_text LIKE '%word%'` is checked on each index entry after them (LIKE is not
 * leakproof, so under RLS it can only be a filter), and the table is visited only for a page the
 * visibility map does not yet mark all-visible. Before, every entry of the walk fetched its row from
 * the table: a word found nowhere read the heaviest reader's whole slice (~26,000 table pages).
 *
 *   - site_notifications_feed_covering (site_id, user_id, id DESC) INCLUDE (search_text) replaces
 *     site_notifications_feed (2022): every notice newest first (the "Tất cả" list, its cursor, its
 *     capped count, the bell's dropdown) and the search in "Tất cả".
 *   - site_notifications_kind_feed_covering (site_id, user_id, kind, id DESC) INCLUDE (search_text)
 *     replaces site_notifications_kind_feed (2061): one group's tab, its capped count, and the search
 *     in that tab.
 *
 * Same keys as the indexes they replace (neither had a predicate), so every statement that read the
 * old ones reads the new ones the same way; the index count stays the same. The unread index
 * (site_notifications_unread_feed) is unchanged: a search in "Chưa đọc" reads only unread rows.
 *
 * Measured on PostgreSQL 17 (default settings) as app_user under RLS + FORCE on 11.8 M notices on
 * 10,000 websites, the heaviest reader 28,158 notices (plans/reports/
 * notifications-search-covering-measure-261114/): see NotificationFeed::matches() for the timings.
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it), and
 * an old index is dropped only once its replacement is valid. CREATE/DROP INDEX CONCURRENTLY take
 * only SHARE UPDATE EXCLUSIVE, which never blocks a visitor's reads or writes, so no lock_timeout is
 * set: it could only cancel a build part-way. Only indexes change (no column, no result type), so
 * PgBouncer needs no restart. down() rebuilds the old two before it drops the new ones. No data
 * changes. Dropping search_text (2063's down()) drops both new indexes with it, so this migration is
 * rolled back first (migrate:rollback does that by order).
 */
return new class extends Migration
{
    /** CREATE / DROP INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    /** @var array<string, array{0: string, 1: string, 2: string}> new index => [definition, old index, old definition] */
    private const array INDEXES = [
        'site_notifications_feed_covering' => [
            '(site_id, user_id, id DESC) INCLUDE (search_text)',
            'site_notifications_feed', '(site_id, user_id, id DESC)',
        ],
        'site_notifications_kind_feed_covering' => [
            '(site_id, user_id, kind, id DESC) INCLUDE (search_text)',
            'site_notifications_kind_feed', '(site_id, user_id, kind, id DESC)',
        ],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::INDEXES as $index => [$definition, $old]) {
            $this->build($index, $definition);
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$old}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::INDEXES as $index => [, $old, $oldDefinition]) {
            $this->build($old, $oldDefinition);
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }

    /** Build $index CONCURRENTLY (again, when an interrupted run left it INVALID) and make sure it is valid. */
    private function build(string $index, string $definition): void
    {
        $this->dropIfInvalid($index);

        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON site_notifications {$definition}");

        $valid = DB::selectOne('SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND indisvalid', [$index]);
        if ($valid === null) {
            throw new RuntimeException("Index {$index} is not valid after its build: the old index is kept; run the migration again.");
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
