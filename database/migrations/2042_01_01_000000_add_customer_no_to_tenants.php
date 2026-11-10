<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every customer (tenants, a GLOBAL table — D6) gets a number of its own, `customer_no`, that
 * staff read and quote: the platform console shows it as a code such as "KH-000123". Only the
 * number is stored; the prefix and the zero padding come from config/customers.php, so another
 * brand shows its own code without touching the data (white-label).
 *
 *   - The number comes from its own sequence, tenants_customer_no_seq, owned by the column.
 *   - Customers that exist now are numbered oldest first (created_at, then id), soft-deleted ones
 *     too, so a number is never handed out twice; the sequence then continues after the highest.
 *   - New customers draw the next number from the column DEFAULT, so code that inserts customers
 *     without naming the column (the code running while this deploys) keeps working.
 *   - NOT NULL and UNIQUE close it.
 *
 * The column, the numbering and the default happen in one transaction: from the ADD COLUMN on,
 * the table is locked, so no customer can be inserted between the numbering and the default and
 * come out without a number. tenants holds one row per customer (thousands, not millions), so the
 * lock lasts milliseconds once held. While its request waits for the lock, requests that arrive
 * after it queue behind it; lock_timeout caps that wait at 5s, after which the migration gives up
 * (and can be run again) rather than hold them longer.
 *
 * The UNIQUE index is built CONCURRENTLY (an existing table), which forbids a transaction, so the
 * migration is re-runnable after a mid-run failure: IF [NOT] EXISTS throughout, only customers
 * without a number are numbered, and an index an interrupted build left INVALID is dropped and
 * built again. down() drops the constraint, the column and the sequence.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const string SEQUENCE = 'tenants_customer_no_seq';

    private const string UNIQUE = 'tenants_customer_no_unique';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('CREATE SEQUENCE IF NOT EXISTS '.self::SEQUENCE.' AS bigint');
            DB::statement('ALTER TABLE tenants ADD COLUMN IF NOT EXISTS customer_no bigint NULL');

            $this->numberUnnumbered('tenants', self::SEQUENCE);

            DB::statement('ALTER SEQUENCE '.self::SEQUENCE.' OWNED BY tenants.customer_no');
            DB::statement("ALTER TABLE tenants ALTER COLUMN customer_no SET DEFAULT nextval('".self::SEQUENCE."')");
            DB::statement('ALTER TABLE tenants ALTER COLUMN customer_no SET NOT NULL');
        });

        $this->dropIfInvalid(self::UNIQUE);
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS '.self::UNIQUE.' ON tenants (customer_no)');

        if (! $this->hasConstraint(self::UNIQUE)) {
            DB::transaction(function (): void {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::statement('ALTER TABLE tenants ADD CONSTRAINT '.self::UNIQUE.' UNIQUE USING INDEX '.self::UNIQUE);
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS '.self::UNIQUE);
            DB::statement('DROP INDEX IF EXISTS '.self::UNIQUE);
            DB::statement('ALTER TABLE tenants DROP COLUMN IF EXISTS customer_no');
            DB::statement('DROP SEQUENCE IF EXISTS '.self::SEQUENCE);
        });
    }

    /**
     * Numbers the rows of $table that have no customer_no yet, oldest first (created_at, then id),
     * after the highest number already given, and moves $sequence on so the next number follows
     * the highest one (1 on an empty table). up() runs it on tenants; it takes the names so a test
     * can run the same SQL on a scratch copy and never has to drop and re-add the live column.
     */
    public function numberUnnumbered(string $table, string $sequence): void
    {
        DB::statement(<<<SQL
            UPDATE {$table} t
            SET customer_no = n.base + n.rank
            FROM (
                SELECT id,
                       row_number() OVER (ORDER BY created_at, id) AS rank,
                       (SELECT COALESCE(MAX(customer_no), 0) FROM {$table}) AS base
                FROM {$table}
                WHERE customer_no IS NULL
            ) n
            WHERE t.id = n.id
            SQL);

        DB::statement(
            "SELECT setval('{$sequence}', GREATEST(COALESCE(MAX(customer_no), 0), 1), MAX(customer_no) IS NOT NULL) FROM {$table}"
        );
    }

    private function hasConstraint(string $name): bool
    {
        return DB::selectOne(
            "SELECT 1 FROM pg_constraint WHERE conname = ? AND conrelid = 'tenants'::regclass",
            [$name],
        ) !== null;
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
