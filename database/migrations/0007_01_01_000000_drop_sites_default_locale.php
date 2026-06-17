<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Contract phase (design.md §10/ADR-11): drop the legacy sites.default_locale mirror now
 * that site_locales.is_default is the sole source of truth — every runtime reader was
 * repointed in the source-of-truth commit, so the column has been write-only since. It
 * carried no index/constraint (verified) — just varchar(12) NOT NULL DEFAULT 'vi'.
 *
 * down() restores the column faithfully AND backfills each site's default_locale from its
 * is_default site_locale (a site with no default keeps the column default 'vi'), so the
 * contract is truly reversible — not a lossy drop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('default_locale');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('default_locale', 12)->default('vi');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Backfill from the source of truth: each site's default = its is_default locale;
        // a site with no default keeps the column default ('vi').
        DB::statement('UPDATE sites SET default_locale = sl.locale FROM site_locales sl WHERE sl.site_id = sites.id AND sl.is_default');
    }
};
