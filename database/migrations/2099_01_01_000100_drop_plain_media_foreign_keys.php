<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drops the seven plain foreign keys (<column>) → media (id) that 2099_01_01_000000 replaced with
 * same-site keys (site_id, <column>) → media (site_id, id). A row that matches the composite key
 * matches the plain one, and both did the same on a purge (SET NULL, or CASCADE for a gallery row),
 * so the plain keys only cost a second check on every write. Each is dropped only once its same-site
 * key exists and is validated.
 *
 * Locks: dropping a foreign key takes ACCESS EXCLUSIVE on its table and, for its triggers, on media,
 * which blocks every read for that moment. So each drop is its own short transaction that gives up
 * after 5s rather than queue storefront requests behind it; a stopped run can be repeated.
 * down() adds the seven back NOT VALID, each in its own short step, then validates each in a
 * one-statement transaction with the same 5s lock wait (the scan blocks neither reads nor writes).
 */
return new class extends Migration
{
    /** Each step is its own short transaction. */
    public $withinTransaction = false;

    /** @var array<string, array{string, string, string}> FK name => [table, column, ON DELETE action] */
    private const array PLAIN = [
        'taxonomies_cover_media_id_foreign' => ['taxonomies', 'cover_media_id', 'SET NULL'],
        'site_profiles_logo_media_id_foreign' => ['site_profiles', 'logo_media_id', 'SET NULL'],
        'site_profiles_logo_dark_media_id_foreign' => ['site_profiles', 'logo_dark_media_id', 'SET NULL'],
        'site_profiles_favicon_media_id_foreign' => ['site_profiles', 'favicon_media_id', 'SET NULL'],
        'site_profiles_og_image_media_id_foreign' => ['site_profiles', 'og_image_media_id', 'SET NULL'],
        'mediables_media_id_foreign' => ['mediables', 'media_id', 'SET NULL'],
        'product_images_media_id_foreign' => ['product_images', 'media_id', 'CASCADE'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::PLAIN as $name => [$table, $column]) {
            $sameSite = "{$table}_{$column}_same_site_foreign";
            $validated = DB::selectOne(
                'SELECT 1 FROM pg_constraint WHERE conrelid = ?::regclass AND conname = ? AND convalidated',
                [$table, $sameSite],
            );
            if ($validated === null) {
                throw new RuntimeException("Plain media keys: {$table}.{$column} has no validated {$sameSite}; run 2099_01_01_000000 first.");
            }

            $this->briefly(fn () => DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}"));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::PLAIN as $name => [$table, $column, $onDelete]) {
            $this->briefly(function () use ($name, $table, $column, $onDelete): void {
                $exists = DB::selectOne('SELECT 1 FROM pg_constraint WHERE conrelid = ?::regclass AND conname = ?', [$table, $name]);
                if ($exists === null) {
                    DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} FOREIGN KEY ({$column}) REFERENCES media (id) ON DELETE {$onDelete} NOT VALID");
                }
            });
        }

        // each validation in its own one-statement transaction, so it too gives up on a lock after 5s
        foreach (self::PLAIN as $name => [$table]) {
            $this->briefly(fn () => DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$name}"));
        }
    }

    /** One short transaction that gives up on a table lock after 5s instead of queueing requests. */
    private function briefly(Closure $step): void
    {
        DB::transaction(function () use ($step): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $step();
        });
    }
};
