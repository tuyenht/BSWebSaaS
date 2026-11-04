<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The data the Categories and Menus admin screens need (design 10 "Chuyên mục", 11 "Trình đơn").
 * Expand-only: every new column is nullable or has a constant default, so no row is rewritten and
 * the code running before this deploy keeps working.
 *
 *   taxonomy_translations — description (text, plain, escaped when shown), seo_title and
 *       seo_description (varchar(255), the same types as entry_translations), per language.
 *   taxonomies — cover_media_id: one picture for every language, FK to media ON DELETE SET NULL
 *       (media is purged from its trash, so the picture then falls away). A plain FK: media has no
 *       (site_id, id) key to aim a same-site FK at, so the write path resolves the picture by its
 *       media key through the site's scope. Its lookup index is built CONCURRENTLY in 2050_000100.
 *   taxonomies — items_count: the posts and products whose main category this is, not in the
 *       trash. A counter kept in the same transaction as each write, never a live COUNT; a reconcile
 *       command re-counts. It starts at 0 for every row; the reconcile fills the existing ones.
 *   menu_items — deleted_at: removed items wait in a trash (30 days), like posts, products and
 *       media. The trash is filtered by the model's scope; RLS stays on site_id, so the trash list,
 *       restore and the purge can still see the rows. The live-tree index is built CONCURRENTLY in
 *       2050_000200.
 *   menu_assignments — an area either shows a menu (menu_id) or follows another area of the same
 *       theme (same_as_region, e.g. the phone menu follows the main menu). menu_id stops being
 *       NOT NULL and a CHECK keeps exactly one of the two set. Every existing row has a menu
 *       (menu_id was NOT NULL until now) and same_as_region starts NULL, so the CHECK holds at once.
 *   taxonomies, menu_items — ulid char(26), as on menus: the key the screens put into the page
 *       instead of the bigint id. Nullable for now: the models fill it on every new row, and
 *       `cms:fill-ulids` fills the existing ones. SET NOT NULL and the unique index come in a later
 *       deploy, once no NULL is left.
 *
 * Grants: the app role's rights are on whole tables (0000_01_01_000100), and a column added to a
 * table carries them, so app_user reads and writes the new columns with nothing granted here.
 *
 * Locks: each table changes in its own short transaction, which gives up after 5s rather than queue
 * requests behind it. The new CHECKs and the FK are added NOT VALID and validated afterwards, each
 * in its own statement, so the scan of existing rows holds no lock that blocks reads or writes.
 * Every step is guarded (IF NOT EXISTS, a catalog check per constraint), so a run that stops half
 * way can be repeated.
 *
 * down() puts the old shape back. It loses what only the new shape can hold: the menu items in the
 * trash are deleted (without the column they would come back into the live menu; a live child of a
 * deleted item moves to the top level through the parent_id SET NULL), and the areas that follow
 * another area are deleted (the old NOT NULL forbids them).
 */
return new class extends Migration
{
    /** Each table changes in its own short transaction; VALIDATE runs outside them. */
    public $withinTransaction = false;

    private const string COVER_FK = 'taxonomies_cover_media_id_foreign';

    private const string COUNT_CHECK = 'taxonomies_items_count_check';

    private const string TARGET_CHECK = 'menu_assignments_one_target_check';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->briefly(function (): void {
            DB::statement(<<<'SQL'
                ALTER TABLE taxonomy_translations
                    ADD COLUMN IF NOT EXISTS description text NULL,
                    ADD COLUMN IF NOT EXISTS seo_title varchar(255) NULL,
                    ADD COLUMN IF NOT EXISTS seo_description varchar(255) NULL
                SQL);
        });

        $this->briefly(function (): void {
            DB::statement(<<<'SQL'
                ALTER TABLE taxonomies
                    ADD COLUMN IF NOT EXISTS cover_media_id bigint NULL,
                    ADD COLUMN IF NOT EXISTS items_count integer NOT NULL DEFAULT 0,
                    ADD COLUMN IF NOT EXISTS ulid char(26) NULL
                SQL);
            $this->addConstraint('taxonomies', self::COVER_FK, 'FOREIGN KEY (cover_media_id) REFERENCES media (id) ON DELETE SET NULL');
            $this->addConstraint('taxonomies', self::COUNT_CHECK, 'CHECK (items_count >= 0)');
        });

        $this->briefly(function (): void {
            DB::statement(<<<'SQL'
                ALTER TABLE menu_items
                    ADD COLUMN IF NOT EXISTS deleted_at timestamp(0) with time zone NULL,
                    ADD COLUMN IF NOT EXISTS ulid char(26) NULL
                SQL);
        });

        $this->briefly(function (): void {
            DB::statement(<<<'SQL'
                ALTER TABLE menu_assignments
                    ALTER COLUMN menu_id DROP NOT NULL,
                    ADD COLUMN IF NOT EXISTS same_as_region varchar(64) NULL
                SQL);
            $this->addConstraint('menu_assignments', self::TARGET_CHECK, 'CHECK ((menu_id IS NULL) <> (same_as_region IS NULL))');
        });

        DB::statement('ALTER TABLE taxonomies VALIDATE CONSTRAINT '.self::COVER_FK);
        DB::statement('ALTER TABLE taxonomies VALIDATE CONSTRAINT '.self::COUNT_CHECK);
        DB::statement('ALTER TABLE menu_assignments VALIDATE CONSTRAINT '.self::TARGET_CHECK);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->briefly(function (): void {
            DB::statement('DELETE FROM menu_assignments WHERE menu_id IS NULL');
            DB::statement('ALTER TABLE menu_assignments DROP CONSTRAINT IF EXISTS '.self::TARGET_CHECK);
            DB::statement(<<<'SQL'
                ALTER TABLE menu_assignments
                    DROP COLUMN IF EXISTS same_as_region,
                    ALTER COLUMN menu_id SET NOT NULL
                SQL);
        });

        $this->briefly(function (): void {
            if ($this->hasColumn('menu_items', 'deleted_at')) {
                DB::statement('DELETE FROM menu_items WHERE deleted_at IS NOT NULL');
            }
            DB::statement(<<<'SQL'
                ALTER TABLE menu_items
                    DROP COLUMN IF EXISTS deleted_at,
                    DROP COLUMN IF EXISTS ulid
                SQL);
        });

        $this->briefly(function (): void {
            DB::statement('ALTER TABLE taxonomies DROP CONSTRAINT IF EXISTS '.self::COUNT_CHECK);
            DB::statement('ALTER TABLE taxonomies DROP CONSTRAINT IF EXISTS '.self::COVER_FK);
            DB::statement(<<<'SQL'
                ALTER TABLE taxonomies
                    DROP COLUMN IF EXISTS ulid,
                    DROP COLUMN IF EXISTS items_count,
                    DROP COLUMN IF EXISTS cover_media_id
                SQL);
        });

        $this->briefly(function (): void {
            DB::statement(<<<'SQL'
                ALTER TABLE taxonomy_translations
                    DROP COLUMN IF EXISTS seo_description,
                    DROP COLUMN IF EXISTS seo_title,
                    DROP COLUMN IF EXISTS description
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
    private function addConstraint(string $table, string $name, string $definition): void
    {
        $exists = DB::selectOne(
            'SELECT 1 FROM pg_constraint WHERE conrelid = ?::regclass AND conname = ?',
            [$table, $name],
        );

        if ($exists === null) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition} NOT VALID");
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
            [$table, $column],
        ) !== null;
    }
};
