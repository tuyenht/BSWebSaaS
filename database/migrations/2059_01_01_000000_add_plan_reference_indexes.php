<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Hoàn tác" right after "Thêm gói" or "Nhân bản" on the plans screen deletes the plan just made
 * (PlatformPlans::discardCopy), so a plan row can now be hard-deleted. Deleting it makes Postgres
 * find the rows that point at it with `WHERE <fk column> = $1`: tenants.plan_id (ON DELETE SET
 * NULL) and tenant_history.from_plan_id / to_plan_id (ON DELETE RESTRICT). No index led by those
 * columns existed, so each delete would scan both tables whole. plan_features is served by its
 * primary key (plan_id, feature_key).
 *
 * All three columns are nullable, so each index is partial `IS NOT NULL`: the lookup's `=` implies
 * the predicate, so the planner still uses it, and rows without a plan stay out of it.
 * tests/Feature/Infrastructure/ForeignKeyIndexCoverageTest.php flags any foreign key left without one.
 *
 * Size (estimated, not measured): about 20 bytes a row; 10,000 customers ≈ 0.25 MB for tenants,
 * 100,000 history rows ≈ 2.2 MB per history index. One more index entry per plan change (rare,
 * staff only). `plans`, `tenants` and `tenant_history` are platform-wide tables (no site_id, no RLS).
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it).
 * Expand-only; down() drops what up() added and touches no data.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    /** @var array<string, array{string, string}> index name => [table, column] */
    private const INDEXES = [
        'tenants_plan_id_index' => ['tenants', 'plan_id'],
        'tenant_history_from_plan_id_index' => ['tenant_history', 'from_plan_id'],
        'tenant_history_to_plan_id_index' => ['tenant_history', 'to_plan_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $index => [$table, $column]) {
            $this->dropIfInvalid($index);

            DB::statement(sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s) WHERE %s IS NOT NULL',
                $index,
                $table,
                $column,
                $column,
            ));
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
