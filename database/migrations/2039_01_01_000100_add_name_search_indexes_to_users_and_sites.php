<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The activity log's search (Platform\Audit\Index) looks up every person whose name or email
 * contains the words, and every website whose name does, with `LIKE '%…%'` on the folded name
 * (VietnameseFold::sql, Vietnamese letters without marks, lower case) and on lower(email). No
 * btree serves a match in the middle of a word, so each search read the whole `users` and
 * `sites` tables, twice for users. Trigram GIN indexes (pg_trgm) on exactly those expressions
 * serve it with no change to the SQL: measured with 20M log rows, 20,000 people and 2,000
 * websites, a common search went from 450 to 58 ms ("nguyen") and from 395 to 24 ms ("113.161")
 * per page, for 1.7 MB of indexes built in under a second. `users` and `sites` are platform-wide
 * (D6), so the indexes lead with the searched expression, the exception the migration rules
 * allow for D6 tables backed by measurements.
 *
 * The expressions must stay word for word what VietnameseFold::sql() produces, or the planner
 * stops using them: changing its letter table means a migration rebuilding these two indexes.
 * The letter table is written out here, not read from the class, so this migration keeps building
 * the index production has; AuditLogIndexesTest compares it with the class.
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
     * @return array<string, string> index name => "table USING gin (expression gin_trgm_ops)"
     */
    private function indexes(): array
    {
        $folded = "lower(translate(name, '".self::FROM."', '".self::TO."'))";

        return [
            'users_name_fold_trgm_index' => "users USING gin (({$folded}) gin_trgm_ops)",
            'users_email_lower_trgm_index' => 'users USING gin ((lower(email)) gin_trgm_ops)',
            'sites_name_fold_trgm_index' => "sites USING gin (({$folded}) gin_trgm_ops)",
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
