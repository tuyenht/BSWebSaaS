<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * media.deleted_by: who sent a file to the Trash (NULL = still live, or trashed before this column
 * existed, or by no signed-in user). Someone who may delete only the files they uploaded takes back
 * out of the Trash only the ones they put there themselves.
 *
 * Expand-only: a nullable column without a default (no table rewrite), then a foreign key to users
 * added NOT VALID and validated separately (every row is NULL, so the scan passes without blocking
 * writes for long). ON DELETE SET NULL, as for every soft reference to an account. Accounts are
 * never hard-deleted (ForeignKeyIndexCoverageTest lists the users children), so the key needs no
 * index of its own. Each step that locks a table runs in its own short transaction with a 5 s lock
 * wait: queued behind a long transaction it gives up (the release stops, re-runnable) instead of
 * holding every website's media writes behind it. Re-runnable: the column and the key are each
 * added only when missing.
 */
return new class extends Migration
{
    /** Each step commits on its own, so validating the key never holds the ADD's stronger lock. */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->briefly(fn () => DB::statement('ALTER TABLE media ADD COLUMN IF NOT EXISTS deleted_by bigint'));
        $exists = DB::selectOne("SELECT 1 FROM pg_constraint WHERE conrelid = 'media'::regclass AND conname = 'media_deleted_by_foreign'");
        if ($exists === null) {
            $this->briefly(fn () => DB::statement('ALTER TABLE media ADD CONSTRAINT media_deleted_by_foreign FOREIGN KEY (deleted_by) REFERENCES users (id) ON DELETE SET NULL NOT VALID'));
        }
        $this->briefly(fn () => DB::statement('ALTER TABLE media VALIDATE CONSTRAINT media_deleted_by_foreign'));
    }

    public function down(): void
    {
        $this->briefly(fn () => DB::statement('ALTER TABLE media DROP CONSTRAINT IF EXISTS media_deleted_by_foreign'));
        $this->briefly(fn () => DB::statement('ALTER TABLE media DROP COLUMN IF EXISTS deleted_by'));
    }

    private function briefly(Closure $step): void
    {
        DB::transaction(function () use ($step): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $step();
        });
    }
};
