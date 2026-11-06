<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Where a site's brand images live, and who last saved its settings. Expand-only: every new column
 * is nullable, so no row is rewritten and the code running before this deploy keeps working.
 *
 *   site_profiles — the four brand images as media ids: logo_media_id and favicon_media_id (they
 *       exist since 0004 and nothing wrote them), plus logo_dark_media_id (the logo on a dark
 *       background; empty means the normal logo) and og_image_media_id (the image a shared link
 *       carries). Each gets an FK to media ON DELETE SET NULL: media is hard-deleted when its trash
 *       is purged, and the image then falls away instead of pointing at nothing. A plain FK, as on
 *       taxonomies.cover_media_id: media has no (site_id, id) key to aim a same-site FK at, so the
 *       write path resolves an image by its media key through the site's scope. The lookup indexes
 *       the purge needs are built CONCURRENTLY in 2051_000100.
 *   site_settings, site_profiles — updated_by: the account that made the last save, written in the
 *       same save, FK to users ON DELETE SET NULL. Accounts are never hard-deleted, so these keys
 *       carry no index (ForeignKeyIndexCoverageTest lists them).
 *
 * The two old image columns were never written, but a value they might hold is checked first: one
 * that names no media row, or a media row of another site, is cleared, so the FK validates.
 *
 * Grants: the app role's rights are on whole tables (0000_01_01_000100), and a column added to a
 * table carries them, so app_user reads and writes the new columns with nothing granted here.
 *
 * Locks: every storefront request reads site_profiles, so no step holds its lock while it waits for
 * another table's. Each step is its own short transaction that gives up after 5s rather than queue
 * requests behind it: first the new columns of a table, committed at once; then the check above;
 * then each FK on its own, added NOT VALID (it locks the table and the one it points at, media or
 * users, and scans nothing); last each FK is validated outside any transaction, in its own
 * statement, so the scan of existing rows holds no lock that blocks reads or writes. Every step is
 * guarded (IF NOT EXISTS, a catalog check per constraint), so a run that stops half way can be
 * repeated. down() drops what up() added (the two old image columns stay, without their FKs).
 */
return new class extends Migration
{
    /** Each table changes in its own short transaction; VALIDATE runs outside them. */
    public $withinTransaction = false;

    /** Image column → its FK name. */
    private const array IMAGE_FKS = [
        'logo_media_id' => 'site_profiles_logo_media_id_foreign',
        'logo_dark_media_id' => 'site_profiles_logo_dark_media_id_foreign',
        'favicon_media_id' => 'site_profiles_favicon_media_id_foreign',
        'og_image_media_id' => 'site_profiles_og_image_media_id_foreign',
    ];

    /** Table → the FK name of its updated_by. */
    private const array SAVED_BY_FKS = [
        'site_settings' => 'site_settings_updated_by_foreign',
        'site_profiles' => 'site_profiles_updated_by_foreign',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // The columns first, committed at once: this step locks site_profiles alone.
        $this->briefly(fn () => DB::statement(<<<'SQL'
            ALTER TABLE site_profiles
                ADD COLUMN IF NOT EXISTS logo_dark_media_id bigint NULL,
                ADD COLUMN IF NOT EXISTS og_image_media_id bigint NULL,
                ADD COLUMN IF NOT EXISTS updated_by bigint NULL
            SQL));

        // Then the check, once that ALTER has committed: only ids of this site's own media stay (the
        // columns were never written). Row locks on site_profiles and a plain read of media, so
        // storefront reads go on meanwhile.
        $this->briefly(function (): void {
            foreach (['logo_media_id', 'favicon_media_id'] as $column) {
                DB::statement(<<<SQL
                    UPDATE site_profiles AS p SET {$column} = NULL
                    WHERE p.{$column} IS NOT NULL
                      AND NOT EXISTS (SELECT 1 FROM media m WHERE m.id = p.{$column} AND m.site_id = p.site_id)
                    SQL);
            }
        });
        $this->briefly(fn () => DB::statement('ALTER TABLE site_settings ADD COLUMN IF NOT EXISTS updated_by bigint NULL'));

        // Then each FK in its own step: it waits for its two tables only, and for 5s at most.
        foreach (self::IMAGE_FKS as $column => $name) {
            $this->briefly(fn () => $this->addConstraint('site_profiles', $name, "FOREIGN KEY ({$column}) REFERENCES media (id) ON DELETE SET NULL"));
        }
        foreach (self::SAVED_BY_FKS as $table => $name) {
            $this->briefly(fn () => $this->addConstraint($table, $name, 'FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL'));
        }

        foreach (self::IMAGE_FKS as $name) {
            DB::statement('ALTER TABLE site_profiles VALIDATE CONSTRAINT '.$name);
        }
        foreach (self::SAVED_BY_FKS as $table => $name) {
            DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$name}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Each constraint in its own short step (it locks its table and the one it points at), then
        // each table's columns in one more step that locks that table alone.
        foreach (self::SAVED_BY_FKS as $table => $name) {
            $this->briefly(fn () => DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}"));
        }
        foreach (self::IMAGE_FKS as $name) {
            $this->briefly(fn () => DB::statement('ALTER TABLE site_profiles DROP CONSTRAINT IF EXISTS '.$name));
        }

        $this->briefly(fn () => DB::statement('ALTER TABLE site_settings DROP COLUMN IF EXISTS updated_by'));
        $this->briefly(fn () => DB::statement(<<<'SQL'
            ALTER TABLE site_profiles
                DROP COLUMN IF EXISTS updated_by,
                DROP COLUMN IF EXISTS og_image_media_id,
                DROP COLUMN IF EXISTS logo_dark_media_id
            SQL));
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
};
