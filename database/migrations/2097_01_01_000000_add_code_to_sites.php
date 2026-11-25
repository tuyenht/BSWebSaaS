<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
 * - `reserved_site_codes` (platform-wide, no RLS; the application role may only read it) holds every
 *   name no code may ever be, by reason:
 *   - `legacy_key`: an old first folder and the website that owns it, one row per owner. Owners are
 *     the websites whose existing keys use the folder, and every website (trashed ones too) for the
 *     slug of its name: the code before this one keeps uploading as `{slug(name)}/{collection}/…`
 *     until the new code is live, so those uploads must still be accepted in the deploy window. Two
 *     websites with the same name share a folder: it has two owners. A removed website's rows lose
 *     their owner (the folder stays frozen, nobody may write under it any more).
 *   - `seed_folder`: a fixed folder the repository's seeders write for whichever website they seed
 *     (`an-vinh`, `cwv`), recorded only when no website owns it yet;
 *   - `collection`: the single-site layout's first folders (MediaKey::COLLECTIONS);
 *   - `platform`: `sys`, the public folder of the platform's own files (MediaKey::SYSTEM_SEGMENT);
 *   - `deleted_site`: the code of a hard-deleted website.
 *   Unique over (code, site_id) with NULLs equal: each name once, each old folder once per owner.
 *   site_id is not a foreign key: the test suite empties `sites` with TRUNCATE … CASCADE, which would
 *   empty this list too. The AFTER DELETE trigger on sites drops the owner instead (ON DELETE SET
 *   NULL by hand), and ids are never reused.
 * - A trigger on `media` (insert, or an update of storage_key/site_id) judges every key written:
 *   1. it must be a clean relative path: ASCII segments that start with a letter or digit, joined by
 *      single slashes (storage turns `\` into `/`, drops `.` and empty segments and resolves `..`, so
 *      any other path could reach another website's objects); `_incoming/`, `_private/` and every
 *      other private `_…` folder fail here: they never hold a website's row;
 *   2. a key under the row's own website code passes;
 *   3. a key under the platform's `sys/` folder is refused;
 *   4. a key under an old folder the row's website owns passes, and one under a seeded folder;
 *   5. a key whose first folder is one of the fixed collections and equals the row's collection
 *      passes (single-site mode: `library/logo-ab12.png`);
 *   6. anything else is refused (a first folder shaped like a code with its own message), so no new
 *      key in the old layout is created and nobody files under another website's old folder.
 *   A key that passes by rule 4 under a folder with more than one owner (or a seeded one), or by
 *   rule 5, is also refused when another website's row holds it: trashing one copy would delete the
 *   other's file. That lookup takes an advisory lock on the key first, so two websites writing the
 *   same key at once are judged one after the other. It probes media's (site_id, storage_key) index
 *   once per other owner (seeded folders and collections: once per website); no platform-wide index
 *   on storage_key is added (ADR-18: media may be partitioned by site_id). Shared folders are expected
 *   to hold about no rows in production, so the probes almost never run there. The guard runs
 *   SECURITY DEFINER for that lookup across websites, which RLS hides from the application role; it
 *   reads only the key's existence.
 *
 * Pre-checks abort before anything changes: the migration role must see every row (superuser or
 * BYPASSRLS), every existing storage key must be a clean relative path, and no storage key may be
 * held by two websites or lie under the platform's `sys/` folder.
 *
 * Expand-only, one transaction with a 5-second lock timeout (it queues behind no long query on sites
 * or media; rerun once traffic is quiet). The list and the freeze come before the new column, the
 * unique index on the column before the backfill (each draw is an index probe; sites is small, so a
 * plain index build inside the transaction is fine). down() refuses once any media row is filed under
 * its website's code (a new code on the next up() would strand those files); otherwise it removes
 * everything up() added.
 */
return new class extends Migration
{
    /** up() and down() open their own transaction (the lock timeout is set inside it). */
    public $withinTransaction = false;

    private const string SITES_INDEX = 'sites_code_unique';

    /** A website code (MediaKey::CODE_PATTERN in the application). */
    private const string CODE_PATTERN = '^[0-9a-hjkmnp-tv-z]{6}$';

    /** One clean path segment: what a reserved name is. */
    private const string SEGMENT_PATTERN = '^[A-Za-z0-9][A-Za-z0-9._-]*$';

    /** A clean relative path (MediaKey::CLEAN_PATH_PATTERN in the application). */
    private const string CLEAN_KEY = '^[A-Za-z0-9][A-Za-z0-9._-]*(/[A-Za-z0-9][A-Za-z0-9._-]*)+$';

    /**
     * First folders the repository's seeders write for the website they seed: SeedAnVinhContent's
     * fixed images and the An Vinh pack's legacy_key slots (`an-vinh`), the speed-measurement site (`cwv`).
     */
    private const array SEEDED_FOLDERS = ['an-vinh', 'cwv'];

    /** Public folders of the platform's own files (MediaKey::SYSTEM_SEGMENT in the application). */
    private const array PLATFORM_FOLDERS = ['sys'];

    /** The single-site layout's first folders (MediaKey::COLLECTIONS in the application). */
    private const array COLLECTIONS = ['library', 'cover', 'og', 'product'];

    private const string RESERVED_TABLE = <<<'SQL'
        CREATE TABLE IF NOT EXISTS public.reserved_site_codes (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            code varchar(255) NOT NULL CHECK (code ~ '__SEGMENT_PATTERN__'),
            reason varchar(16) NOT NULL CHECK (reason IN ('legacy_key', 'seed_folder', 'collection', 'platform', 'deleted_site')),
            site_id bigint NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            CONSTRAINT reserved_site_codes_owner_only_on_legacy CHECK (site_id IS NULL OR reason = 'legacy_key')
        )
    SQL;

    private const string ISSUE_CODE = <<<'SQL'
        CREATE OR REPLACE FUNCTION public.issue_site_code() RETURNS varchar
        LANGUAGE plpgsql VOLATILE
        SET search_path = pg_catalog, pg_temp
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
        CREATE OR REPLACE FUNCTION public.sites_code_guard() RETURNS trigger
        LANGUAGE plpgsql
        SET search_path = pg_catalog, pg_temp
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
                NEW.code := public.issue_site_code();
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
        CREATE OR REPLACE FUNCTION public.sites_code_retire() RETURNS trigger
        LANGUAGE plpgsql SECURITY DEFINER
        SET search_path = pg_catalog, pg_temp
        AS $fn$
        BEGIN
            INSERT INTO public.reserved_site_codes (code, reason) VALUES (OLD.code, 'deleted_site')
            ON CONFLICT DO NOTHING;

            -- Its old folders stay frozen but lose their owner: one ownerless row per folder.
            DELETE FROM public.reserved_site_codes r
            WHERE r.site_id = OLD.id
                AND EXISTS (
                    SELECT 1 FROM public.reserved_site_codes o
                    WHERE o.code = r.code AND o.id <> r.id AND o.site_id IS DISTINCT FROM OLD.id
                );
            UPDATE public.reserved_site_codes SET site_id = NULL WHERE site_id = OLD.id;

            RETURN OLD;
        END
        $fn$
    SQL;

    private const string MEDIA_GUARD = <<<'SQL'
        CREATE OR REPLACE FUNCTION public.media_storage_key_prefix_guard() RETURNS trigger
        LANGUAGE plpgsql SECURITY DEFINER
        SET search_path = pg_catalog, pg_temp
        AS $fn$
        DECLARE
            first_segment text;
            is_platform boolean;
            is_mine boolean;
            is_seeded boolean;
            is_collection boolean;
            owners integer;
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

            IF EXISTS (SELECT 1 FROM public.sites WHERE id = NEW.site_id AND code = first_segment) THEN
                RETURN NEW;
            END IF;

            SELECT coalesce(bool_or(reason = 'platform'), false),
                   coalesce(bool_or(reason = 'legacy_key' AND site_id = NEW.site_id), false),
                   coalesce(bool_or(reason = 'seed_folder'), false),
                   coalesce(bool_or(reason = 'collection'), false),
                   count(*) FILTER (WHERE reason = 'legacy_key' AND site_id IS NOT NULL)
            INTO is_platform, is_mine, is_seeded, is_collection, owners
            FROM public.reserved_site_codes
            WHERE code = first_segment;

            IF is_platform THEN
                RAISE EXCEPTION 'media storage key "%" is under a folder of the platform, not of a website', NEW.storage_key
                    USING ERRCODE = 'check_violation';
            END IF;

            IF is_mine OR is_seeded OR (is_collection AND first_segment = NEW.collection) THEN
                IF is_mine AND owners = 1 AND NOT is_seeded AND NOT is_collection THEN
                    RETURN NEW;    -- the folder's only owner: no other website can hold a key under it
                END IF;

                -- A shared folder: judge two writers of the same key one after the other.
                PERFORM pg_advisory_xact_lock(hashtext(NEW.storage_key));

                IF is_mine AND NOT is_seeded AND NOT is_collection THEN
                    -- Only the folder's other owners can hold a key under it: one index probe each.
                    IF EXISTS (
                        SELECT 1 FROM public.reserved_site_codes r
                        JOIN public.media m ON m.site_id = r.site_id AND m.storage_key = NEW.storage_key
                        WHERE r.code = first_segment AND r.reason = 'legacy_key' AND r.site_id <> NEW.site_id
                    ) THEN
                        RAISE EXCEPTION 'media storage key "%" is held by another website', NEW.storage_key
                            USING ERRCODE = 'check_violation';
                    END IF;
                -- A seeded folder or a collection: any website may write there, one probe per website.
                ELSIF EXISTS (
                    SELECT 1 FROM public.sites s
                    JOIN public.media m ON m.site_id = s.id AND m.storage_key = NEW.storage_key
                    WHERE s.id <> NEW.site_id
                ) THEN
                    RAISE EXCEPTION 'media storage key "%" is held by another website', NEW.storage_key
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END IF;

            IF first_segment ~ '__CODE_PATTERN__' THEN
                RAISE EXCEPTION 'media storage key "%" starts with a website code that is not its website''s', NEW.storage_key
                    USING ERRCODE = 'check_violation';
            END IF;

            RAISE EXCEPTION 'media storage key "%" does not start with its website''s code or an old folder of it', NEW.storage_key
                USING ERRCODE = 'check_violation';
        END
        $fn$
    SQL;

    public function up(): void
    {
        DB::transaction(function (): void {
            // Fail fast instead of queueing every request behind a long-running query on sites or media.
            DB::statement("SET LOCAL lock_timeout = '5s'");

            $this->assertSeesEveryRow();
            $this->assertEveryKeyIsClean();
            $this->assertNoSharedStorageKeys();
            $this->assertNoKeyUnderPlatformFolder();

            DB::statement(str_replace('__SEGMENT_PATTERN__', self::SEGMENT_PATTERN, self::RESERVED_TABLE));
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS reserved_site_codes_code_site_unique ON public.reserved_site_codes (code, site_id) NULLS NOT DISTINCT');
            DB::statement('CREATE INDEX IF NOT EXISTS reserved_site_codes_site_id_index ON public.reserved_site_codes (site_id) WHERE site_id IS NOT NULL');
            DB::statement('REVOKE ALL ON public.reserved_site_codes FROM app_user');
            DB::statement('GRANT SELECT ON public.reserved_site_codes TO app_user');

            $this->reserveNames('platform', self::PLATFORM_FOLDERS);
            $this->reserveNames('collection', self::COLLECTIONS);
            $this->freezeLegacyFolders();

            DB::statement('ALTER TABLE public.sites ADD COLUMN IF NOT EXISTS code varchar(6)');
            DB::statement(self::ISSUE_CODE);
            $this->createUniqueIndex();
            $this->backfill();

            if (DB::selectOne("SELECT 1 FROM pg_constraint WHERE conname = 'sites_code_format'") === null) {
                DB::statement("ALTER TABLE public.sites ADD CONSTRAINT sites_code_format CHECK (code ~ '".self::CODE_PATTERN."')");
            }
            DB::statement('ALTER TABLE public.sites ALTER COLUMN code SET NOT NULL');

            DB::statement(self::SITES_GUARD);
            DB::statement('DROP TRIGGER IF EXISTS sites_code_guard ON public.sites');
            DB::statement('CREATE TRIGGER sites_code_guard BEFORE INSERT OR UPDATE OF code ON public.sites FOR EACH ROW EXECUTE FUNCTION public.sites_code_guard()');

            DB::statement(self::SITES_RETIRE);
            DB::statement('REVOKE ALL ON FUNCTION public.sites_code_retire() FROM PUBLIC');
            DB::statement('DROP TRIGGER IF EXISTS sites_code_retire ON public.sites');
            DB::statement('CREATE TRIGGER sites_code_retire AFTER DELETE ON public.sites FOR EACH ROW EXECUTE FUNCTION public.sites_code_retire()');

            DB::statement(str_replace(['__CLEAN_KEY__', '__CODE_PATTERN__'], [self::CLEAN_KEY, self::CODE_PATTERN], self::MEDIA_GUARD));
            DB::statement('REVOKE ALL ON FUNCTION public.media_storage_key_prefix_guard() FROM PUBLIC');
            DB::statement('DROP TRIGGER IF EXISTS media_storage_key_prefix_guard ON public.media');
            DB::statement(
                'CREATE TRIGGER media_storage_key_prefix_guard BEFORE INSERT OR UPDATE OF storage_key, site_id ON public.media '.
                'FOR EACH ROW EXECUTE FUNCTION public.media_storage_key_prefix_guard()'
            );
        });
    }

    public function down(): void
    {
        if (DB::selectOne("SELECT 1 FROM media m JOIN sites s ON s.id = m.site_id WHERE split_part(m.storage_key, '/', 1) = s.code LIMIT 1") !== null) {
            throw new RuntimeException(
                'Media rows are filed under website codes; dropping sites.code would orphan them. '.
                'Restore the pre-migration dump instead of rolling back.'
            );
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('DROP TRIGGER IF EXISTS media_storage_key_prefix_guard ON public.media');
            DB::statement('DROP FUNCTION IF EXISTS public.media_storage_key_prefix_guard()');
            DB::statement('DROP TRIGGER IF EXISTS sites_code_retire ON public.sites');
            DB::statement('DROP FUNCTION IF EXISTS public.sites_code_retire()');
            DB::statement('DROP TRIGGER IF EXISTS sites_code_guard ON public.sites');
            DB::statement('DROP FUNCTION IF EXISTS public.sites_code_guard()');
            DB::statement('DROP INDEX IF EXISTS public.'.self::SITES_INDEX);
            DB::statement('ALTER TABLE public.sites DROP COLUMN IF EXISTS code');
            DB::statement('DROP FUNCTION IF EXISTS public.issue_site_code()');
            DB::statement('DROP TABLE IF EXISTS public.reserved_site_codes');
        });
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

    /** @param  list<string>  $names */
    private function reserveNames(string $reason, array $names): void
    {
        foreach ($names as $name) {
            DB::insert('INSERT INTO public.reserved_site_codes (code, reason) VALUES (?, ?) ON CONFLICT DO NOTHING', [$name, $reason]);
        }
    }

    /**
     * Records each old first folder with its owners, before any code is drawn (issue_site_code() never
     * hands one out): the first folder of every existing key with the website that holds it, and the
     * slug of every website's name (trashed ones too) with that website, where the code before this one
     * keeps uploading until the new code is live. A seeded folder no website owns yet is open to the
     * website it is seeded for. A value that is already a website's code (a re-run, after uploads under
     * codes) is never frozen: rule 4 would exempt it from the guard. Platform folders are never an old
     * folder.
     */
    private function freezeLegacyFolders(): void
    {
        $codes = DB::selectOne("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'sites' AND column_name = 'code'") !== null
            ? array_flip($this->column(DB::select('SELECT code AS value FROM public.sites WHERE code IS NOT NULL')))
            : [];

        $pairs = [];
        foreach (DB::select("SELECT DISTINCT split_part(storage_key, '/', 1) AS folder, site_id FROM public.media") as $row) {
            $pairs[$this->string($row, 'folder')."\0".$this->int($row, 'site_id')] = true;
        }
        foreach (DB::select('SELECT id, name FROM public.sites') as $site) {
            $pairs[Str::slug($this->string($site, 'name'))."\0".$this->int($site, 'id')] = true;
        }

        foreach (array_keys($pairs) as $pair) {
            [$folder, $siteId] = explode("\0", (string) $pair);
            if (isset($codes[$folder]) || in_array($folder, self::PLATFORM_FOLDERS, true) || preg_match('#'.self::SEGMENT_PATTERN.'#', $folder) !== 1) {
                continue;
            }
            DB::insert("INSERT INTO public.reserved_site_codes (code, reason, site_id) VALUES (?, 'legacy_key', ?) ON CONFLICT DO NOTHING", [$folder, (int) $siteId]);
        }

        foreach (self::SEEDED_FOLDERS as $folder) {
            DB::insert(
                "INSERT INTO public.reserved_site_codes (code, reason) SELECT ?, 'seed_folder' ".
                "WHERE NOT EXISTS (SELECT 1 FROM public.reserved_site_codes WHERE code = ? AND reason = 'legacy_key') ON CONFLICT DO NOTHING",
                [$folder, $folder]
            );
        }
    }

    /** Built before the backfill, so each draw's uniqueness check is an index probe. Drops an INVALID leftover of an interrupted concurrent build. */
    private function createUniqueIndex(): void
    {
        if (DB::selectOne('SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid', ['public.'.self::SITES_INDEX]) !== null) {
            DB::statement('DROP INDEX public.'.self::SITES_INDEX);
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.self::SITES_INDEX.' ON public.sites (code)');
    }

    /** One draw per website without a code, in id order, soft-deleted ones included (each draw sees the ones before). */
    private function backfill(): void
    {
        foreach (DB::select('SELECT id FROM public.sites WHERE code IS NULL ORDER BY id') as $site) {
            DB::update('UPDATE public.sites SET code = public.issue_site_code() WHERE id = ?', [$this->int($site, 'id')]);
        }
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
