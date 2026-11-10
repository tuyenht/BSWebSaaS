<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A customer's history (tenant_history) shows every kind of change the customer page lists
 * (design 03, "Lịch sử"), not only a change of plan:
 *
 *   - customer_created: the customer was created (to_plan_id: the plan it started on, if any);
 *   - site_created: one of its websites was created;
 *   - feature_overridden: one of its websites was given its own value for a feature, or had it
 *     taken away (the plan decides again);
 *   - suspended / resumed: the customer was suspended or resumed.
 *
 * meta (JSONB, NULL for plan_changed / customer_created / suspended / resumed) holds what the
 * sentence names and the row has no column for, as it was at the time: the website (its ulid and
 * name, never its bigint id) and, for an override, the feature key and its new value (null when
 * cleared). Only shown, never filtered or sorted on, so it stays in meta (graduation rule).
 * Adding a nullable column with no default only changes the catalog: no table rewrite.
 *
 * The kind CHECK (D13) is swapped for one listing the new kinds: dropped and re-added NOT VALID,
 * then validated — the house pattern for a CHECK on an existing table (2033). The lock gives up
 * after 5s rather than queue the customer page behind it. Re-runnable after a mid-run failure
 * (DROP IF EXISTS, ADD COLUMN IF NOT EXISTS).
 *
 * down() deletes the rows of these kinds (they would break the old CHECK; as the table owner,
 * which app_user is not), restores the old list and drops meta.
 */
return new class extends Migration
{
    private const array KINDS = ['plan_changed'];

    private const array ADDED = ['customer_created', 'site_created', 'feature_overridden', 'suspended', 'resumed'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE tenant_history ADD COLUMN IF NOT EXISTS meta jsonb NULL');
        $this->listKinds([...self::KINDS, ...self::ADDED]);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::table('tenant_history')->whereIn('kind', self::ADDED)->delete();
        $this->listKinds(self::KINDS);
        DB::statement('ALTER TABLE tenant_history DROP COLUMN IF EXISTS meta');
    }

    /** @param list<string> $kinds */
    private function listKinds(array $kinds): void
    {
        $list = implode(', ', array_map(fn (string $kind): string => "'{$kind}'", $kinds));

        DB::statement('ALTER TABLE tenant_history DROP CONSTRAINT IF EXISTS tenant_history_kind_check');
        DB::statement("ALTER TABLE tenant_history ADD CONSTRAINT tenant_history_kind_check CHECK (kind IN ({$list})) NOT VALID");
        DB::statement('ALTER TABLE tenant_history VALIDATE CONSTRAINT tenant_history_kind_check');
    }
};
