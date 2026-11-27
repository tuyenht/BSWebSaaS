<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Từ khóa muốn lên top» in a post's or page's «Hiện thế nào trên Google» card, per language: the
 * words an editor wants this language of the page to be found by on Google.
 *
 *   entry_translations — focus_keyword: up to 120 characters, null when not written.
 *
 * It is the editor's own note for writing the page: the website never shows it, sends it to search
 * engines or puts it in the page's head. It lives on the translation row because each language
 * is found by its own words, and a pending change, a version and a copy carry it per language like
 * the rest of the text.
 *
 * Expand-only. `ADD COLUMN … NULL` with no default is metadata-only on PostgreSQL 17 (no table
 * rewrite), done in a short transaction that gives up on the table lock after 5s instead of
 * queueing requests behind it. Guarded with IF NOT EXISTS, so a repeated run does nothing. No
 * index and no CHECK: it is read with the row and never filtered, sorted or compared in SQL (so a
 * plain varchar, not citext), and the length is held by the column type and the editor's
 * validation. A new column leaves PgBouncer holding plans that no longer fit: the release needs its
 * PgBouncer step.
 *
 * Grants: the app role's rights are on whole tables (0000_01_01_000100), and a column added to a
 * table carries them. RLS stays on `site_id`; nothing changes for it.
 *
 * down() never drops words an editor typed: while a row holds some it refuses, naming the count,
 * and drops nothing (the same rule as the bylines' migration, 2060).
 */
return new class extends Migration
{
    /** The table changes in a short transaction of its own. */
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->briefly(fn () => DB::statement('ALTER TABLE entry_translations ADD COLUMN IF NOT EXISTS focus_keyword varchar(120) NULL'));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // One transaction holding the table, so no words can be written between the look and the drop.
        $this->briefly(function (): void {
            DB::statement('LOCK TABLE entry_translations IN ACCESS EXCLUSIVE MODE');
            $exists = DB::selectOne(
                "select 1 as found from information_schema.columns where table_schema = current_schema() and table_name = 'entry_translations' and column_name = 'focus_keyword'"
            );
            if ($exists === null) {
                return;
            }
            // Migrations run on the privileged connection, which row-level security does not limit: every site counts.
            $count = DB::table('entry_translations')->whereNotNull('focus_keyword')->count();
            if ($count > 0) {
                throw new RuntimeException(
                    "Refused: {$count} row(s) of entry_translations.focus_keyword still hold words an editor typed. "
                    .'Clear those values on purpose first (set them to NULL), then roll back.'
                );
            }
            DB::statement('ALTER TABLE entry_translations DROP COLUMN IF EXISTS focus_keyword');
        });
    }

    /** One short transaction that gives up on a table lock after 5s instead of queueing requests. */
    private function briefly(Closure $step): void
    {
        DB::transaction(function () use ($step): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $step();
        });
    }
};
