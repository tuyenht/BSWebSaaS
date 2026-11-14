<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * site_notifications.search_text: the search box of the Notifications page. One line per notice,
 * written with the notice: the facts a person searches by (who acted, the post's title, the lead's
 * name or phone or email, the domains, the support person, member and reason, the return note),
 * folded by VietnameseFold::text() (lower case, no accents, đ → d) and cut at 512 characters
 * (NotificationSearchText). The fixed wording of the sentence is not in it: that is built when shown,
 * in the reader's language, from `kind` + `data`.
 *
 * Derived only: it is rebuilt from `data` at any time (`php artisan notifications:search-text`), so a
 * change that rewrites or clears `data` rewrites it too (SiteNotification's saving hook).
 * NULL = not filled yet: the backfill fills those rows after the deploy, site by site; '' = a notice
 * with nothing to search (a new-device sign-in).
 *
 * No index. A search walks the reader's own notices newest first through site_notifications_feed
 * (site_id, user_id, id DESC), whose leakproof conditions stay index conditions under RLS, and checks
 * `search_text LIKE '%word%'` row by row (LIKE is not leakproof, so no index could serve it for
 * app_user anyway), stopping once enough have matched. Its worst case, a word found nowhere, reads the
 * reader's whole 90-day slice: measured on 11.6 M notices as app_user under RLS (median of 5 warm runs,
 * plans/reports/notifications-search-measure-261114/): see NotificationFeed::matches().
 *
 * RLS: a column on a table that already has ENABLE + FORCE and its policy; nothing to add. Grants are
 * on the whole table (0000_01_01_000100), so the new column carries them.
 *
 * Expand-only: a nullable column with no default changes the catalog only, no row is rewritten. The
 * ALTER takes a brief ACCESS EXCLUSIVE lock and gives up after 5s rather than queue a visitor's
 * contact form (its lead notices) behind a long transaction; IF [NOT] EXISTS makes a re-run safe.
 * Adding or dropping a column leaves PgBouncer holding plans that no longer fit: restart it after
 * up() and after down() (runbook). down() drops the column; nothing is lost, the text is rebuilt
 * from `data`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->briefly('ALTER TABLE site_notifications ADD COLUMN IF NOT EXISTS search_text text NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->briefly('ALTER TABLE site_notifications DROP COLUMN IF EXISTS search_text');
    }

    private function briefly(string $statement): void
    {
        DB::transaction(function () use ($statement): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement($statement);
        });
    }
};
