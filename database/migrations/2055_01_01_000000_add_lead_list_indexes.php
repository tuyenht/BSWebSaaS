<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the leads list (Admin\Livewire\Forms\Inbox through LeadListQuery). The list reads a
 * website's leads newest first with a keyset cursor on (created_at, ulid); before these indexes every
 * page, tab and cursor step read and sorted all of the website's leads.
 *
 *   - form_submissions_live_created_idx: the "all" tab (every status but spam) by time, its cursor
 *     `(created_at, ulid) < (?, ?)` as one range of the index.
 *   - form_submissions_status_created_idx: the same within one status (the Mới / Đã đọc / Lưu trữ /
 *     Thư rác tabs) and their capped counts. It starts with (site_id, status), so the old index on
 *     exactly those two columns is dropped: every query it served this one serves.
 *
 * Measured on PostgreSQL 17 (superuser, without RLS; the global scope's `site_id = ?` leads the
 * index either way), one run after ANALYZE, on 1,100,000 leads: one website of 1,000,000 over three
 * years (1.5% new, 3% spam) and 5,000 websites of 20. On the large website (ms):
 *
 *   "all" tab, first page              198 -> 0.07
 *   "new" tab, first page               57 -> 0.09
 *   cursor 400 days back                171 -> 0.05
 *   capped count of the "new" tab       3.3 -> 2.2
 *   a website of 20, first page         0.07 -> 0.10
 *
 * The form filter keeps the (site_id, form_id, created_at) index: its rows come out by time and
 * only leads sharing one created_at need the ulid sort.
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it).
 * down() restores the (site_id, status) index before it drops the two new ones.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    /** @var array<string, string> index name => definition after the table */
    private const array INDEXES = [
        'form_submissions_live_created_idx' => '(site_id, created_at DESC, ulid DESC)',
        'form_submissions_status_created_idx' => '(site_id, status, created_at DESC, ulid DESC)',
    ];

    private const string OLD = 'form_submissions_site_id_status_index';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::INDEXES as $index => $definition) {
            $this->dropIfInvalid($index);

            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON form_submissions {$definition}");
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::OLD);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->dropIfInvalid(self::OLD);
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::OLD.' ON form_submissions (site_id, status)');

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
