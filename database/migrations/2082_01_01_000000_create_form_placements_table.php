<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which pages show which form: one row per form block of a page (or post) in one language, kept by
 * EntryFormPlacements in the same transaction as the save of that language's body, so «Xem trang có
 * biểu mẫu» on the «Biểu mẫu» list is an indexed lookup and never a scan of page bodies (§3.1: a
 * cross-cutting lookup reads a materialized index table maintained with the save, plus a reconcile
 * command, `forms:placements`).
 *
 *  - site_id CASCADE (D4); form_id and entry_id CASCADE: a form deleted for good, or a page purged
 *    from the Trash, takes its rows with it. A page in the Trash or a draft keeps its rows; what is
 *    live is decided when reading (the entry's and the translation's status, the Trash, the route).
 *  - Plain foreign keys, not (site_id, x) composite ones: forms and entries have no (site_id, id) key
 *    to aim at (as for entry_search_words). The writer resolves the form and the page under the
 *    site's RLS, and the policy below refuses a row of another site.
 *  - UNIQUE (site_id, entry_id, locale, form_id): one row per form per page language; serves the
 *    save's read and rewrite of one page language, and the site_id foreign key.
 *  - (form_id, entry_id): "the pages of this form" (a form belongs to one site, so form_id narrows
 *    as much as site_id would) and the lookup Postgres runs when a form is deleted for good.
 *    (entry_id): the lookup when a page is purged. Both carry no site_id and bypass RLS, so only an
 *    index led by the foreign-key column serves them (.claude/rules/migrations.md).
 *  - locale: varchar(12), as in the translation tables. RLS + FORCE, fail closed, like every
 *    website table.
 *
 * A new, empty table: its indexes are built with it (CONCURRENTLY is for existing tables). Adding the
 * foreign keys takes a brief SHARE ROW EXCLUSIVE lock on sites, forms and entries; lock_timeout keeps
 * it from queueing every write behind a long transaction (the migration fails after 5 s and is run
 * again). Fill it with `php artisan forms:placements` after the reload; until then the list offers
 * «Đặt vào trang» for every form. down() drops the table; no source data is lost (the rows are made
 * from the page bodies).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::create('form_placements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained('entries')->cascadeOnDelete();
            $table->string('locale', 12);
            $table->timestampsTz();

            $table->unique(['site_id', 'entry_id', 'locale', 'form_id']);
            $table->index(['form_id', 'entry_id']);
            $table->index('entry_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE form_placements ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE form_placements FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY form_placements_site_isolation ON form_placements
                FOR ALL
                USING      (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
                WITH CHECK (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
            SQL);
        // the default privileges of the migrating role give these too; said here so the table never depends on them
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_user') THEN
                    GRANT SELECT, INSERT, UPDATE, DELETE ON form_placements TO app_user;
                END IF;
            END $$
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('form_placements');
    }
};
