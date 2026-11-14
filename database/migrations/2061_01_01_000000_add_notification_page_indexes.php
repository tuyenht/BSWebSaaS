<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the Notifications page (Admin\Livewire\Notifications through NotificationFeed). The
 * page counts all six tabs on every render and lists one tab newest first by id (keyset). Before
 * these indexes a tab whose notices are rare for the reader (Bảo mật, Hệ thống) walked the reader's
 * whole 90-day slice of the feed index, checking each row's kind on the table, and the "Chưa đọc"
 * tab sorted all of the reader's unread rows, because the unread index held no id.
 *
 *   - site_notifications_unread_feed: the reader's unread notices newest first: the "Chưa đọc" page,
 *     its cursor (`id < ?` / `id >= ?`) and capped count, the bell's capped count (a prefix), and
 *     "Đánh dấu đã đọc tất cả" (`ORDER BY id DESC LIMIT 201`). It replaces site_notifications_unread
 *     (site_id, user_id) WHERE read_at IS NULL, which it covers as a prefix.
 *   - site_notifications_kind_feed: one group tab (`kind IN (the group's kinds)`): each capped count
 *     reads only that group's entries, and a page reads that group's rows only (one kind: one ordered
 *     range; several kinds: a top-N sort bounded by one reader's rows of that group).
 *
 * Measured on PostgreSQL 17 as app_user under RLS (reads: median of 5 warm runs; writes: of 30 runs
 * for the lead, 5 for marking all read) on 11,590,506 notices: 9,990 websites of 3 readers with ~200
 * each, and 10 websites where 20 readers get every one of 300 leads a day for 91 days. The heaviest reader has 27,852 notices, the oldest 7,956 unread, 4 Bảo mật
 * and 2 Hệ thống. Before -> after (ms):
 *
 *   six tab counts                          300   -> 0.23
 *   "Chưa đọc", first page                    9.4 -> 0.03
 *   "Chưa đọc", 400 rows back drawn again     5.5 -> 0.37
 *   "Bảo mật", first page                   141   -> 0.05
 *   "Duyệt bài", first page                   0.6 -> 0.9
 *   a reader with ~200 notices, six counts    0.43 -> 0.36
 *   one lead to 20 readers (20 inserts)       5.0 -> 4.6–5.2
 *   "Đánh dấu đã đọc tất cả", 7,956 rows    526   -> 551–583
 *
 * The unread index alone left the six counts at 430 ms, so the kind index is needed too. Index
 * size on that data: kind 697 MB, unread 72 MB (was 13 MB). Every operator in these conditions
 * (bigint and varchar comparisons) is leakproof, so they stay index conditions under RLS;
 * tests/Feature/Admin/NotificationPageIndexesTest.php plans the real statements that way.
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it).
 * down() restores the old unread index before it drops the two new ones. No data changes.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    /** @var array<string, string> index name => definition after the table */
    private const array INDEXES = [
        'site_notifications_unread_feed' => '(site_id, user_id, id DESC) WHERE read_at IS NULL',
        'site_notifications_kind_feed' => '(site_id, user_id, kind, id DESC)',
    ];

    private const string OLD = 'site_notifications_unread';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::INDEXES as $index => $definition) {
            $this->dropIfInvalid($index);

            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON site_notifications {$definition}");
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::OLD);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->dropIfInvalid(self::OLD);
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::OLD.' ON site_notifications (site_id, user_id) WHERE read_at IS NULL');

        foreach (array_keys(self::INDEXES) as $index) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
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
