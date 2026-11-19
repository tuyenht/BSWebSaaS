<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The words that describe a post's or page's featured image («Mô tả ảnh» in the editor's «Ảnh đại
 * diện» card), per language: what Google and a screen reader take from the picture on the website.
 *
 *   entry_translations — cover_alt: up to 250 characters, null when not written (the website then
 *       falls back to the picture's own description in the media library, then to the title).
 *
 * It belongs to the post or page, not to the picture: the same picture may stand for different
 * things on two posts, and each language says it in its own words. The picture itself (shared by
 * every language) stays on `entries.cover_media_id`.
 *
 * Expand-only. `ADD COLUMN … NULL` with no default is metadata-only on PostgreSQL 17 (no table
 * rewrite), done in a short transaction that gives up on the table lock after 5s instead of
 * queueing requests behind it. Guarded with IF NOT EXISTS, so a repeated run does nothing. No
 * index and no CHECK: it is read with the row and never filtered or sorted, and the length is held
 * by the column type and the editor's validation. A new column leaves PgBouncer holding plans that
 * no longer fit: the release needs its PgBouncer step.
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

        $this->briefly(fn () => DB::statement('ALTER TABLE entry_translations ADD COLUMN IF NOT EXISTS cover_alt varchar(250) NULL'));
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
                "select 1 as found from information_schema.columns where table_schema = current_schema() and table_name = 'entry_translations' and column_name = 'cover_alt'"
            );
            if ($exists === null) {
                return;
            }
            // Migrations run on the privileged connection, which row-level security does not limit: every site counts.
            $count = DB::table('entry_translations')->whereNotNull('cover_alt')->count();
            if ($count > 0) {
                throw new RuntimeException(
                    "Refused: {$count} row(s) of entry_translations.cover_alt still hold text an editor typed. "
                    .'Clear those values on purpose first (set them to NULL), then roll back.'
                );
            }
            DB::statement('ALTER TABLE entry_translations DROP COLUMN IF EXISTS cover_alt');
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
