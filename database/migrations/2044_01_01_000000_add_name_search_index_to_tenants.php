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
 * The expression must stay word for word what VietnameseFold::sql() produces, or the planner stops
 * using it: changing its letter table means a migration rebuilding the trigram index and those of
 * 2039_01_01_000100. The letter table is written out here, not read from the class, so this
 * migration keeps building the index production has; AuditLogIndexesTest compares it with the
 * class, and the owner index next to it.
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

        return [
            'tenants_name_fold_trgm_index' => "tenants USING gin (({$folded}) gin_trgm_ops)",
            'tenants_owner_user_id_index' => 'tenants USING btree (owner_user_id)',
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
