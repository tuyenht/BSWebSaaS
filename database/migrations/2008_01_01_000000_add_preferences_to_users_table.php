<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * users is a GLOBAL account table (no site_id, no RLS — one account spans the platform).
 * Per-user UI preferences (admin theme chrome: scheme/sidebar/topbar/position, plus future
 * personal settings) are a personal, cross-site choice, so they live here, never in a
 * tenant-scoped table. A constant '{}' default makes this a metadata-only add that backfills
 * existing rows with no table rewrite; fully reversible via down().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('preferences')->default('{}');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};
