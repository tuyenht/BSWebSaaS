<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two optional fields a member fills in on their own profile ("Tài khoản của tôi" › Hồ sơ), one
 * value for every website they belong to (users is a platform-wide table, D6):
 *  - users.job_title varchar(80): what they do ("Trưởng phòng kinh doanh"). Free text the member
 *    typed; trimmed and checked in code.
 *  - users.phone varchar(16): one international form (E.164, "+84901234567"), normalised in code
 *    from what the member typed. Personal data: shown to the member, to whoever manages members on a
 *    website they belong to, and to platform support; never on a storefront.
 *
 * Plain varchar, not citext: neither is compared, sorted or unique. No index: nothing filters or
 * sorts on either yet; both are read with the member's own row.
 *
 * users_phone_e164_check holds the stored form whatever path writes it (`+`, a first digit 1-9,
 * 8-15 digits in all). It is added NOT VALID in the step that adds the column, then validated on its
 * own: the column is new, so every row is NULL and nothing can fail, but a plain ADD CONSTRAINT would
 * still scan users under the ACCESS EXCLUSIVE lock that every sign-in waits behind; VALIDATE scans
 * under SHARE UPDATE EXCLUSIVE, which lets reads and writes through.
 *
 * Expand-only: a nullable column with no default is a catalog change, so no row is rewritten and
 * nothing is backfilled. Grants: the app role's rights are on whole tables (0000_01_01_000100), and
 * a column added to a table carries them.
 *
 * Locks: each step runs in its own short transaction that gives up after 5s rather than queue
 * sign-ins behind it. Guarded (IF [NOT] EXISTS, the constraint looked up first), so a run that stops
 * half way can be repeated.
 *
 * down() never drops what a member typed: while any row holds a job title or a phone number it
 * refuses and says how many, and drops nothing. Clear those values on purpose first, then roll back.
 */
return new class extends Migration
{
    /** Each step runs in its own short transaction (briefly()). */
    public $withinTransaction = false;

    private const string CHECK = 'users_phone_e164_check';

    /** column => type */
    private const array COLUMNS = [
        'job_title' => 'varchar(80)',
        'phone' => 'varchar(16)',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->briefly(function (): void {
            foreach (self::COLUMNS as $column => $type) {
                DB::statement("ALTER TABLE users ADD COLUMN IF NOT EXISTS {$column} {$type} NULL");
            }
            if (! $this->hasConstraint(self::CHECK)) {
                DB::statement('ALTER TABLE users ADD CONSTRAINT '.self::CHECK." CHECK (phone ~ '^\\+[1-9][0-9]{7,14}$') NOT VALID");
            }
        });

        $this->briefly(fn () => DB::statement('ALTER TABLE users VALIDATE CONSTRAINT '.self::CHECK));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // One transaction holding the table, so no value can be written between the look and the drop.
        $this->briefly(function (): void {
            DB::statement('LOCK TABLE users IN ACCESS EXCLUSIVE MODE');
            $held = [];
            foreach (array_keys(self::COLUMNS) as $column) {
                if (! $this->hasColumn($column)) {
                    continue;
                }
                $count = DB::table('users')->whereNotNull($column)->count();
                if ($count > 0) {
                    $held[] = "{$count} row(s) of users.{$column}";
                }
            }
            if ($held !== []) {
                throw new RuntimeException(
                    'Refused: '.implode(' and ', $held).' still hold what a member typed. '
                    .'Clear those values on purpose first (set them to NULL), then roll back.'
                );
            }

            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS '.self::CHECK);
            foreach (array_keys(self::COLUMNS) as $column) {
                DB::statement("ALTER TABLE users DROP COLUMN IF EXISTS {$column}");
            }
        });
    }

    private function hasColumn(string $column): bool
    {
        return DB::selectOne(
            "select 1 as found from information_schema.columns where table_schema = current_schema() and table_name = 'users' and column_name = ?",
            [$column],
        ) !== null;
    }

    private function hasConstraint(string $name): bool
    {
        return DB::selectOne(
            "select 1 as found from pg_constraint where conname = ? and conrelid = 'users'::regclass",
            [$name],
        ) !== null;
    }

    private function briefly(Closure $step): void
    {
        DB::transaction(function () use ($step): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $step();
        });
    }
};
