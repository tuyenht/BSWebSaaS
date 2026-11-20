<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Media folders get sub-folders, at most two levels: a top-level folder may hold sub-folders, a
 * sub-folder holds none. media_folders.parent_id names the parent (NULL = top level).
 *
 *   - The parent is a folder of the same website, enforced by the database: the foreign key is
 *     composite, (site_id, parent_id) → (site_id, id), so even a write that bypasses the app (or a
 *     superuser seeding data) cannot hang one site's folder under another's. It needs the unique
 *     (site_id, id) it references. Deleting a folder deletes its sub-folders (CASCADE); their files,
 *     like the folder's own, drop back to no folder through media.folder_id's SET NULL.
 *   - (site_id, parent_id, position) lists a folder's sub-folders in order and, led by the key's
 *     columns, serves the parent-delete lookup of the composite key.
 *   - A folder is never its own parent (CHECK), and the depth stays at two levels: a row trigger
 *     refuses a parent that has a parent itself, and refuses a parent for a folder that has
 *     sub-folders, on INSERT and UPDATE alike. It is an AFTER trigger, so Postgres runs it at the end
 *     of the statement, when every row the statement wrote is there to be seen: one multi-row INSERT
 *     or UPDATE cannot chain three levels, nor close a cycle (10 → 11 → 10: each row's parent then
 *     has a parent), through rows that did not exist yet when an earlier row was checked. A change
 *     of id fires it too (a folder taking the id that sub-folders point at). Two people
 *     moving folders at once could each pass that check against the other's old state, so the
 *     trigger first takes the website's folder-tree lock (an advisory lock held to the end of the
 *     transaction, the same key the app takes before its own checks): the second writer waits, then
 *     checks, with a fresh snapshot, against what the first committed.
 *
 * Expand-only, on a table with a few rows per site: the new column is nullable without a default
 * (no rewrite); the two indexes are built CONCURRENTLY; the foreign key and the CHECK are added
 * NOT VALID, then validated (every existing row has parent_id NULL, so validation reads and passes).
 * Each step that locks the whole table runs in its own short transaction with a 5 s lock wait.
 * Re-runnable after a mid-run failure: an index an interrupted build left INVALID is dropped and
 * built again, and each constraint is added only when missing. down() removes all of it.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->briefly(fn () => DB::statement('ALTER TABLE media_folders ADD COLUMN IF NOT EXISTS parent_id bigint'));

        $this->index('media_folders_site_id_id_unique', 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS media_folders_site_id_id_unique ON media_folders (site_id, id)');
        $this->index('media_folders_site_id_parent_id_position_index', 'CREATE INDEX CONCURRENTLY IF NOT EXISTS media_folders_site_id_parent_id_position_index ON media_folders (site_id, parent_id, position)');

        $this->constraint('media_folders_site_id_id_unique', 'ALTER TABLE media_folders ADD CONSTRAINT media_folders_site_id_id_unique UNIQUE USING INDEX media_folders_site_id_id_unique');
        $this->constraint('media_folders_parent_fk', 'ALTER TABLE media_folders ADD CONSTRAINT media_folders_parent_fk FOREIGN KEY (site_id, parent_id) REFERENCES media_folders (site_id, id) ON DELETE CASCADE NOT VALID');
        $this->briefly(fn () => DB::statement('ALTER TABLE media_folders VALIDATE CONSTRAINT media_folders_parent_fk'));
        $this->constraint('media_folders_parent_not_self', 'ALTER TABLE media_folders ADD CONSTRAINT media_folders_parent_not_self CHECK (parent_id IS NULL OR parent_id <> id) NOT VALID');
        $this->briefly(fn () => DB::statement('ALTER TABLE media_folders VALIDATE CONSTRAINT media_folders_parent_not_self'));

        $this->briefly(fn () => DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.media_folders_two_levels() RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, pg_temp
            AS $fn$
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtextextended('media_folders:' || NEW.site_id::text, 0));
                IF EXISTS (SELECT 1 FROM public.media_folders p
                           WHERE p.site_id = NEW.site_id AND p.id = NEW.parent_id AND p.parent_id IS NOT NULL) THEN
                    RAISE EXCEPTION 'media folder %: its parent % is a sub-folder (folders have at most two levels)', NEW.id, NEW.parent_id
                        USING ERRCODE = '23514', CONSTRAINT = 'media_folders_two_levels';
                END IF;
                IF EXISTS (SELECT 1 FROM public.media_folders c
                           WHERE c.site_id = NEW.site_id AND c.parent_id = NEW.id) THEN
                    RAISE EXCEPTION 'media folder % has sub-folders, so it cannot go inside another folder (folders have at most two levels)', NEW.id
                        USING ERRCODE = '23514', CONSTRAINT = 'media_folders_two_levels';
                END IF;
                RETURN NULL;
            END;
            $fn$;

            DROP TRIGGER IF EXISTS media_folders_two_levels ON public.media_folders;
            CREATE TRIGGER media_folders_two_levels
                AFTER INSERT OR UPDATE OF parent_id, site_id, id ON public.media_folders
                FOR EACH ROW
                WHEN (NEW.parent_id IS NOT NULL)
                EXECUTE FUNCTION public.media_folders_two_levels();
            SQL));
    }

    public function down(): void
    {
        $this->briefly(fn () => DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS media_folders_two_levels ON public.media_folders;
            DROP FUNCTION IF EXISTS public.media_folders_two_levels();
            ALTER TABLE media_folders DROP CONSTRAINT IF EXISTS media_folders_parent_not_self;
            ALTER TABLE media_folders DROP CONSTRAINT IF EXISTS media_folders_parent_fk;
            ALTER TABLE media_folders DROP CONSTRAINT IF EXISTS media_folders_site_id_id_unique;
            SQL));
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS media_folders_site_id_parent_id_position_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS media_folders_site_id_id_unique');
        $this->briefly(fn () => DB::statement('ALTER TABLE media_folders DROP COLUMN IF EXISTS parent_id'));
    }

    /** Builds an index, first dropping one an interrupted CONCURRENTLY build left INVALID. */
    private function index(string $name, string $create): void
    {
        $invalid = DB::selectOne('SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid', [$name]);
        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
        DB::statement($create);
    }

    private function constraint(string $name, string $add): void
    {
        $exists = DB::selectOne('SELECT 1 FROM pg_constraint WHERE conrelid = to_regclass(?) AND conname = ?', ['media_folders', $name]);
        if ($exists === null) {
            $this->briefly(fn () => DB::statement($add));
        }
    }

    /**
     * One step that locks the whole table, in its own transaction with a short lock wait: queued
     * behind a long transaction it gives up after 5 s (the release stops, re-runnable) instead of
     * holding every website's folder list behind it.
     */
    private function briefly(Closure $step): void
    {
        DB::transaction(function () use ($step): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $step();
        });
    }
};
