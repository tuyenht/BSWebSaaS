<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The media library's «Người tạo» filter: `created_by IN (…)` on the live files, newest first by
 * (created_at, ulid), the keyset cursor `(created_at, ulid) < (?, ?)`. The only list index so far is
 * (site_id, created_at), so a person with 100 of a site's million files had the list walk the
 * site's rows backwards and drop the others one by one, and the count read every row of the site.
 *
 * This index leads with site_id, then the uploader, then the list's own order: one index range per
 * chosen person, already sorted (several people: a merge of short ranges), the cursor an index
 * condition and the count one range. Its tie-break is ulid, the column the list orders and pages
 * by (MediaLibraryDirectory::page), so no sort step is left; an id there would leave one.
 *
 * Partial on the live files (`deleted_at IS NULL`): the live list is the hot path; the Trash is
 * small and short-lived (purged after 30 days) and keeps the (site_id, created_at) index.
 *
 * As app_user under RLS the plan holds: bigint, timestamptz and char(26) comparisons and IS NULL
 * are leakproof, so they stay index conditions; tests/Feature/Admin/Media/MediaCreatorIndexTest.php
 * plans the list's real statements that way.
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it).
 * Expand-only; down() drops what up() added.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const string INDEX = 'media_site_creator_created_idx';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $invalid = DB::selectOne(
            'SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid',
            [self::INDEX],
        );
        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX
            .' ON media (site_id, created_by, created_at DESC, ulid DESC) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
    }
};
