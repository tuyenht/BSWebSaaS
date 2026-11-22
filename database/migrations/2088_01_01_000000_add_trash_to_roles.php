<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A trash for the roles a website makes itself (custom roles): deleting one moves it to the trash
 * for 30 days (`roles:purge-trash`), and it can be brought back with its id, its permissions and
 * the role it was copied from. Expand-only: the new column is nullable, so no row is rewritten and
 * the code running before this deploy keeps working (it still deletes for good, and never writes a
 * trashed row).
 *
 *   deleted_at — set when a custom role goes to the trash. The model's scope hides such a row from
 *       every reader, the permission catalog included, so a role in the trash grants nothing and can
 *       be given to nobody. `roles` stays platform-wide (no RLS, list D6): every query on it names
 *       the site explicitly, and raw joins on `roles` add `deleted_at IS NULL` themselves (an arch
 *       test checks that).
 *   roles_site_id_name_guard_name_live_unique — one LIVE role per (site, name, guard), as before, but
 *       a role in the trash no longer holds its name: a new role may take it, and restoring a role
 *       whose name was taken meanwhile is refused by the action. Built first; only then is the old
 *       full unique constraint dropped, so the table is never without a unique on its live rows.
 *       spatie's `findByName`/`findOrCreate` read through the model's scope, so they agree with it.
 *   roles_site_id_deleted_at_index — the trash of one site, newest first, and the daily purge of the
 *       rows past 30 days. Partial on the trashed rows only: a handful per site.
 *
 * Size (estimated, not measured): six system roles per site plus at most 50 custom ones (a code
 * constant), so about 60,000 rows at 10,000 sites; each index is tens of bytes a row.
 *
 * Grants: the app role's rights are on whole tables (0000_01_01_000100), and a column added to a
 * table carries them, so app_user reads and writes deleted_at with nothing granted here.
 *
 * Locks: the ALTER is one short transaction that gives up after 5s rather than queue requests
 * behind it (every request reads `roles`). CONCURRENTLY forbids a transaction, so a mid-run failure
 * must be safely re-runnable: an index an interrupted build left INVALID is dropped and built again
 * (IF NOT EXISTS alone would keep it). The constraint drop takes the table lock for an instant,
 * under the same 5s lock_timeout.
 *
 * down() stops if the trash is not empty: without the column those roles would come back into
 * service, and the full unique could not hold a trashed role beside a live one with the same name.
 * Empty the trash first (`roles:purge-trash --days=0`). Then it builds the full unique again
 * CONCURRENTLY, turns it back into the constraint 0003 made, and drops the rest; the trash is checked
 * again under the table lock right before the column goes.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const string OLD_UNIQUE = 'roles_site_id_name_guard_name_unique';

    private const string LIVE_UNIQUE = 'roles_site_id_name_guard_name_live_unique';

    private const string TRASH_INDEX = 'roles_site_id_deleted_at_index';

    /** @var array<string, string> index name → definition, built in this order */
    private const array INDEXES = [
        self::LIVE_UNIQUE => 'UNIQUE INDEX CONCURRENTLY IF NOT EXISTS '.self::LIVE_UNIQUE.' ON roles (site_id, name, guard_name) WHERE deleted_at IS NULL',
        self::TRASH_INDEX => 'INDEX CONCURRENTLY IF NOT EXISTS '.self::TRASH_INDEX.' ON roles (site_id, deleted_at) WHERE deleted_at IS NOT NULL',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->briefly(fn () => DB::statement('ALTER TABLE roles ADD COLUMN IF NOT EXISTS deleted_at timestamp(0) with time zone NULL'));

        foreach (self::INDEXES as $name => $definition) {
            $this->dropIfInvalid($name);
            DB::statement('CREATE '.$definition);
        }

        $this->briefly(fn () => DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS '.self::OLD_UNIQUE));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->assertTrashEmpty();

        if (! $this->constraintExists(self::OLD_UNIQUE)) {
            $this->dropIfInvalid(self::OLD_UNIQUE);
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS '.self::OLD_UNIQUE.' ON roles (site_id, name, guard_name)');
            $this->briefly(fn () => DB::statement('ALTER TABLE roles ADD CONSTRAINT '.self::OLD_UNIQUE.' UNIQUE USING INDEX '.self::OLD_UNIQUE));
        }

        foreach (array_reverse(array_keys(self::INDEXES)) as $name) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }

        $this->briefly(function (): void {
            // checked again under the table lock: a role sent to the trash during the index work
            // would otherwise come back into service when the column goes
            DB::statement('LOCK TABLE roles IN ACCESS EXCLUSIVE MODE');
            $this->assertTrashEmpty();
            DB::statement('ALTER TABLE roles DROP COLUMN IF EXISTS deleted_at');
        });
    }

    private function dropIfInvalid(string $index): void
    {
        $invalid = DB::selectOne(
            'SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid',
            [$index],
        );

        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$index);
        }
    }

    /** One short transaction that gives up on a table lock after 5s instead of queueing requests. */
    private function assertTrashEmpty(): void
    {
        if ($this->hasDeletedAt() && DB::selectOne('SELECT 1 FROM roles WHERE deleted_at IS NOT NULL LIMIT 1') !== null) {
            throw new RuntimeException('roles: the trash still holds roles. Empty it first (php artisan roles:purge-trash --days=0), then roll back.');
        }
    }

    private function briefly(Closure $step): void
    {
        DB::transaction(function () use ($step): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $step();
        });
    }

    private function constraintExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM pg_constraint WHERE conrelid = \'roles\'::regclass AND conname = ?',
            [$name],
        ) !== null;
    }

    private function hasDeletedAt(): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = \'roles\' AND column_name = \'deleted_at\'',
        ) !== null;
    }
};
