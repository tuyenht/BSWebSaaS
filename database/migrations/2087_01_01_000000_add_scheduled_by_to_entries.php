<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * entries.scheduled_by: the reviewer who approved a post under an approval workflow for a later
 * time («Khi nào đăng?» → «Hẹn giờ đăng» in the review inbox). Set only together with
 * status = 'scheduled'; NULL on every other row, and on a post scheduled without a workflow.
 *
 * The invariant it carries: only the workflow engine puts a governed post live. The approval does
 * not move the post's stage; when the time comes, entries:publish-due applies the stage's publishing
 * move through ApplyEntryWorkflowTransition AS this person, so their right is checked again then. A
 * post whose approver lost the right, whose flow changed, or whose approver is gone (this key goes
 * NULL) is never published by the sweep: it goes back to waiting, or is held and reported.
 *
 * Expand-only: a nullable column without a default (no table rewrite), then a foreign key to users
 * added NOT VALID and validated separately (every row is NULL, so the scan passes without blocking
 * writes for long). ON DELETE SET NULL, as for every soft reference to an account. A partial index
 * led by the column (`WHERE scheduled_by IS NOT NULL`) serves the parent-delete lookup and holds
 * only the few posts waiting for their time, built CONCURRENTLY. Each step that locks the table runs
 * in its own short transaction with a 5 s lock wait: queued behind a long transaction it gives up
 * (the release stops, re-runnable) instead of holding every website's post writes behind it.
 * Re-runnable: the column and the key are each added only when missing, and an index an interrupted
 * build left INVALID is dropped and built again.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run in a transaction; each other step commits on its own. */
    public $withinTransaction = false;

    private const string INDEX = 'entries_scheduled_by_index';

    public function up(): void
    {
        $this->briefly(fn () => DB::statement('ALTER TABLE entries ADD COLUMN IF NOT EXISTS scheduled_by bigint'));
        $exists = DB::selectOne("SELECT 1 FROM pg_constraint WHERE conrelid = 'entries'::regclass AND conname = 'entries_scheduled_by_foreign'");
        if ($exists === null) {
            $this->briefly(fn () => DB::statement('ALTER TABLE entries ADD CONSTRAINT entries_scheduled_by_foreign FOREIGN KEY (scheduled_by) REFERENCES users (id) ON DELETE SET NULL NOT VALID'));
        }
        $this->briefly(fn () => DB::statement('ALTER TABLE entries VALIDATE CONSTRAINT entries_scheduled_by_foreign'));

        $invalid = DB::selectOne('SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid', [self::INDEX]);
        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        }
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX.' ON entries (scheduled_by) WHERE scheduled_by IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        $this->briefly(fn () => DB::statement('ALTER TABLE entries DROP CONSTRAINT IF EXISTS entries_scheduled_by_foreign'));
        $this->briefly(fn () => DB::statement('ALTER TABLE entries DROP COLUMN IF EXISTS scheduled_by'));
    }

    private function briefly(Closure $step): void
    {
        DB::transaction(function () use ($step): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $step();
        });
    }
};
