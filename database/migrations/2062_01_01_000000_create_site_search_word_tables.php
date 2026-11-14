<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The word tables of the storefront search (SiteSearchWords), one per kind of item, per website and
 * per language: the folded words of what a visitor may type to find a post or page
 * (`entry_search_words`), a product (`product_site_words`) or a category or tag (`taxonomy_search_words`).
 *
 * Why: as for the admin products search (`product_search_words`, ADR-23), LIKE, trigram and full-text
 * operators are not leakproof, so under RLS no index can serve them; a range on a text column of
 * collation "C" is leakproof and walks the primary key. The admin table is left as it is: a weight and
 * the short descriptions there would widen every range the admin list counts.
 *
 *  - Primary keys (site_id, locale, word, item): the search walks a word range of one site in one
 *    language. No `id` column, extending the ADR-23 exception to the single-column key (ADR-16/18) to
 *    these three tables: the key already leads with site_id, so a HASH partition by site_id needs no
 *    key change.
 *  - weight (posts, pages, products): 1 = the word is in the title, the product name or its code
 *    (with the code's endings, as in the admin table); 3 = only in the short description (an entry's
 *    excerpt, a product's summary). The long text, SEO fields and alt text are never stored.
 *  - (entry_id), (product_id), (taxonomy_id): rewriting one item's words, and the cascade when an item
 *    is deleted for good (Postgres looks its children up by the foreign key alone).
 *  - site_id and the item CASCADE (D4). Items in the Trash, drafts and scheduled items keep their
 *    words; what is public is decided when searching.
 *  - word: `text COLLATE "C"`, never empty, at most 64 characters (SiteSearchWords::MAX_LENGTH).
 *  - locale: varchar(12), as in the translation tables.
 *  - RLS + FORCE, fail closed, like every website table.
 *
 * New, empty tables, no column change (PgBouncer needs no restart). Adding the foreign keys takes a
 * brief SHARE ROW EXCLUSIVE lock on sites, entries, products and taxonomies; lock_timeout keeps it from
 * queueing every write behind a long transaction (the migration fails after 5s and is run again).
 * Fill them with `php artisan search:words` after the code switch (runbook). down() drops them; no
 * source data is lost (the words are made from the titles, names, codes and short descriptions).
 */
return new class extends Migration
{
    /** @var array<string, string> table => its item column and the item's table */
    private const array TABLES = [
        'entry_search_words' => 'entry_id:entries',
        'product_site_words' => 'product_id:products',
        'taxonomy_search_words' => 'taxonomy_id:taxonomies',
    ];

    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        foreach (self::TABLES as $table => $item) {
            [$column, $parent] = explode(':', $item);
            $weight = $table === 'taxonomy_search_words'
                ? ''
                : "weight smallint NOT NULL CONSTRAINT {$table}_weight_check CHECK (weight IN (1, 3)),";
            DB::statement(<<<SQL
                CREATE TABLE {$table} (
                    site_id   bigint NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
                    locale    varchar(12) NOT NULL,
                    word      text COLLATE "C" NOT NULL,
                    {$column} bigint NOT NULL REFERENCES {$parent} (id) ON DELETE CASCADE,
                    {$weight}
                    CONSTRAINT {$table}_pkey PRIMARY KEY (site_id, locale, word, {$column}),
                    CONSTRAINT {$table}_word_check CHECK (word <> '' AND char_length(word) <= 64)
                )
                SQL);
            DB::statement("CREATE INDEX {$table}_{$column}_index ON {$table} ({$column})");

            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY {$table}_site_isolation ON {$table}
                    FOR ALL
                    USING      (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
                    WITH CHECK (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
                SQL);
            // the default privileges of the migrating role give these too; said here so the table never depends on them
            DB::statement(<<<SQL
                DO \$\$ BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_user') THEN
                        GRANT SELECT, INSERT, UPDATE, DELETE ON {$table} TO app_user;
                    END IF;
                END \$\$
                SQL);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table}");
        }
    }
};
