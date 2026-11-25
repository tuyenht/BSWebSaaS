<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Gỡ khỏi website lúc»: entries.expires_at and products.expires_at, the moment a live post, page or
 * product leaves the website by itself. NULL (every row today) means it stays until someone takes it
 * down. Stored UTC (timestamptz), entered and shown in the site's time zone. One per item, not per
 * language: being public belongs to the whole item.
 *
 * When the time comes, entries:unpublish-due and products:unpublish-due move the row from
 * `published` to `archived` («Gỡ khỏi website», content kept), the status every public list,
 * sitemap, search and count already reads, and the write purges the edge cache. The detail page
 * checks the time as well, so a visitor never sees it in the minute before the sweep.
 *
 * The CHECK keeps the end after the start: an item cannot leave the website before it went (or will
 * go) public. Added NOT VALID (no scan under the table lock; every row is NULL anyway) and validated
 * by the next migration, together with the due indexes.
 *
 * Expand-only: a nullable column without a default changes the catalog only (no table rewrite). Each
 * step that locks a table runs in its own short transaction with a 5 s lock wait: queued behind a
 * long transaction it gives up (the release stops, re-runnable) instead of holding every website's
 * writes behind it. Re-runnable: the columns and the constraints are each added only when missing.
 */
return new class extends Migration
{
    /** Each step commits on its own, with its own lock wait. */
    public $withinTransaction = false;

    /** @var array<string, string> table => its CHECK constraint */
    private const array CHECKS = [
        'entries' => 'entries_expires_after_publish',
        'products' => 'products_expires_after_publish',
    ];

    public function up(): void
    {
        foreach (self::CHECKS as $table => $check) {
            $this->briefly(fn () => DB::statement("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS expires_at timestamptz NULL"));

            $exists = DB::selectOne("SELECT 1 FROM pg_constraint WHERE conrelid = '{$table}'::regclass AND conname = ?", [$check]);
            if ($exists === null) {
                $this->briefly(fn () => DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$check} CHECK (expires_at IS NULL OR published_at IS NULL OR expires_at > published_at) NOT VALID"));
            }
        }
    }

    public function down(): void
    {
        foreach (self::CHECKS as $table => $check) {
            $this->briefly(fn () => DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$check}"));
            $this->briefly(fn () => DB::statement("ALTER TABLE {$table} DROP COLUMN IF EXISTS expires_at"));
        }
    }

    private function briefly(Closure $step): void
    {
        DB::transaction(function () use ($step): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $step();
        });
    }
};
