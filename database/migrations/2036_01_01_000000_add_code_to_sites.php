<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every website gets a permanent code, `sites.code`: 8 characters from `0-9a-hjkmnp-tv-z` (lowercase
 * Crockford base32, no i l o u). New media storage keys start with it (`{code}/…`). The old prefix was
 * the slug of the site name, which is not unique: two websites with the same name shared a prefix and
 * could overwrite or delete each other's files.
 *
 * - The database issues the code (DEFAULT generate_site_code(), so raw inserts get one too). The
 *   generator skips any code already held (soft-deleted sites included) and the code-shaped first
 *   segments of the storage keys that exist when this migration runs, frozen into the function as a
 *   constant list. The list stays small and can never grow: only legacy name-slug keys can be on it,
 *   at most a few per website that existed then, and the trigger below refuses every NEW key whose
 *   first segment has the code shape but is not its own site's code. So the generator never scans
 *   media, whatever its size.
 * - CHECK on the format; UNIQUE over every row (a soft-deleted site keeps its code, so it is never
 *   issued again). A HARD-deleted site frees its code; its R2 prefix must be purged with it.
 * - The application role loses UPDATE on the column. Column privileges only narrow a table that has no
 *   table-level grant, so the table-level UPDATE is revoked and every OTHER column is granted back:
 *   a column added to `sites` later needs its own `GRANT UPDATE (column) ON sites TO app_user`.
 * - A trigger on `media` (BEFORE INSERT, or UPDATE of storage_key/site_id) judges every key written:
 *   1. It must be a clean relative path: ASCII segments that start with a letter or digit, joined by
 *      single slashes. Storage (Flysystem) turns `\` into `/`, drops `.` and empty segments and
 *      resolves `..`, so `./{code}/x` or `{code}\x` would reach another site's objects although their
 *      first segment is no code. A staging key (`_…`) and an empty key fail here too.
 *   2. A key under the row's own code passes.
 *   3. A first segment with the code shape is refused: either another website's code, or a code that
 *      could be issued later.
 *   4. Anything else is the legacy name-slug layout (existing rows, An Vinh's seeded images), accepted
 *      until it is re-keyed, but only while no row of ANOTHER website holds the same key (trashing one
 *      site's copy would delete the other's file). That lookup is the only reason the trigger runs with
 *      the definer's rights (media has FORCE ROW LEVEL SECURITY) and needs the definer to be a
 *      superuser or BYPASSRLS role; it uses a partial index that holds legacy-layout keys only, so
 *      it stays tiny. A transaction-scoped advisory lock on the key serialises two concurrent writers.
 *   The legacy branch, its index and the frozen list go away when every row is re-keyed.
 *
 * Pre-checks abort before anything changes: the migration role must see every row (superuser or
 * BYPASSRLS), every existing storage key must be a clean relative path, and no storage key may be
 * shared by two websites.
 *
 * Expand-only. All but the two indexes run in one transaction; the indexes are built CONCURRENTLY
 * afterwards, so a re-run first drops an INVALID leftover. down() removes everything up() added.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const string SITES_INDEX = 'sites_code_unique';

    private const string LEGACY_KEY_INDEX = 'media_legacy_storage_key_lookup';

    /** A clean relative path (the application checks keys with the same pattern, MediaKey::CLEAN_PATH_PATTERN). */
    private const string CLEAN_KEY = '^[A-Za-z0-9][A-Za-z0-9._-]*(/[A-Za-z0-9][A-Za-z0-9._-]*)+$';

    /** The shape of a site code, as the sites_code_format CHECK has it. */
    private const string CODE_SHAPE = '^[0-9a-hjkmnp-tv-z]{8}$';

    /** A key whose first segment has the code shape (the legacy-key index holds every other key). */
    private const string CODE_SHAPED_FIRST_SEGMENT = '^[0-9a-hjkmnp-tv-z]{8}/';

    private const string GENERATOR = <<<'SQL'
        CREATE OR REPLACE FUNCTION generate_site_code() RETURNS varchar
        LANGUAGE plpgsql VOLATILE
        SET search_path = pg_catalog, public
        AS $fn$
        DECLARE
            alphabet constant text := '0123456789abcdefghjkmnpqrstvwxyz';
            -- Code-shaped first segments of the storage keys that existed when the migration ran.
            legacy_segments constant text[] := __LEGACY_SEGMENTS__;
            candidate text;
        BEGIN
            FOR attempt IN 1..64 LOOP
                candidate := '';
                FOR position IN 1..8 LOOP
                    candidate := candidate || substr(alphabet, 1 + floor(random() * 32)::int, 1);
                END LOOP;

                CONTINUE WHEN candidate = ANY (legacy_segments);
                CONTINUE WHEN EXISTS (SELECT 1 FROM public.sites WHERE code = candidate);

                RETURN candidate;
            END LOOP;

            RAISE EXCEPTION 'no free site code after 64 attempts';
        END
        $fn$
    SQL;

    private const string PREFIX_GUARD = <<<'SQL'
        CREATE OR REPLACE FUNCTION media_storage_key_prefix_guard() RETURNS trigger
        LANGUAGE plpgsql SECURITY DEFINER
        SET search_path = pg_catalog, public
        AS $fn$
        DECLARE
            own_code text;
            first_segment text;
        BEGIN
            IF TG_OP = 'UPDATE'
                AND NEW.storage_key IS NOT DISTINCT FROM OLD.storage_key
                AND NEW.site_id IS NOT DISTINCT FROM OLD.site_id THEN
                RETURN NEW;
            END IF;

            IF NEW.storage_key IS NULL OR NEW.storage_key !~ '__CLEAN_KEY__' THEN
                RAISE EXCEPTION 'media storage key "%" is not a clean relative path', NEW.storage_key
                    USING ERRCODE = 'check_violation';
            END IF;

            first_segment := split_part(NEW.storage_key, '/', 1);
            SELECT code INTO own_code FROM public.sites WHERE id = NEW.site_id;

            IF first_segment = own_code THEN
                RETURN NEW;
            END IF;

            IF first_segment ~ '__CODE_SHAPE__' THEN
                RAISE EXCEPTION 'media storage key "%" starts with a website code that is not its own site''s', NEW.storage_key
                    USING ERRCODE = 'check_violation';
            END IF;

            -- Legacy name-slug layout: accepted until every row is re-keyed under its site code, as long
            -- as no other website holds the same key.
            PERFORM pg_advisory_xact_lock(hashtext('media legacy storage key'), hashtext(NEW.storage_key));

            IF EXISTS (
                SELECT 1 FROM public.media
                WHERE storage_key = NEW.storage_key
                  AND storage_key !~ '__CODE_SHAPED_FIRST_SEGMENT__'
                  AND site_id <> NEW.site_id
                  AND id <> NEW.id
            ) THEN
                RAISE EXCEPTION 'media storage key "%" is already used by another website', NEW.storage_key
                    USING ERRCODE = 'check_violation';
            END IF;

            RETURN NEW;
        END
        $fn$
    SQL;

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->assertSeesEveryRow();
            $this->assertEveryKeyIsClean();
            $this->assertNoSharedStorageKeys();

            DB::statement('ALTER TABLE sites ADD COLUMN IF NOT EXISTS code varchar(8)');
            DB::statement(str_replace('__LEGACY_SEGMENTS__', $this->legacySegmentsLiteral(), self::GENERATOR));
            DB::statement('REVOKE ALL ON FUNCTION generate_site_code() FROM PUBLIC');
            DB::statement('GRANT EXECUTE ON FUNCTION generate_site_code() TO app_user');
            DB::statement('ALTER TABLE sites ALTER COLUMN code SET DEFAULT generate_site_code()');

            $this->backfill();

            if (DB::selectOne("SELECT 1 FROM pg_constraint WHERE conname = 'sites_code_format'") === null) {
                DB::statement("ALTER TABLE sites ADD CONSTRAINT sites_code_format CHECK (code ~ '".self::CODE_SHAPE."')");
            }
            DB::statement('ALTER TABLE sites ALTER COLUMN code SET NOT NULL');

            $this->assertNoLegacySegmentIsACode();
            $this->narrowUpdateGrant();

            DB::statement(strtr(self::PREFIX_GUARD, [
                '__CLEAN_KEY__' => self::CLEAN_KEY,
                '__CODE_SHAPE__' => self::CODE_SHAPE,
                '__CODE_SHAPED_FIRST_SEGMENT__' => self::CODE_SHAPED_FIRST_SEGMENT,
            ]));
            DB::statement('REVOKE ALL ON FUNCTION media_storage_key_prefix_guard() FROM PUBLIC');
            DB::statement('DROP TRIGGER IF EXISTS media_storage_key_prefix_guard ON media');
            DB::statement(
                'CREATE TRIGGER media_storage_key_prefix_guard BEFORE INSERT OR UPDATE OF storage_key, site_id ON media '.
                'FOR EACH ROW EXECUTE FUNCTION media_storage_key_prefix_guard()'
            );
        });

        $this->createIndexConcurrently(self::SITES_INDEX, 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS '.self::SITES_INDEX.' ON sites (code)');
        // Cross-site lookups of legacy keys only, so not led by site_id; new site-code keys stay out of it.
        $this->createIndexConcurrently(
            self::LEGACY_KEY_INDEX,
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::LEGACY_KEY_INDEX.' ON media (storage_key) '.
            "WHERE storage_key !~ '".self::CODE_SHAPED_FIRST_SEGMENT."'",
        );
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS media_storage_key_prefix_guard ON media');
        DB::statement('DROP FUNCTION IF EXISTS media_storage_key_prefix_guard()');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::LEGACY_KEY_INDEX);
        DB::statement('GRANT UPDATE ON sites TO app_user');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::SITES_INDEX);
        DB::statement('ALTER TABLE sites DROP COLUMN IF EXISTS code');
        DB::statement('DROP FUNCTION IF EXISTS generate_site_code()');
    }

    /** RLS would hide media rows from a restricted role, and every check below would pass on nothing. */
    private function assertSeesEveryRow(): void
    {
        $role = DB::selectOne('SELECT rolsuper OR rolbypassrls AS sees_all FROM pg_roles WHERE rolname = current_user');

        if (! is_object($role) || ! property_exists($role, 'sees_all') || ($role->sees_all !== true && $role->sees_all !== 't')) {
            throw new RuntimeException(
                'Run this migration as the privileged role (php artisan migrate --database=pgsql_admin): '.
                'it must read every website\'s media rows, which row-level security hides from other roles.'
            );
        }
    }

    /** The trigger refuses any other key on its next write; an existing one is listed for repair first. */
    private function assertEveryKeyIsClean(): void
    {
        $unclean = $this->storageKeys(DB::select('SELECT storage_key FROM media WHERE storage_key !~ ? LIMIT 20', [self::CLEAN_KEY]));

        if ($unclean !== []) {
            throw new RuntimeException(
                'These media storage keys are not clean relative paths (storage would rewrite them to another path): '.
                implode(', ', array_map(fn (string $key): string => json_encode($key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '?', $unclean)).
                '. Re-key or remove those rows before running this migration.'
            );
        }
    }

    private function assertNoSharedStorageKeys(): void
    {
        $shared = $this->storageKeys(DB::select('SELECT storage_key FROM media GROUP BY storage_key HAVING count(DISTINCT site_id) > 1 LIMIT 20'));

        if ($shared !== []) {
            throw new RuntimeException(
                'Two websites share these media storage keys, so their files overwrite each other: '.implode(', ', $shared).'. '.
                'Re-key one website\'s rows (or remove the duplicates) before running this migration.'
            );
        }
    }

    /**
     * The code-shaped first segments of today's storage keys that are no site's code, as a SQL array
     * literal. Every element is validated against the code shape, so it can be inlined safely.
     */
    private function legacySegmentsLiteral(): string
    {
        $rows = DB::select(
            "SELECT DISTINCT split_part(m.storage_key, '/', 1) AS segment FROM media m
             WHERE split_part(m.storage_key, '/', 1) ~ ?
               AND NOT EXISTS (SELECT 1 FROM sites s WHERE s.code = split_part(m.storage_key, '/', 1))
             ORDER BY 1",
            [self::CODE_SHAPE],
        );

        $segments = [];
        foreach ($rows as $row) {
            $segment = is_object($row) && property_exists($row, 'segment') && is_string($row->segment) ? $row->segment : '';
            if (preg_match('/'.self::CODE_SHAPE.'/', $segment) !== 1) {
                throw new RuntimeException("Unexpected legacy key segment \"{$segment}\".");
            }
            $segments[] = "'{$segment}'";
        }

        return $segments === [] ? "'{}'::text[]" : 'ARRAY['.implode(', ', $segments).']::text[]';
    }

    /** One statement per site, so each new code is visible to the generator when it draws the next. */
    private function backfill(): void
    {
        foreach (DB::table('sites')->whereNull('code')->orderBy('id')->pluck('id') as $id) {
            DB::update('UPDATE sites SET code = generate_site_code() WHERE id = ?', [$id]);
        }
    }

    private function assertNoLegacySegmentIsACode(): void
    {
        $clash = DB::selectOne(
            "SELECT m.storage_key FROM media m JOIN sites s ON s.code = split_part(m.storage_key, '/', 1)
             WHERE s.id <> m.site_id LIMIT 1"
        );

        if ($clash !== null) {
            throw new RuntimeException('A media storage key starts with another website\'s new code; run the migration again to draw new codes.');
        }
    }

    private function narrowUpdateGrant(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'sites')
            ->where('column_name', '<>', 'code')
            ->orderBy('ordinal_position')
            ->pluck('column_name');

        $quoted = $columns
            ->map(fn (mixed $column): string => '"'.str_replace('"', '""', is_string($column) ? $column : '').'"')
            ->implode(', ');

        DB::statement('REVOKE UPDATE ON sites FROM app_user');
        DB::statement("GRANT UPDATE ({$quoted}) ON sites TO app_user");
    }

    /** Re-runnable: an interrupted CONCURRENTLY build leaves an INVALID index that IF NOT EXISTS would keep. */
    private function createIndexConcurrently(string $name, string $create): void
    {
        $invalid = DB::selectOne('SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid', [$name]);
        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }

        DB::statement($create);
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<string>
     */
    private function storageKeys(array $rows): array
    {
        return array_values(array_map(
            fn (mixed $row): string => is_object($row) && property_exists($row, 'storage_key') && is_string($row->storage_key) ? $row->storage_key : '?',
            $rows,
        ));
    }
};
