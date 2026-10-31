<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes the slug and email lookups of four website tables the way the app (app_user, under RLS)
 * can use them. Under RLS the planner only lets a leakproof operator into an index condition, and
 * no citext operator is leakproof: `slug = ?` on a citext column used the site_id and locale part of
 * an index and then checked every row of that language. `slug::text` is a binary cast and text `=`
 * (and the byte-wise `~>=~` / `~<~`) are leakproof, so the app now compares `col::text` against the
 * lower-cased value, and these indexes are built on that expression.
 *
 * Measured as app_user on a big site, before → after:
 *   - storefront grid finds its category by slug: 16.1 ms → 0.024 ms (taxonomy_translations);
 *   - category slug taken: 10 ms → 0.023 ms;
 *   - free entry slug: 40 ms → 0.14 ms; its fallback for more than 50 copies: 152 ms → 0.029 ms;
 *   - product slug taken: 35 ms → 0.035 ms; free product slug: 68 ms → 0.03 ms;
 *   - pending invitation of an address: 7 ms → 0.021 ms.
 * The entry and product indexes use text_pattern_ops so they also serve the byte-wise range of the
 * fallback; equality uses them as well. The category index serves equality only.
 *
 * The old (site_id, locale, slug) category index is dropped: app_user can use only its site_id and
 * locale columns, which the new index leads with too, and no constraint is built on it.
 *
 * A text comparison is exact, so the stored value must be lower case for the lookups to find it
 * (citext found it in any case). Every writer already stores lower case (the slug rules allow
 * [a-z0-9-] only; invitation addresses are lower-cased). The CHECKs below hold new and changed rows
 * to that. They are added NOT VALID because rows stored earlier are not checked:
 * `php artisan db:lower-case-keys` lists the tables with capitals left and, with --validate,
 * validates the CHECKs once there are none.
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it), and
 * a CHECK that exists is left as it is. Each CHECK takes a brief exclusive lock (no table scan) that
 * gives up after 5s instead of queueing the storefront's reads behind it. down() reverses all of it.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    /** @var array<string, array{string, string}> index name => [table, definition after "ON table"] */
    private const array INDEXES = [
        'taxonomy_translations_site_id_locale_slug_text_index' => ['taxonomy_translations', '(site_id, locale, (slug::text))'],
        'entry_translations_site_id_locale_slug_text_index' => ['entry_translations', '(site_id, locale, (slug::text) text_pattern_ops)'],
        'product_translations_site_id_locale_slug_text_index' => ['product_translations', '(site_id, locale, (slug::text) text_pattern_ops)'],
        'invitations_pending_email_text_index' => ['invitations', '(site_id, (email::text)) WHERE accepted_at IS NULL AND revoked_at IS NULL'],
    ];

    /** The citext category index app_user cannot use beyond site_id and locale. */
    private const string OLD_INDEX = 'taxonomy_translations_site_id_locale_slug_index';

    /** @var array<string, array{string, string}> constraint name => [table, column] */
    private const array LOWER_CASE_CHECKS = [
        'taxonomy_translations_slug_lower_case' => ['taxonomy_translations', 'slug'],
        'entry_translations_slug_lower_case' => ['entry_translations', 'slug'],
        'product_translations_slug_lower_case' => ['product_translations', 'slug'],
        'invitations_email_lower_case' => ['invitations', 'email'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $index => [$table, $definition]) {
            $this->dropIfInvalid($index);

            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON {$table} {$definition}");
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::OLD_INDEX);

        foreach (self::LOWER_CASE_CHECKS as $constraint => [$table, $column]) {
            if ($this->constraintExists($table, $constraint)) {
                continue;
            }

            DB::transaction(function () use ($table, $constraint, $column): void {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$column}::text = lower({$column}::text)) NOT VALID");
            });
        }
    }

    public function down(): void
    {
        foreach (self::LOWER_CASE_CHECKS as $constraint => [$table]) {
            DB::transaction(function () use ($table, $constraint): void {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            });
        }

        $this->dropIfInvalid(self::OLD_INDEX);
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::OLD_INDEX.' ON taxonomy_translations (site_id, locale, slug)');

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

    private function constraintExists(string $table, string $constraint): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM pg_constraint WHERE conrelid = to_regclass(?) AND conname = ?',
            [$table, $constraint],
        ) !== null;
    }
};
