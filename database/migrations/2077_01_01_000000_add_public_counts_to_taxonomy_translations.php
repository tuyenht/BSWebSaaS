<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * How many items a visitor sees on a category's page, per language (CategoryPublicCounts): the
 * number the storefront search suggestion shows beside a category («Sản phẩm · 12 mục»). The admin
 * total (`taxonomies.items_count`, every item not in the trash, drafts included) stays as it is and
 * is never shown to visitors.
 *
 *   taxonomy_translations — public_own_count: the public items the category's default (titles)
 *       layout lists in that language (posts whose main category it is; products filed under it).
 *   taxonomy_translations — public_tree_count: the public items its card layout lists in that
 *       language (filed under it or any category below it, each item once).
 *
 * Both are counters kept in the same transaction as each write, never a live COUNT; the nightly
 * `cms:reconcile-category-public-counts` re-counts them, and its first run after this deploy fills
 * the existing rows (they all start at 0). No index: they are read with the row the suggestion
 * already fetches by (taxonomy_id, locale) and never filtered or sorted, so updates stay HOT.
 *
 * Expand-only. `ADD COLUMN … NOT NULL DEFAULT 0` is metadata-only on PostgreSQL 17 (no table
 * rewrite). Each CHECK is added NOT VALID in a short transaction that gives up on the table lock
 * after 5s, then validated in its own statement, so the scan of existing rows blocks no reads or
 * writes. Every step is guarded (IF NOT EXISTS, a catalog check per constraint), so a run that
 * stops half way can be repeated. A new column leaves PgBouncer holding plans that no longer fit:
 * the release needs its PgBouncer step.
 *
 * Grants: the app role's rights are on whole tables (0000_01_01_000100), and a column added to a
 * table carries them. RLS stays on `site_id`; nothing changes for it.
 *
 * down() drops the two columns (and their CHECKs). Nothing else reads them.
 */
return new class extends Migration
{
    /** The table changes in a short transaction of its own; VALIDATE runs outside it. */
    public $withinTransaction = false;

    private const string OWN_CHECK = 'taxonomy_translations_public_own_count_check';

    private const string TREE_CHECK = 'taxonomy_translations_public_tree_count_check';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->briefly(function (): void {
            DB::statement(<<<'SQL'
                ALTER TABLE taxonomy_translations
                    ADD COLUMN IF NOT EXISTS public_own_count integer NOT NULL DEFAULT 0,
                    ADD COLUMN IF NOT EXISTS public_tree_count integer NOT NULL DEFAULT 0
                SQL);
            $this->addConstraint(self::OWN_CHECK, 'CHECK (public_own_count >= 0)');
            $this->addConstraint(self::TREE_CHECK, 'CHECK (public_tree_count >= 0)');
        });

        DB::statement('ALTER TABLE taxonomy_translations VALIDATE CONSTRAINT '.self::OWN_CHECK);
        DB::statement('ALTER TABLE taxonomy_translations VALIDATE CONSTRAINT '.self::TREE_CHECK);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->briefly(function (): void {
            DB::statement('ALTER TABLE taxonomy_translations DROP CONSTRAINT IF EXISTS '.self::TREE_CHECK);
            DB::statement('ALTER TABLE taxonomy_translations DROP CONSTRAINT IF EXISTS '.self::OWN_CHECK);
            DB::statement(<<<'SQL'
                ALTER TABLE taxonomy_translations
                    DROP COLUMN IF EXISTS public_tree_count,
                    DROP COLUMN IF EXISTS public_own_count
                SQL);
        });
    }

    /** One short transaction that gives up on a table lock after 5s instead of queueing requests. */
    private function briefly(Closure $step): void
    {
        DB::transaction(function () use ($step): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $step();
        });
    }

    /** Add a constraint NOT VALID unless a repeated run already added it; up() validates it later. */
    private function addConstraint(string $name, string $definition): void
    {
        $exists = DB::selectOne(
            "SELECT 1 FROM pg_constraint WHERE conrelid = 'taxonomy_translations'::regclass AND conname = ?",
            [$name],
        );

        if ($exists === null) {
            DB::statement("ALTER TABLE taxonomy_translations ADD CONSTRAINT {$name} {$definition} NOT VALID");
        }
    }
};
