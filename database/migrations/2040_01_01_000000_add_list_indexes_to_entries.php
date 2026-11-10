<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the posts and pages list (Admin\Livewire\Entries\Index), which now filters, sorts and
 * pages in the database. Each one serves an order of the list as an index walk, so a page costs the
 * rows it shows (plus the rows a filter skips), however large the website:
 *
 *   - live posts by last edit, by publish date, within a status (the status order and the status
 *     groups), and by author (the author order, and the skip scan that lists the authors);
 *   - the Trash of each type by last edit;
 *   - titles in the default language, in Vietnamese alphabetical order (the name order);
 *   - live top-level pages in their website order (the top of the page tree; the pages below
 *     a parent are found on the existing (site_id, parent_id) index).
 *
 * The title index starts with (site_id, locale), so the plain (site_id, locale) index of
 * entry_translations is dropped: every lookup it served, the longer index serves, and each index
 * is a write cost. down() builds it again.
 *
 * Every index leads with site_id. The partial predicates are written exactly as the list sends
 * them: the type and the soft-delete test are literals in its SQL, so a generic plan (PgBouncer
 * reuses prepared statements) still matches them. The date keys are NULLS FIRST so that the list's
 * two directions, "newest first, never-dated last" and "oldest first, never-dated first", are one
 * forward and one backward walk.
 *
 * The keys are ascending although the list reads newest first: Postgres scans them backward just
 * as fast, and new rows land on the right edge, where page splits leave full pages.
 *
 * The tie-break is the bigint id: the name order walks entry_translations, which carries the
 * entry's id and not its ulid, and an id is 8 bytes where a ulid is 27. The id never leaves the
 * server: a page cursor holding it is encrypted.
 *
 * No index for the views order (a top-N sort over the live posts) or for the search (ILIKE is not
 * leakproof, so under RLS no index could serve it: the list walks rows in the order shown).
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it).
 * Expand-only; down() drops what up() added.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const string LIVE_POST = "WHERE deleted_at IS NULL AND type = 'post'";

    /** @var array<string, array{0: string, 1: string}> index name => [table, definition after the table] */
    private const array INDEXES = [
        'entries_post_updated_idx' => ['entries', '(site_id, updated_at NULLS FIRST, id) '.self::LIVE_POST],
        'entries_post_published_idx' => ['entries', '(site_id, published_at NULLS FIRST, id) '.self::LIVE_POST],
        'entries_post_status_idx' => ['entries', '(site_id, status, updated_at NULLS FIRST, id) '.self::LIVE_POST],
        'entries_post_author_idx' => ['entries', '(site_id, author_id, updated_at NULLS FIRST, id) '.self::LIVE_POST],
        'entries_trash_idx' => ['entries', '(site_id, type, updated_at NULLS FIRST, id) WHERE deleted_at IS NOT NULL'],
        'entries_page_top_idx' => ['entries', "(site_id, position, id) WHERE deleted_at IS NULL AND type = 'page' AND parent_id IS NULL"],
        'entry_translations_title_icu_idx' => ['entry_translations', '(site_id, locale, title COLLATE "vi-VN-x-icu", entry_id)'],
    ];

    /** Covered by entry_translations_title_icu_idx, which starts with the same two columns. */
    private const string COVERED = 'entry_translations_site_id_locale_index';

    public function up(): void
    {
        foreach (self::INDEXES as $index => [$table, $definition]) {
            $this->dropIfInvalid($index);

            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON {$table} {$definition}");
        }

        // only once the longer index is built and valid, so lookups by website and language
        // always have one of the two
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::COVERED);
    }

    public function down(): void
    {
        $this->dropIfInvalid(self::COVERED);
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::COVERED.' ON entry_translations (site_id, locale)');

        foreach (array_keys(self::INDEXES) as $index) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }

    private function dropIfInvalid(string $index): void
    {
        $invalid = DB::selectOne(
            'SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid',
            [$index],
        );

        if ($invalid !== null) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }
};
