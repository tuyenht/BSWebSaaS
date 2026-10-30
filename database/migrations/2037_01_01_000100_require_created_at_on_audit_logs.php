<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every audit row has a time. The activity log pages by (created_at, id) and the hourly counts
 * group by created_at; a NULL would sort outside every page and every hour, so the row would
 * silently never be shown or counted. AuditService always writes now(); this makes it a rule.
 *
 * Without rewriting or blocking the table: a NOT VALID check is added under a 5s lock_timeout, then
 * validated, which scans the table under a lock that lets writes continue, and SET NOT NULL then
 * trusts the validated check instead of scanning again. The check is dropped afterwards. Each step
 * runs in its own short transaction, so the long scan never holds the exclusive lock. A NULL row
 * makes the validation fail loudly, before anything is changed for good.
 *
 * Re-runnable: a column already NOT NULL is left alone; a check left by an interrupted run is
 * reused. down() lets the column be NULL again.
 */
return new class extends Migration
{
    /** VALIDATE must run outside the transaction that took the exclusive lock. */
    public $withinTransaction = false;

    private const string CHECK = 'audit_logs_created_at_not_null';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->nullable()) {
            return;
        }

        $exists = DB::selectOne("SELECT 1 FROM pg_constraint WHERE conrelid = 'audit_logs'::regclass AND conname = ?", [self::CHECK]);
        if ($exists === null) {
            DB::transaction(function (): void {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::statement('ALTER TABLE audit_logs ADD CONSTRAINT '.self::CHECK.' CHECK (created_at IS NOT NULL) NOT VALID');
            });
        }

        DB::statement('ALTER TABLE audit_logs VALIDATE CONSTRAINT '.self::CHECK);

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('ALTER TABLE audit_logs ALTER COLUMN created_at SET NOT NULL');
            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT '.self::CHECK);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('ALTER TABLE audit_logs ALTER COLUMN created_at DROP NOT NULL');
        });
    }

    private function nullable(): bool
    {
        return DB::selectOne(
            "SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'audit_logs' AND column_name = 'created_at' AND is_nullable = 'YES'",
        ) !== null;
    }
};
