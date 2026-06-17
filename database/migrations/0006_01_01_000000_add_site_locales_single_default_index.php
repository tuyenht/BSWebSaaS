<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * One default locale per site (design.md §10/ADR-11). site_locales.is_default is the
 * source of truth for a site's URL-default, so a site must have AT MOST one default. A
 * partial unique on (site_id) WHERE is_default enforces it (a site may have zero during
 * setup → runtime fallback). Built CONCURRENTLY so it never locks the live table — which
 * means it CANNOT run inside a transaction ($withinTransaction = false).
 *
 * Expand-only: sites.default_locale stayed as a legacy mirror, dropped in the contract
 * migration (0007) once no runtime reader remained.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_locales_one_default ON site_locales (site_id) WHERE is_default');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS site_locales_one_default');
    }
};
