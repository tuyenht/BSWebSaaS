<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The platform customer list's search (Admin\Support\Customers\CustomerDirectory) looks up every
 * customer whose name contains the words, with `LIKE '%…%'` on the folded name (VietnameseFold::sql,
 * Vietnamese letters without marks, lower case), together with every customer whose owner's name
 * or email does. No btree serves a match in the middle of a word, so each search folded the name
 * of every row of `tenants` (about 10 µs a row with this letter table), three times per screen.
 * Two indexes serve it with the search split into one lookup per index: a trigram GIN index
 * (pg_trgm) on exactly the folded-name expression, and a btree on owner_user_id, which finds the
 * customers of the owners the users trigram indexes matched (without it each search read the
 * whole table again). Measured with 50,000 customers, each with an owner (page and counts in one
 * statement, median of five): a narrow search went from 2,595 to 5 ms ("tam duc 4997"), a search
 * by the owner's email from 2,658 to 7 ms, and a wide one matching 10,000 owners from 2,395 to
 * about 200 ms ("nguyen"), for 2.2 MB and 1.1 MB of indexes built in under a second. `tenants` is
 * platform-wide (D6), so the trigram index leads with the searched expression, the exception the
 * migration rules allow for D6 tables backed by measurements.
 *
 * A search of one or two letters ("an", "hà") finds the customers and owners with a name whose
 * word starts with them. A trigram index cannot serve it on its own: with "(^| )an" it finds the
 * rows, but checks each one again with the fold (300 ms for 20,000 matches at 50,000 customers),
 * and a single letter, or a word start after any other separator, reads the whole table (530 to
 * 790 ms). A word index serves it with no second check: a GIN index on the folded name as a text
 * search vector (VietnameseFold::words, the 'simple' configuration: every word, no stemming),
 * queried by prefix ("an:*"), on `tenants` and on `users` (the owners; also D6). Measured at
 * 50,000: 6 to 16 ms for 10,000 to 50,000 matches. Both lead with that expression, as above.
 *
 * The expressions must stay word for word what VietnameseFold::sql() and words() produce, or the
 * planner stops using them: changing the letter table means a migration rebuilding these indexes
 * and those of 2039_01_01_000100. The letter table is written out here, not read from the class,
 * so this migration keeps building the indexes production has; AuditLogIndexesTest compares them
 * with the class, and checks the owner index next to them.
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it).
 * Expand-only; down() drops what up() added.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const string FROM = 'àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ'
        .'ÀÁẠẢÃÂẦẤẬẨẪĂẰẮẶẲẴÈÉẸẺẼÊỀẾỆỂỄÌÍỊỈĨÒÓỌỎÕÔỒỐỘỔỖƠỜỚỢỞỠÙÚỤỦŨƯỪỨỰỬỮỲÝỴỶỸĐ';

    private const string TO = 'aaaaaaaaaaaaaaaaaeeeeeeeeeeeiiiiiooooooooooooooooouuuuuuuuuuuyyyyyd'
        .'AAAAAAAAAAAAAAAAAEEEEEEEEEEEIIIIIOOOOOOOOOOOOOOOOOUUUUUUUUUUUYYYYYD';

    public function up(): void
    {
        foreach ($this->indexes() as $index => $definition) {
            $this->dropIfInvalid($index);

            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON {$definition}");
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->indexes()) as $index) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }

    /**
     * @return array<string, string> index name => "table USING method (expression)"
     */
    private function indexes(): array
    {
        $folded = "lower(translate(name, '".self::FROM."', '".self::TO."'))";
        $words = "to_tsvector('simple'::regconfig, {$folded})";

        return [
            'tenants_name_fold_trgm_index' => "tenants USING gin (({$folded}) gin_trgm_ops)",
            'tenants_owner_user_id_index' => 'tenants USING btree (owner_user_id)',
            'tenants_name_fold_words_index' => "tenants USING gin (({$words}))",
            'users_name_fold_words_index' => "users USING gin (({$words}))",
        ];
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
