<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * forms gets UNIQUE (site_id, id), the key a same-site composite foreign key aims at: the next
 * migration's form_placements references forms (site_id, id), so a row can never pair one site with
 * another site's form, even written past the app (a superuser, a seeder). id is the primary key, so
 * every existing row already satisfies it; nothing is rewritten.
 *
 * Numbered before 2082 (create_form_placements_table), which needs it. forms is small (at most a
 * few dozen rows a site), but it is read by every lead a visitor sends, so the index is built
 * CONCURRENTLY and then attached as the constraint (UNIQUE USING INDEX), which only takes a brief
 * lock, in its own transaction with a 5 s lock wait. Re-runnable after a mid-run failure: an index
 * an interrupted build left INVALID is dropped and built again, and the constraint is added only
 * when missing. down() removes both (after 2082's down(), which drops the table that references it).
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const string NAME = 'forms_site_id_id_unique';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $invalid = DB::selectOne('SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid', [self::NAME]);
        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::NAME);
        }
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS '.self::NAME.' ON forms (site_id, id)');

        $exists = DB::selectOne('SELECT 1 FROM pg_constraint WHERE conrelid = to_regclass(?) AND conname = ?', ['forms', self::NAME]);
        if ($exists === null) {
            DB::transaction(function (): void {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::statement('ALTER TABLE forms ADD CONSTRAINT '.self::NAME.' UNIQUE USING INDEX '.self::NAME);
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
            // dropping the constraint drops its index too
            DB::statement('ALTER TABLE forms DROP CONSTRAINT IF EXISTS '.self::NAME);
        });
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::NAME);
    }
};
