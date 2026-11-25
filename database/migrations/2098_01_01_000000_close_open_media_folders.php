<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Closes the media folders the site-code migration (2097) left open, now that nothing writes a new
 * key outside a website's code: the seeders file their fixed images under the seeded website's code
 * and media:rekey-site moves the old files there.
 *
 * 1. Multi-site installs (`media.tenant_prefix` on): the seeded folders (`seed_folder`: `an-vinh`,
 *    `cwv`) and the single-site collections (`collection`: `library`, `cover`, `og`, `product`) no
 *    longer accept a key from any website. A website whose rows already sit under one becomes its
 *    owner (`legacy_key` with its id), so those rows stay as they are; a folder nobody holds a row in
 *    stays frozen without an owner (no code is ever issued as it, nobody may write under it).
 *    Single-site installs keep both: their uploads are filed under the collections.
 * 2. Every install: an owner row of an old folder (`legacy_key` with a website) under which that
 *    website holds no media row (the Trash included) is released. Those are mostly the slugs of
 *    website names, recorded only for the deploy window of the site-code change. The folder stays
 *    frozen (an ownerless row is kept when no other owner is left).
 *
 * One transaction with a 5-second lock timeout; media is locked against writes first, so no key is
 * written while its folder's owners are being judged. The media scans are one hash join each, run
 * once (old folders hold few rows; new keys are all under codes). Re-runnable.
 *
 * down() reopens the seeded folders and the collections (multi-site installs); the owner rows that
 * up() released granted nothing that is still written, so they are not recreated.
 */
return new class extends Migration
{
    /** up() and down() open their own transaction (the lock timeout is set inside it). */
    public $withinTransaction = false;

    /** The seeded folders 2097 opened to every website. */
    private const array SEEDED_FOLDERS = ['an-vinh', 'cwv'];

    /** The single-site layout's first folders (MediaKey::COLLECTIONS in the application). */
    private const array COLLECTIONS = ['library', 'cover', 'og', 'product'];

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('LOCK TABLE public.media IN SHARE ROW EXCLUSIVE MODE');
            $this->assertSeesEveryRow();

            if ($this->multiSite()) {
                $this->closeOpenFolders();
            }
            $this->releaseUnusedOwners();
        });
    }

    public function down(): void
    {
        if (! $this->multiSite()) {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $this->reopenFolders();
        });
    }

    /**
     * Seeded folders and collections → owned by the websites that hold rows under them, else
     * ownerless; the open rows go.
     */
    public function closeOpenFolders(): void
    {
        DB::insert(<<<'SQL'
            INSERT INTO public.reserved_site_codes (code, reason, site_id)
            SELECT DISTINCT r.code, 'legacy_key', m.site_id
            FROM public.reserved_site_codes r
            JOIN public.media m ON split_part(m.storage_key, '/', 1) = r.code
            WHERE r.reason IN ('seed_folder', 'collection')
            ON CONFLICT DO NOTHING
            SQL);

        $open = array_filter(DB::table('reserved_site_codes')->whereIn('reason', ['seed_folder', 'collection'])->pluck('code')->all(), is_string(...));
        DB::table('reserved_site_codes')->whereIn('reason', ['seed_folder', 'collection'])->delete();

        foreach (array_unique($open) as $code) {
            DB::insert(
                "INSERT INTO public.reserved_site_codes (code, reason) SELECT ?, 'legacy_key' ".
                'WHERE NOT EXISTS (SELECT 1 FROM public.reserved_site_codes WHERE code = ?) ON CONFLICT DO NOTHING',
                [$code, $code],
            );
        }
    }

    /** Owner rows of folders their website holds no row under → released; each folder stays frozen. */
    public function releaseUnusedOwners(): void
    {
        $unused = <<<'SQL'
            r.reason = 'legacy_key' AND r.site_id IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM public.media m WHERE m.site_id = r.site_id AND split_part(m.storage_key, '/', 1) = r.code)
            SQL;

        // Freeze each such folder without an owner first, so deleting its last owner never unfreezes it.
        DB::insert("INSERT INTO public.reserved_site_codes (code, reason) SELECT DISTINCT r.code, 'legacy_key' FROM public.reserved_site_codes r WHERE {$unused} ON CONFLICT DO NOTHING");
        DB::delete("DELETE FROM public.reserved_site_codes r WHERE {$unused}");
        // An ownerless row is kept only where no owner is left.
        DB::delete(<<<'SQL'
            DELETE FROM public.reserved_site_codes r
            WHERE r.reason = 'legacy_key' AND r.site_id IS NULL
              AND EXISTS (SELECT 1 FROM public.reserved_site_codes o WHERE o.code = r.code AND o.reason = 'legacy_key' AND o.site_id IS NOT NULL)
            SQL);
    }

    /** What 2097 recorded for them: each collection, and each seeded folder no website owns. */
    public function reopenFolders(): void
    {
        foreach (self::COLLECTIONS as $code) {
            DB::table('reserved_site_codes')->where('code', $code)->where('reason', 'legacy_key')->whereNull('site_id')->delete();
            DB::insert("INSERT INTO public.reserved_site_codes (code, reason) VALUES (?, 'collection') ON CONFLICT DO NOTHING", [$code]);
        }
        foreach (self::SEEDED_FOLDERS as $code) {
            if (DB::table('reserved_site_codes')->where('code', $code)->where('reason', 'legacy_key')->whereNotNull('site_id')->exists()) {
                continue;
            }
            DB::table('reserved_site_codes')->where('code', $code)->where('reason', 'legacy_key')->whereNull('site_id')->delete();
            DB::insert("INSERT INTO public.reserved_site_codes (code, reason) VALUES (?, 'seed_folder') ON CONFLICT DO NOTHING", [$code]);
        }
    }

    private function multiSite(): bool
    {
        return config('media.tenant_prefix', true) === true;
    }

    /** RLS would hide media rows from a restricted role, and every folder would look unused. */
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
};
