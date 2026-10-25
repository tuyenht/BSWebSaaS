<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every website gets a permanent code, `sites.code`: 8 characters from `0-9a-hjkmnp-tv-z` (lowercase
 * Crockford base32, no i l o u). New media storage keys start with it (`{code}/…`). The old prefix was
 * the slug of the site name, which is not unique: two websites with the same name shared a prefix and
 * could overwrite or delete each other's files.
 *
 * - The database issues the code (DEFAULT generate_site_code()), so raw inserts get one too. The
 *   generator skips any code already held (soft-deleted sites included), any lower-cased site name, any
 *   collection name and any first segment of an existing storage key, so a code can never collide with
 *   a legacy key prefix. Site::freshCode() adds the exact Str::slug(name) check the database cannot do.
 * - CHECK on the format; UNIQUE over every row (a soft-deleted site keeps its code, so it is never
 *   issued again).
 * - The application role loses UPDATE on the column. Column privileges only narrow a table that has no
 *   table-level grant, so the table-level UPDATE is revoked and every OTHER column is granted back:
 *   a column added to `sites` later needs its own `GRANT UPDATE (column) ON sites TO app_user`.
 * - A trigger on `media` (BEFORE INSERT, or UPDATE of storage_key/site_id) refuses a key under another
 *   website's code, a staging key (`_…`) and an empty key. Keys under the row's own code always pass.
 *   Legacy keys (the name-slug layout) are still accepted when their first segment is not any site's
 *   code: existing rows and An Vinh's seeded images keep working until they are re-keyed, after which
 *   the legacy branch is removed and every key must start with its own site's code.
 *
 * Pre-checks abort before anything changes: the migration role must see every row (superuser or
 * BYPASSRLS — media has FORCE ROW LEVEL SECURITY, and the generator's legacy-segment check runs with
 * the migration role's rights), and no storage key may be shared by two websites.
 *
 * Expand-only. All but the unique index run in one transaction; the index is built CONCURRENTLY
 * afterwards, so a re-run first drops an INVALID leftover. down() removes everything up() added.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const string INDEX = 'sites_code_unique';

    private const string GENERATOR = <<<'SQL'
        CREATE OR REPLACE FUNCTION generate_site_code() RETURNS varchar
        LANGUAGE plpgsql VOLATILE SECURITY DEFINER
        SET search_path = pg_catalog, public
        AS $fn$
        DECLARE
            alphabet constant text := '0123456789abcdefghjkmnpqrstvwxyz';
            candidate text;
        BEGIN
            FOR attempt IN 1..64 LOOP
                candidate := '';
                FOR position IN 1..8 LOOP
                    candidate := candidate || substr(alphabet, 1 + floor(random() * 32)::int, 1);
                END LOOP;

                CONTINUE WHEN EXISTS (SELECT 1 FROM public.sites WHERE code = candidate OR lower(name) = candidate);
                CONTINUE WHEN EXISTS (
                    SELECT 1 FROM public.media
                    WHERE split_part(storage_key, '/', 1) = candidate OR collection = candidate
                );

                RETURN candidate;
            END LOOP;

            RAISE EXCEPTION 'no free site code after 64 attempts';
        END
        $fn$;

        REVOKE ALL ON FUNCTION generate_site_code() FROM PUBLIC;
        GRANT EXECUTE ON FUNCTION generate_site_code() TO app_user;
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

            SELECT code INTO own_code FROM public.sites WHERE id = NEW.site_id;

            IF own_code IS NOT NULL AND NEW.storage_key LIKE own_code || '/_%' THEN
                RETURN NEW;
            END IF;

            first_segment := split_part(NEW.storage_key, '/', 1);

            IF first_segment = '' OR left(first_segment, 1) = '_' THEN
                RAISE EXCEPTION 'media storage key "%" is not a media key (empty or staging prefix)', NEW.storage_key
                    USING ERRCODE = 'check_violation';
            END IF;

            IF EXISTS (SELECT 1 FROM public.sites WHERE code = first_segment) THEN
                RAISE EXCEPTION 'media storage key "%" does not start with the code of its own site', NEW.storage_key
                    USING ERRCODE = 'check_violation';
            END IF;

            -- Legacy name-slug layout: accepted until every row is re-keyed under its site code.
            RETURN NEW;
        END
        $fn$;

        REVOKE ALL ON FUNCTION media_storage_key_prefix_guard() FROM PUBLIC;

        DROP TRIGGER IF EXISTS media_storage_key_prefix_guard ON media;
        CREATE TRIGGER media_storage_key_prefix_guard
            BEFORE INSERT OR UPDATE OF storage_key, site_id ON media
            FOR EACH ROW EXECUTE FUNCTION media_storage_key_prefix_guard();
    SQL;

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->assertSeesEveryRow();
            $this->assertNoSharedStorageKeys();

            DB::statement('ALTER TABLE sites ADD COLUMN IF NOT EXISTS code varchar(8)');
            DB::unprepared(self::GENERATOR);
            DB::statement('ALTER TABLE sites ALTER COLUMN code SET DEFAULT generate_site_code()');

            $this->backfill();

            if (DB::selectOne("SELECT 1 FROM pg_constraint WHERE conname = 'sites_code_format'") === null) {
                DB::statement("ALTER TABLE sites ADD CONSTRAINT sites_code_format CHECK (code ~ '^[0-9a-hjkmnp-tv-z]{8}$')");
            }
            DB::statement('ALTER TABLE sites ALTER COLUMN code SET NOT NULL');

            $this->assertNoLegacySegmentIsACode();
            $this->narrowUpdateGrant();

            DB::unprepared(self::PREFIX_GUARD);
        });

        $invalid = DB::selectOne(
            'SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid',
            [self::INDEX],
        );
        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        }

        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX.' ON sites (code)');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS media_storage_key_prefix_guard ON media');
        DB::statement('DROP FUNCTION IF EXISTS media_storage_key_prefix_guard()');
        DB::statement('GRANT UPDATE ON sites TO app_user');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
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

    private function assertNoSharedStorageKeys(): void
    {
        $shared = DB::select('SELECT storage_key FROM media GROUP BY storage_key HAVING count(DISTINCT site_id) > 1 LIMIT 20');

        if ($shared !== []) {
            $keys = implode(', ', array_map(
                fn (mixed $row): string => is_object($row) && property_exists($row, 'storage_key') && is_string($row->storage_key) ? $row->storage_key : '?',
                $shared,
            ));

            throw new RuntimeException(
                "Two websites share these media storage keys, so their files overwrite each other: {$keys}. ".
                'Re-key one website\'s rows (or remove the duplicates) before running this migration.'
            );
        }
    }

    /** One statement per site, so each new code is visible to the generator when it draws the next. */
    private function backfill(): void
    {
        $nameSlugs = [];
        foreach (DB::table('sites')->pluck('name') as $name) {
            $nameSlugs[Str::slug(is_string($name) ? $name : '')] = true;
        }

        foreach (DB::table('sites')->whereNull('code')->orderBy('id')->pluck('id') as $id) {
            $code = null;
            for ($attempt = 0; $attempt < 16 && ($code === null || isset($nameSlugs[$code])); $attempt++) {
                $drawn = DB::scalar('SELECT generate_site_code()');
                $code = is_string($drawn) ? $drawn : null;
            }

            if ($code === null || isset($nameSlugs[$code])) {
                throw new RuntimeException('Could not draw a free site code; run the migration again.');
            }

            DB::table('sites')->where('id', $id)->update(['code' => $code]);
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
};
