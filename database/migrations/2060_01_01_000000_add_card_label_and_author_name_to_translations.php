<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two optional per-language texts an editor types:
 *  - taxonomy_translations.card_label varchar(40): the short label a category puts on its cards
 *    (the product and post cards on the home page). Empty = the category's name, as before.
 *  - entry_translations.author_name varchar(80): who a post is by, as the reader sees it (a team
 *    or department such as "Phòng Kỹ thuật"). Free text, linked to no staff account, so no staff
 *    member's name leaves the admin. Empty = the site's business, as before.
 *
 * Plain varchar, not citext: neither is compared, sorted or unique. No index: both are read through
 * the translation's unique (owner, locale) key or an eager load that already reads the row.
 *
 * Expand-only: a nullable column with no default is a catalog change, so no row is rewritten and
 * nothing is backfilled. Grants: the app role's rights are on whole tables (0000_01_01_000100), and
 * a column added to a table carries them.
 *
 * Locks: up() changes each table in its own short transaction that gives up after 5s rather than
 * queue storefront reads behind it (entry_translations is read on every post page); down() locks
 * both in one such transaction. Guarded (IF [NOT] EXISTS), so a run that stops half way can be
 * repeated.
 *
 * down() never drops typed text: while any row holds a card label or an author name it refuses and
 * says how many, and drops nothing. Clear those values on purpose first, then roll back.
 */
return new class extends Migration
{
    /** Each step runs in its own short transaction (briefly()). */
    public $withinTransaction = false;

    /** table => [column, type] */
    private const array COLUMNS = [
        'taxonomy_translations' => ['card_label', 'varchar(40)'],
        'entry_translations' => ['author_name', 'varchar(80)'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::COLUMNS as $table => [$column, $type]) {
            $this->briefly(fn () => DB::statement("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$column} {$type} NULL"));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // One transaction holding both tables, so no value can be written between the look and the
        // drop; every table is checked before anything is dropped, so a refusal leaves both in place.
        $this->briefly(function (): void {
            DB::statement('LOCK TABLE '.implode(', ', array_keys(self::COLUMNS)).' IN ACCESS EXCLUSIVE MODE');
            $held = [];
            foreach (self::COLUMNS as $table => [$column]) {
                if (! $this->hasColumn($table, $column)) {
                    continue;
                }
                // Migrations run on the privileged connection, which row-level security does not limit: every site counts.
                $count = DB::table($table)->whereNotNull($column)->count();
                if ($count > 0) {
                    $held[] = "{$count} row(s) of {$table}.{$column}";
                }
            }
            if ($held !== []) {
                throw new RuntimeException(
                    'Refused: '.implode(' and ', $held).' still hold text an editor typed. '
                    .'Clear those values on purpose first (set them to NULL), then roll back.'
                );
            }

            foreach (self::COLUMNS as $table => [$column]) {
                DB::statement("ALTER TABLE {$table} DROP COLUMN IF EXISTS {$column}");
            }
        });
    }

    private function hasColumn(string $table, string $column): bool
    {
        return DB::selectOne(
            'select 1 as found from information_schema.columns where table_schema = current_schema() and table_name = ? and column_name = ?',
            [$table, $column],
        ) !== null;
    }

    private function briefly(Closure $step): void
    {
        DB::transaction(function () use ($step): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $step();
        });
    }
};
