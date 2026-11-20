<?php

use App\Modules\Shared\Support\Cast;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The data the «Chuyển hướng» admin screen needs. Expand-only: every new column is nullable or has a
 * constant default, so no row is rewritten and the code running before this deploy keeps working.
 *
 *   deleted_at — a removed redirect waits in a trash (30 days, `redirects:purge-trash`), like posts,
 *       menu items and media. The model's scope hides it from every reader (the storefront lookup,
 *       RouteSync, the content-pack planner); RLS stays on site_id, so the trash list, restore and
 *       the purge still see the rows. The unique (site_id, locale, from_path) becomes partial on the
 *       live rows in 2085_000100, so an address in the trash can be added again.
 *   source — 'auto' when RouteSync wrote the row (a published address changed), 'manual' when a
 *       person added it. varchar + CHECK (D13). Existing rows that lead to a post or page (entry_id
 *       set) can only have come from RouteSync, so they become 'auto'; the others cannot be told
 *       apart and stay 'manual'.
 *   product_id, taxonomy_id — the product or category a redirect leads to, beside entry_id, so the
 *       screen can say what the row came from. ON DELETE SET NULL: products and categories are
 *       hard-deleted (a product after its trash), and the redirect keeps serving from → to. The
 *       lookup indexes the delete needs are built CONCURRENTLY in 2085_000100.
 *   ulid char(26) — the key the screen puts into the page instead of the bigint id (D2). Filled here
 *       for existing rows, by the model on every new one, and by `cms:fill-ulids` for a row an older
 *       process wrote during the deploy. Its unique (site_id, ulid) comes in 2085_000100.
 *
 * Grants: the app role's rights are on whole tables (0000_01_01_000100), and a column added to a
 * table carries them, so app_user reads and writes the new columns with nothing granted here.
 *
 * Locks: the ALTER is one short transaction that gives up after 5s rather than queue requests behind
 * it. The CHECK and the two FKs are added NOT VALID and validated afterwards, each in its own
 * statement, so the scan of existing rows holds no lock that blocks reads or writes. The backfills
 * write in chunks of rows walked by id. Every step is guarded (IF NOT EXISTS, a catalog check per
 * constraint, `IS NULL` in each UPDATE), so a run that stops half way can be repeated.
 *
 * down() puts the old shape back. It loses what only the new shape can hold: the redirects in the
 * trash are deleted (without the column they would come back into service), with the source, the
 * product and category links and the keys.
 */
return new class extends Migration
{
    /** The ALTER runs in its own short transaction; VALIDATE and the backfills run outside it. */
    public $withinTransaction = false;

    private const string SOURCE_CHECK = 'redirects_source_check';

    private const string PRODUCT_FK = 'redirects_product_id_foreign';

    private const string TAXONOMY_FK = 'redirects_taxonomy_id_foreign';

    private const int CHUNK = 1000;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->briefly(function (): void {
            DB::statement(<<<'SQL'
                ALTER TABLE redirects
                    ADD COLUMN IF NOT EXISTS deleted_at timestamp(0) with time zone NULL,
                    ADD COLUMN IF NOT EXISTS source varchar(10) NOT NULL DEFAULT 'manual',
                    ADD COLUMN IF NOT EXISTS product_id bigint NULL,
                    ADD COLUMN IF NOT EXISTS taxonomy_id bigint NULL,
                    ADD COLUMN IF NOT EXISTS ulid char(26) NULL
                SQL);
            $this->addConstraint(self::SOURCE_CHECK, "CHECK (source IN ('auto', 'manual'))");
            $this->addConstraint(self::PRODUCT_FK, 'FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL');
            $this->addConstraint(self::TAXONOMY_FK, 'FOREIGN KEY (taxonomy_id) REFERENCES taxonomies (id) ON DELETE SET NULL');
        });

        DB::statement('ALTER TABLE redirects VALIDATE CONSTRAINT '.self::SOURCE_CHECK);
        DB::statement('ALTER TABLE redirects VALIDATE CONSTRAINT '.self::PRODUCT_FK);
        DB::statement('ALTER TABLE redirects VALIDATE CONSTRAINT '.self::TAXONOMY_FK);

        $this->markEntryRedirectsAuto();
        $this->fillUlids();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->briefly(function (): void {
            if ($this->hasColumn('deleted_at')) {
                DB::statement('DELETE FROM redirects WHERE deleted_at IS NOT NULL');
            }
            DB::statement('ALTER TABLE redirects DROP CONSTRAINT IF EXISTS '.self::TAXONOMY_FK);
            DB::statement('ALTER TABLE redirects DROP CONSTRAINT IF EXISTS '.self::PRODUCT_FK);
            DB::statement('ALTER TABLE redirects DROP CONSTRAINT IF EXISTS '.self::SOURCE_CHECK);
            DB::statement(<<<'SQL'
                ALTER TABLE redirects
                    DROP COLUMN IF EXISTS ulid,
                    DROP COLUMN IF EXISTS taxonomy_id,
                    DROP COLUMN IF EXISTS product_id,
                    DROP COLUMN IF EXISTS source,
                    DROP COLUMN IF EXISTS deleted_at
                SQL);
        });
    }

    /** A row that leads to a post or page was written by RouteSync: only it sets entry_id. */
    private function markEntryRedirectsAuto(): void
    {
        $after = 0;
        do {
            $ids = $this->idsAfter($after, 'entry_id IS NOT NULL AND source = \'manual\'');
            if ($ids !== []) {
                DB::update('UPDATE redirects SET source = \'auto\' WHERE id = ANY(?::bigint[]) AND entry_id IS NOT NULL', ['{'.implode(',', $ids).'}']);
                $after = (int) end($ids);
            }
        } while (count($ids) === self::CHUNK);
    }

    /** Give every existing row its key; a key fill is not an edit, so updated_at is left alone. */
    private function fillUlids(): void
    {
        $after = 0;
        do {
            $ids = $this->idsAfter($after, 'ulid IS NULL');
            if ($ids !== []) {
                $values = implode(', ', array_fill(0, count($ids), '(?::bigint, ?::char(26))'));
                $bindings = [];
                foreach ($ids as $id) {
                    $bindings[] = $id;
                    $bindings[] = (string) Str::ulid();
                }
                DB::update("UPDATE redirects AS r SET ulid = v.ulid FROM (VALUES {$values}) AS v(id, ulid) WHERE r.id = v.id AND r.ulid IS NULL", $bindings);
                $after = (int) end($ids);
            }
        } while (count($ids) === self::CHUNK);
    }

    /**
     * The next chunk of row ids after $after that match $where (a fixed condition, never input).
     *
     * @return list<int>
     */
    private function idsAfter(int $after, string $where): array
    {
        $ids = [];
        foreach (DB::select("SELECT id FROM redirects WHERE id > ? AND {$where} ORDER BY id LIMIT ".self::CHUNK, [$after]) as $row) {
            $ids[] = Cast::int(((array) $row)['id'] ?? 0);
        }

        return $ids;
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
            'SELECT 1 FROM pg_constraint WHERE conrelid = \'redirects\'::regclass AND conname = ?',
            [$name],
        );

        if ($exists === null) {
            DB::statement("ALTER TABLE redirects ADD CONSTRAINT {$name} {$definition} NOT VALID");
        }
    }

    private function hasColumn(string $column): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = \'redirects\' AND column_name = ?',
            [$column],
        ) !== null;
    }
};
