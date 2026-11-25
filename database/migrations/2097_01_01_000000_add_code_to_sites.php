<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every website gets a permanent code, `sites.code`, and new media storage keys start with it
 * (`{code}/{slug}-{hash}.{ext}`). The old prefix was the slug of the site's name, which is not unique:
 * two websites with the same name filed their pictures in one folder, so the same file got the same
 * key and one website's trash deleted the other's picture.
 *
 * The code: six random characters from `0-9a-hjkmnp-tv-z` (no i, l, o, u), issued by the database
 * when a website row is inserted (issue_site_code(), the one place that makes codes; existing
 * websites get one here). Independent of how it is made:
 * - CHECK on the format; UNIQUE over every row, soft-deleted ones included; nobody may change it (a
 *   trigger refuses any UPDATE of the column, the privileged role too); a hard-deleted website's code
 *   goes into `reserved_site_codes`, so it is never issued again.
 * - `reserved_site_codes` (platform-wide, no site_id) also freezes the first folders of the storage
 *   keys that exist now (`an-vinh`, `co-khi-chinh-xac-an-vinh` …) and the folders the repository's
 *   seeders still write (`an-vinh`, `cwv`), and reserves `sys`, the public folder of the platform's own
 *   files (MediaKey::SYSTEM_SEGMENT; three characters, so never a code). No code is issued from it.
 *   The application role may only read it.
 * - A trigger on `media` (insert, or an update of storage_key/site_id) judges every key written:
 *   1. it must be a clean relative path: ASCII segments that start with a letter or digit, joined by
 *      single slashes (storage turns `\` into `/`, drops `.` and empty segments and resolves `..`, so
 *      any other path could reach another website's objects); `_incoming/`, `_private/` and every
 *      other private `_…` folder fail here: they never hold a website's row. `sys/` is refused too:
 *      it holds the platform's files, never a website's;
 *   2. a key under the row's own website code passes;
 *   3. a key under a frozen folder passes (old pictures, seeded demo pictures);
 *   4. a first folder shaped like a code (issued to another website or not) is refused;
 *   5. single-site mode (`media.tenant_prefix` false): a key whose first folder is the row's
 *      collection passes (`library/logo-ab12.png`);
 *   6. anything else is refused, so no new key in the old layout is created.
 *   A key that passes by rule 3 or 5 is also refused when another website's row holds it (trashing
 *   one copy would delete the other's file); a key under the row's own code cannot be held by
 *   another website. The guard runs SECURITY DEFINER for that one lookup across websites, which RLS
 *   hides from the application role; it reads only the key's existence. No platform-wide unique
 *   index on storage_key: media may later be partitioned by site_id (ADR-18), and such an index
 *   would have to include it.
 *
 * Pre-checks abort before anything changes: the migration role must see every row (superuser or
 * BYPASSRLS), every existing storage key must be a clean relative path, and no storage key may be
 * held by two websites or lie under the platform's `sys/` folder.
 *
 * Expand-only. All but the unique index on sites.code run in one transaction; the index is built
 * CONCURRENTLY afterwards (a re-run first drops an INVALID leftover). down() refuses once any media row is filed
 * under its website's code (a new code on the next up() would strand those files); otherwise it
 * removes everything up() added.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const string SITES_INDEX = 'sites_code_unique';

    /** A website code (MediaKey::CODE_PATTERN in the application). */
    private const string CODE_PATTERN = '^[0-9a-hjkmnp-tv-z]{6}$';

    /** One clean path segment: what a frozen folder is. */
    private const string SEGMENT_PATTERN = '^[A-Za-z0-9][A-Za-z0-9._-]*$';

    /** A clean relative path (MediaKey::CLEAN_PATH_PATTERN in the application). */
    private const string CLEAN_KEY = '^[A-Za-z0-9][A-Za-z0-9._-]*(/[A-Za-z0-9][A-Za-z0-9._-]*)+$';

    /**
     * First folders the repository's seeders still write: SeedAnVinhContent's fixed images and the An
     * Vinh pack's legacy_key slots (`an-vinh`), the speed-measurement site (`cwv`).
     */
    private const array SEEDED_LEGACY_FOLDERS = ['an-vinh', 'cwv'];

    /** Public folders of the platform's own files (MediaKey::SYSTEM_SEGMENT in the application). */
    private const array PLATFORM_FOLDERS = ['sys'];

    private const string ISSUE_CODE = <<<'SQL'
        CREATE OR REPLACE FUNCTION issue_site_code() RETURNS varchar
        LANGUAGE plpgsql VOLATILE
        SET search_path = pg_catalog, public
        AS $fn$
        DECLARE
            alphabet constant text := '0123456789abcdefghjkmnpqrstvwxyz';
            candidate text;
        BEGIN
            -- 32^6 ≈ 1.07 billion codes; a clash with an issued or reserved one is redrawn. Two
            -- transactions drawing the same new code at once: the unique index refuses the second.
            FOR attempt IN 1..100 LOOP
                candidate := '';
                FOR i IN 1..6 LOOP
                    candidate := candidate || substr(alphabet, 1 + floor(random() * 32)::int, 1);
                END LOOP;

                IF NOT EXISTS (SELECT 1 FROM public.sites WHERE code = candidate)
                    AND NOT EXISTS (SELECT 1 FROM public.reserved_site_codes WHERE code = candidate) THEN
                    RETURN candidate;
                END IF;
            END LOOP;

            RAISE EXCEPTION 'no free website code after 100 draws';
        END
        $fn$
    SQL;

    private const string SITES_GUARD = <<<'SQL'
        CREATE OR REPLACE FUNCTION sites_code_guard() RETURNS trigger
        LANGUAGE plpgsql
        SET search_path = pg_catalog, public
        AS $fn$
        BEGIN
            IF TG_OP = 'UPDATE' THEN
                IF NEW.code IS DISTINCT FROM OLD.code THEN
                    RAISE EXCEPTION 'the website code "%" is permanent and cannot be changed', OLD.code
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END IF;

            IF NEW.code IS NULL THEN
                NEW.code := issue_site_code();
            END IF;

            IF EXISTS (SELECT 1 FROM public.reserved_site_codes WHERE code = NEW.code) THEN
                RAISE EXCEPTION 'the website code "%" is reserved and cannot be issued', NEW.code
                    USING ERRCODE = 'check_violation';
            END IF;

            RETURN NEW;
        END
        $fn$
    SQL;

    private const string SITES_RETIRE = <<<'SQL'
        CREATE OR REPLACE FUNCTION sites_code_retire() RETURNS trigger
        LANGUAGE plpgsql SECURITY DEFINER
        SET search_path = pg_catalog, public
        AS $fn$
        BEGIN
            INSERT INTO public.reserved_site_codes (code, reason) VALUES (OLD.code, 'deleted_site')
            ON CONFLICT (code) DO NOTHING;

            RETURN OLD;
        END
        $fn$
    SQL;

    private const string MEDIA_GUARD = <<<'SQL'
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

            IF EXISTS (SELECT 1 FROM public.reserved_site_codes WHERE code = first_segment AND reason = 'platform') THEN
                RAISE EXCEPTION 'media storage key "%" is under a folder of the platform, not of a website', NEW.storage_key
                    USING ERRCODE = 'check_violation';
            END IF;
            SELECT code INTO own_code FROM public.sites WHERE id = NEW.site_id;

            IF first_segment = own_code THEN
                RETURN NEW;
            END IF;

            IF first_segment ~ '__CODE_PATTERN__'
                AND NOT EXISTS (SELECT 1 FROM public.reserved_site_codes WHERE code = first_segment AND reason = 'legacy_key') THEN
                RAISE EXCEPTION 'media storage key "%" starts with a website code that is not its website''s', NEW.storage_key
                    USING ERRCODE = 'check_violation';
            END IF;

            -- A frozen folder of old keys, or (single-site mode) the row's collection.
            IF first_segment = NEW.collection
                OR EXISTS (SELECT 1 FROM public.reserved_site_codes WHERE code = first_segment AND reason = 'legacy_key') THEN
                IF EXISTS (SELECT 1 FROM public.media WHERE storage_key = NEW.storage_key AND site_id <> NEW.site_id) THEN
                    RAISE EXCEPTION 'media storage key "%" is held by another website', NEW.storage_key
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END IF;

            RAISE EXCEPTION 'media storage key "%" does not start with its website''s code', NEW.storage_key
                USING ERRCODE = 'check_violation';
        END
        $fn$
    SQL;

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->assertSeesEveryRow();
            $this->assertEveryKeyIsClean();
            $this->assertNoSharedStorageKeys();
            $this->assertNoKeyUnderPlatformFolder();

            DB::statement(
                'CREATE TABLE IF NOT EXISTS reserved_site_codes ('.
                "code varchar(255) PRIMARY KEY CHECK (code ~ '".self::SEGMENT_PATTERN."'), ".
                "reason varchar(16) NOT NULL CHECK (reason IN ('legacy_key', 'deleted_site', 'platform')), ".
                'created_at timestamptz NOT NULL DEFAULT now())'
            );
            DB::statement('REVOKE ALL ON reserved_site_codes FROM app_user');
            DB::statement('GRANT SELECT ON reserved_site_codes TO app_user');

            DB::statement('ALTER TABLE sites ADD COLUMN IF NOT EXISTS code varchar(6)');
            DB::statement(self::ISSUE_CODE);

            foreach (self::PLATFORM_FOLDERS as $folder) {
                DB::insert("INSERT INTO reserved_site_codes (code, reason) VALUES (?, 'platform') ON CONFLICT (code) DO NOTHING", [$folder]);
            }
            $this->freezeLegacyFolders();
            $this->backfill();

            if (DB::selectOne("SELECT 1 FROM pg_constraint WHERE conname = 'sites_code_format'") === null) {
                DB::statement("ALTER TABLE sites ADD CONSTRAINT sites_code_format CHECK (code ~ '".self::CODE_PATTERN."')");
            }
            DB::statement('ALTER TABLE sites ALTER COLUMN code SET NOT NULL');

            DB::statement(self::SITES_GUARD);
            DB::statement('DROP TRIGGER IF EXISTS sites_code_guard ON sites');
            DB::statement('CREATE TRIGGER sites_code_guard BEFORE INSERT OR UPDATE OF code ON sites FOR EACH ROW EXECUTE FUNCTION sites_code_guard()');

            DB::statement(self::SITES_RETIRE);
            DB::statement('REVOKE ALL ON FUNCTION sites_code_retire() FROM PUBLIC');
            DB::statement('DROP TRIGGER IF EXISTS sites_code_retire ON sites');
            DB::statement('CREATE TRIGGER sites_code_retire AFTER DELETE ON sites FOR EACH ROW EXECUTE FUNCTION sites_code_retire()');

            DB::statement(str_replace(['__CLEAN_KEY__', '__CODE_PATTERN__'], [self::CLEAN_KEY, self::CODE_PATTERN], self::MEDIA_GUARD));
            DB::statement('REVOKE ALL ON FUNCTION media_storage_key_prefix_guard() FROM PUBLIC');
            DB::statement('DROP TRIGGER IF EXISTS media_storage_key_prefix_guard ON media');
            DB::statement(
                'CREATE TRIGGER media_storage_key_prefix_guard BEFORE INSERT OR UPDATE OF storage_key, site_id ON media '.
                'FOR EACH ROW EXECUTE FUNCTION media_storage_key_prefix_guard()'
            );
        });

        $this->createIndexConcurrently(self::SITES_INDEX, 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS '.self::SITES_INDEX.' ON sites (code)');
    }

    public function down(): void
    {
        if (DB::selectOne("SELECT 1 FROM media m JOIN sites s ON s.id = m.site_id WHERE split_part(m.storage_key, '/', 1) = s.code LIMIT 1") !== null) {
            throw new RuntimeException(
                'Media rows are filed under website codes; dropping sites.code would orphan them. '.
                'Restore the pre-migration dump instead of rolling back.'
            );
        }

        DB::statement('DROP TRIGGER IF EXISTS media_storage_key_prefix_guard ON media');
        DB::statement('DROP FUNCTION IF EXISTS media_storage_key_prefix_guard()');
        DB::statement('DROP TRIGGER IF EXISTS sites_code_retire ON sites');
        DB::statement('DROP FUNCTION IF EXISTS sites_code_retire()');
        DB::statement('DROP TRIGGER IF EXISTS sites_code_guard ON sites');
        DB::statement('DROP FUNCTION IF EXISTS sites_code_guard()');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::SITES_INDEX);
        DB::statement('ALTER TABLE sites DROP COLUMN IF EXISTS code');
        DB::statement('DROP FUNCTION IF EXISTS issue_site_code()');
        DB::statement('DROP TABLE IF EXISTS reserved_site_codes');
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
        $unclean = $this->column(DB::select('SELECT storage_key AS value FROM media WHERE storage_key !~ ? LIMIT 20', [self::CLEAN_KEY]));

        if ($unclean !== []) {
            throw new RuntimeException(
                'These media storage keys are not clean relative paths (storage would rewrite them to another path): '.
                implode(', ', array_map(fn (string $key): string => json_encode($key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '?', $unclean)).
                '. Re-key or remove those rows before running this migration.'
            );
        }
    }

    /** A website's row under the platform's folder would be refused on its next write; it is listed for repair first. */
    private function assertNoKeyUnderPlatformFolder(): void
    {
        $placeholders = implode(', ', array_fill(0, count(self::PLATFORM_FOLDERS), '?'));
        $under = $this->column(DB::select("SELECT storage_key AS value FROM media WHERE split_part(storage_key, '/', 1) IN ({$placeholders}) LIMIT 20", self::PLATFORM_FOLDERS));

        if ($under !== []) {
            throw new RuntimeException(
                'These media storage keys are under a folder reserved for the platform\'s own files: '.implode(', ', $under).
                '. Re-key those rows before running this migration.'
            );
        }
    }

    private function assertNoSharedStorageKeys(): void
    {
        $shared = $this->column(DB::select('SELECT storage_key AS value FROM media GROUP BY storage_key HAVING count(DISTINCT site_id) > 1 LIMIT 20'));

        if ($shared !== []) {
            throw new RuntimeException(
                'Two websites share these media storage keys, so their files overwrite each other: '.implode(', ', $shared).'. '.
                'Re-key one website\'s rows (or remove the duplicates) before running this migration.'
            );
        }
    }

    /**
     * Freezes the first folder of every key that exists now and the folders the seeders write, before
     * any code is drawn: issue_site_code() never hands one out. Nothing is under a code yet.
     */
    private function freezeLegacyFolders(): void
    {
        $folders = [
            ...self::SEEDED_LEGACY_FOLDERS,
            ...$this->column(DB::select("SELECT DISTINCT split_part(storage_key, '/', 1) AS value FROM media")),
        ];

        foreach (array_unique($folders) as $folder) {
            DB::insert("INSERT INTO reserved_site_codes (code, reason) VALUES (?, 'legacy_key') ON CONFLICT (code) DO NOTHING", [$folder]);
        }
    }

    /** One draw per website without a code, in id order, soft-deleted ones included (each draw sees the ones before). */
    private function backfill(): void
    {
        foreach (DB::select('SELECT id FROM sites WHERE code IS NULL ORDER BY id') as $site) {
            DB::update('UPDATE sites SET code = issue_site_code() WHERE id = ?', [$this->int($site, 'id')]);
        }
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
     * @param  array<mixed>  $rows  rows with a `value` column
     * @return list<string>
     */
    private function column(array $rows): array
    {
        return array_values(array_map(fn (mixed $row): string => $this->string($row, 'value'), $rows));
    }

    private function string(mixed $row, string $column): string
    {
        $value = is_object($row) && property_exists($row, $column) ? $row->{$column} : null;

        return is_string($value) ? $value : '';
    }

    private function int(mixed $row, string $column): int
    {
        $value = is_object($row) && property_exists($row, $column) ? $row->{$column} : null;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }
};
