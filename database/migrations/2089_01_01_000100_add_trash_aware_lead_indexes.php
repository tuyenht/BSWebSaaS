<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the leads list now that a lead can sit in the Trash (deleted_at). Every list, count
 * and cursor of «Khách liên hệ» reads `deleted_at IS NULL`; without these, each capped count would
 * check that column on the table row of up to 10,001 leads instead of walking the index alone.
 *
 *   - form_submissions_kept_created_idx: the "all" group by time without the Trash, its cursor
 *     `(created_at, ulid) < (?, ?)` as one range of the index.
 *   - form_submissions_kept_status_created_idx: the same within one status, and the capped counts.
 *   - form_submissions_trash_idx: the Trash tab, last trashed first, its cursor on
 *     (deleted_at, ulid), its count, and the daily purge's `deleted_at < cutoff` range. Partial on
 *     the trashed rows only: a handful per site.
 *
 * Measured on PostgreSQL 17 as app_user under RLS, one run after VACUUM ANALYZE, on 1,000,000 leads
 * of one website over three years (1.5% new, 3% spam), 1% of them in the Trash, moved there over
 * the last 60 days. Without these indexes -> with them (ms):
 *
 *   "all" tab, first page                    0.05 -> 0.05
 *   "new" tab, first page                    0.08 -> 0.13
 *   cursor step 400 days back                0.05 -> 0.07
 *   the six capped counts (one statement)     102 -> 22   (each status and the Trash index-only)
 *   the Trash, first page                      51 -> 0.08
 *   one chunk of the daily purge              102 -> 9.1
 *
 * Building the three took 2.8 s on that table; they weigh 64 MB, 77 MB and 0.7 MB.
 *
 * As app_user under RLS the plans hold: every operator in these conditions (bigint, varchar,
 * timestamptz and char(26) comparisons, IS [NOT] NULL) is leakproof, so the cursor stays an index
 * condition; tests/Feature/Admin/LeadListIndexesTest.php plans the real statements that way.
 *
 * Expand-contract: form_submissions_live_created_idx and form_submissions_status_created_idx
 * (2055) stay for now. They are dropped in a later release, once the production plans show the new
 * ones in use. Until then they cost one more index write per new lead.
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it).
 * down() drops the three indexes; the 2055 ones still serve every list.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    /** @var array<string, string> index name => definition after the table */
    private const array INDEXES = [
        'form_submissions_kept_created_idx' => '(site_id, created_at DESC, ulid DESC) WHERE deleted_at IS NULL',
        'form_submissions_kept_status_created_idx' => '(site_id, status, created_at DESC, ulid DESC) WHERE deleted_at IS NULL',
        'form_submissions_trash_idx' => '(site_id, deleted_at DESC, ulid DESC) WHERE deleted_at IS NOT NULL',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::INDEXES as $index => $definition) {
            $this->dropIfInvalid($index);

            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON form_submissions {$definition}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_reverse(array_keys(self::INDEXES)) as $index) {
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
