<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the filters of the operator activity log (Platform\Audit\Index). `audit_logs` is a
 * platform-wide table (D6): most of its reads are not about one website, so these lead with the
 * filter's own column (time, person, support session) instead of site_id, the exception the
 * migration rules allow for D6 tables backed by measurements.
 *
 * Measured on 5M rows (2,000 websites, 20,000 people, 2% support rows), page query of the screen:
 *   - first page, action group, platform only, last 7 days: 430–1,105 ms → 0.02–0.06 ms (created_at, id);
 *   - one person: 390–459 ms → 0.24–3.6 ms (actor_id, plus impersonator_id for "on behalf of");
 *   - support actions only, one support session: ~470 ms → 0.04 ms (the two partial indexes);
 *   - the "Người làm" sample query: 410–980 ms → under 1.3 ms.
 * At 20M rows every filter stayed at 0.02–2.4 ms. One website keeps using (site_id, created_at).
 *
 * A person filter is "actor OR on behalf of", which Postgres serves by combining the two indexes
 * and sorting, so neither carries created_at: with it they were no faster and five times larger.
 * The support predicate is written exactly as the screen sends it, or the partial index would
 * not match. Leading with created_at also suits a later split of the table by month.
 *
 * Writes: an ordinary row maintains two more indexes; the three partial ones hold support rows
 * only (~2%). The app only appends to this table, so no update or delete pays for them.
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it).
 * Expand-only; down() drops what up() added.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    /** @var array<string, string> index name => definition after "ON audit_logs" */
    private const array INDEXES = [
        'audit_logs_created_at_id_index' => '(created_at DESC, id DESC)',
        'audit_logs_actor_id_index' => '(actor_id)',
        'audit_logs_impersonator_id_index' => '(impersonator_id) WHERE impersonator_id IS NOT NULL',
        'audit_logs_support_created_at_index' => "(created_at DESC, id DESC) WHERE impersonator_id IS NOT NULL OR action LIKE 'support.%'",
        'audit_logs_support_session_created_at_index' => '(support_session_ulid, created_at DESC, id DESC) WHERE support_session_ulid IS NOT NULL',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $index => $definition) {
            $this->dropIfInvalid($index);

            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON audit_logs {$definition}");
        }
    }

    public function down(): void
    {
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
