<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * When a page's own main content last changed, the content search engines read: a hidden
 * `content_changed_at` on entry_translations and product_translations. The sitemap's lastmod, a
 * post's Article dateModified and its "Cập nhật" date read it (TracksContentChange on the models
 * moves it; the readers fall back to updated_at while it is empty).
 *
 * Expand-only: a nullable timestamptz with no default is a catalog change, so no row is rewritten
 * and nothing is backfilled. An old row gets its mark the first time it is saved (from the
 * updated_at it had then), and until then the readers use its updated_at, as before this migration.
 *
 * Grants: the app role's rights are on whole tables (0000_01_01_000100), and a column added to a
 * table carries them. No index: the readers reach a translation through its unique (owner, locale)
 * key.
 *
 * Locks: each table in its own short transaction that gives up after 5s rather than queue
 * storefront reads behind it. Guarded (IF [NOT] EXISTS), so a run that stops half way can be
 * repeated. down() drops the columns; the readers then use updated_at again.
 */
return new class extends Migration
{
    /** Each table changes in its own short transaction. */
    public $withinTransaction = false;

    private const array TABLES = ['entry_translations', 'product_translations'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            $this->briefly(fn () => DB::statement("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS content_changed_at timestamptz NULL"));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            $this->briefly(fn () => DB::statement("ALTER TABLE {$table} DROP COLUMN IF EXISTS content_changed_at"));
        }
    }

    private function briefly(Closure $step): void
    {
        DB::transaction(function () use ($step): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $step();
        });
    }
};
