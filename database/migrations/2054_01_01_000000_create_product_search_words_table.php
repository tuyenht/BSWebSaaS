<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The word table of the admin products search (ProductSearchWords): one row per word of a product's
 * names and SKU, folded, per website.
 *
 * Why: LIKE, trigram and full-text operators are not leakproof, so under RLS (app_user) no index can
 * serve them and the products list read every product of the site on each search. A range on a text
 * column of collation "C" is leakproof and becomes an index condition. Measured on a scratch copy as
 * app_user under RLS, a website of 200,000 products (654,533 products, 947,923 translations in all,
 * 4,153,483 word rows = 470 MB before the SKU endings): a code or a word that is not there 5.8–6.9 s
 * → 0.05 ms; the count of a four-word phrase 6.1 s → 67 ms; its first page 33 → 79 ms. Saving one
 * product rewrites its words in ~0.34 ms.
 *
 *  - Primary key (site_id, word, product_id): the search walks a word range of one site. No `id`
 *    column (the owner's choice 08/11/2026: ~100 MB less per 650,000 products); the key already leads
 *    with site_id, so a HASH partition by site_id needs no key change.
 *  - (product_id): rewriting one product's words, and the cascade when a product is deleted for good
 *    (Postgres looks its children up by the foreign key alone).
 *  - site_id, product_id CASCADE (D4). Soft-deleted products keep their words (the Trash is searched).
 *  - word: `text COLLATE "C"`, never empty, at most 64 characters (ProductSearchWords::MAX_LENGTH).
 *  - RLS + FORCE, fail closed, like every website table.
 *
 * A new, empty table: no lock on any existing table, no column change (PgBouncer needs no restart).
 * Fill it with `php artisan catalog:search-words` after the code switch, before the Octane reload (runbook). down() drops it; no source
 * data is lost (the words are made from the names and SKUs).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE product_search_words (
                site_id    bigint NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
                product_id bigint NOT NULL REFERENCES products (id) ON DELETE CASCADE,
                word       text COLLATE "C" NOT NULL,
                CONSTRAINT product_search_words_pkey PRIMARY KEY (site_id, word, product_id),
                CONSTRAINT product_search_words_word_check CHECK (word <> '' AND char_length(word) <= 64)
            )
            SQL);
        DB::statement('CREATE INDEX product_search_words_product_id_index ON product_search_words (product_id)');

        DB::statement('ALTER TABLE product_search_words ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE product_search_words FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY product_search_words_site_isolation ON product_search_words
                FOR ALL
                USING      (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
                WITH CHECK (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
            SQL);
        // the default privileges of the migrating role give these too; said here so the table never depends on them
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_user') THEN
                    GRANT SELECT, INSERT, UPDATE, DELETE ON product_search_words TO app_user;
                END IF;
            END $$
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS product_search_words');
    }
};
