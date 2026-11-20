<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The word table of the search box of «Khách liên hệ» (LeadSearchTerms): every word of a lead's
 * name and email, and its phone's digits run together with each of their endings of three digits or
 * more, folded like VietnameseFold::text() (no accents, lower case), as the notifications' search
 * text (2063) and the products' word table are. The list finds a lead when each typed word is the
 * start of one of its words, in any order.
 *
 * Why a table: LIKE, trigram and full-text operators are not leakproof, so under RLS no index can
 * serve them and a search read every lead of the site (measured on the private test database as
 * app_user under RLS: a trigram GIN index on the folded name is used by the owner role and ignored by
 * app_user, which reads the table in full). A range on a text column of collation "C"
 * (`term >= ? and term < ?`) is leakproof and walks the primary key (LeadSearchTermsIndexesTest).
 *
 *  - Primary key (site_id, term, lead_id): the search walks a word range of one site; it is the
 *    btree (site_id, term) the search needs, in the byte order text_pattern_ops would give. No `id`
 *    column, as for the other word tables (2062, ADR-23): the key already leads with site_id.
 *  - (lead_id): rewriting one lead's words, and the cascade when a lead is deleted (Postgres looks
 *    its children up by the foreign key alone).
 *  - site_id CASCADE (D4); lead_id a plain foreign key CASCADE: form_submissions has no
 *    (site_id, id) key, and adding one to that big table is a decision of its own. A lead erased at
 *    the visitor's request, or deleted with its form, takes its words with it. The writer names the
 *    lead's own site, and the policy below refuses a row of another site.
 *  - term: `text COLLATE "C"`, never empty, at most 64 characters (LeadSearchTerms::MAX_LENGTH).
 *  - RLS + FORCE, fail closed, like every website table.
 *
 * A new, empty table: its indexes are built with it (CONCURRENTLY is for existing tables; nothing
 * reads or writes this one before the release). Adding the foreign keys takes a brief SHARE ROW
 * EXCLUSIVE lock on sites and form_submissions; lock_timeout keeps it from queueing a visitor's
 * contact form behind a long transaction (the migration fails after 5 s and is run again). Fill it
 * with `php artisan forms:search-terms` after the reload; until then a search finds only the leads
 * sent since. No column of an existing table changes (PgBouncer needs no restart). down() drops the
 * table; no source data is lost (the words are made from the leads' name, email and phone).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement(<<<'SQL'
            CREATE TABLE lead_search_terms (
                site_id bigint NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
                term    text COLLATE "C" NOT NULL,
                lead_id bigint NOT NULL REFERENCES form_submissions (id) ON DELETE CASCADE,
                CONSTRAINT lead_search_terms_pkey PRIMARY KEY (site_id, term, lead_id),
                CONSTRAINT lead_search_terms_term_check CHECK (term <> '' AND char_length(term) <= 64)
            )
            SQL);
        DB::statement('CREATE INDEX lead_search_terms_lead_id_index ON lead_search_terms (lead_id)');
        DB::statement('ALTER TABLE lead_search_terms ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE lead_search_terms FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY lead_search_terms_site_isolation ON lead_search_terms
                FOR ALL
                USING      (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
                WITH CHECK (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
            SQL);
        // the default privileges of the migrating role give these too; said here so the table never depends on them
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_user') THEN
                    GRANT SELECT, INSERT, UPDATE, DELETE ON lead_search_terms TO app_user;
                END IF;
            END $$
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS lead_search_terms');
    }
};
