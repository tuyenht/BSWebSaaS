<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a media folder (the library's "Xóa thư mục") makes Postgres find the folder's files with
 * `WHERE folder_id = $1` to set them back to no folder. That lookup carries no site_id and bypasses
 * RLS, so the `(site_id, folder_id, position)` index cannot serve it and every site's media rows
 * would be scanned inside the deleting request's transaction. This index leads with folder_id.
 * Most files sit in no folder, so it is partial `WHERE folder_id IS NOT NULL`: the lookup's `=`
 * implies the predicate, so the planner still uses it, and unfiled rows stay out of it.
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it).
 * Expand-only; down() drops what up() added.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const INDEX = 'media_folder_id_index';

    public function up(): void
    {
        $invalid = DB::selectOne(
            'SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid',
            [self::INDEX],
        );
        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX.' ON media (folder_id) WHERE folder_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
    }
};
