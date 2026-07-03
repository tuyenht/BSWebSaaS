<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * site_type axis (design.md §15.2 / §17 D6). A site's functional shape — its module set +
 * role-template set — is keyed by site_type; GĐ1 ships only `corporate` (An Vinh = B2B cơ khí).
 *
 *   • site_types               — GLOBAL catalog (no site_id, no RLS): platform-writable only.
 *   • sites.site_type_id       — nullable FK; LIVE inheritance (edit the type, every site follows).
 *   • roles.forked_from_role_id — nullable self-FK; set once a role is copied-on-write off its
 *                                 template (additive app column — spatie core + team-scoping untouched).
 *
 * Expand-safe + reversible: create site_types FIRST, then the additive nullable FKs, then a one-shot
 * backfill of pre-existing sites → corporate. down() drops the two FK columns BEFORE the table (FK
 * order) and does NOT reverse the backfill (new columns have no prior value to restore).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_types', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();          // snake_case (D7); joins to the code registries
            $table->jsonb('label')->default('{}');     // {vi,en,ja}
            $table->jsonb('meta')->default('{}');
            $table->timestampsTz();                    // D11
        });

        // Seed the only GĐ1 type. Raw catalog write inside the privileged migration — never routes
        // through the app-layer platform.site_types.manage gate.
        $corporateId = DB::table('site_types')->insertGetId([
            'key' => 'corporate',
            'label' => '{"vi":"Doanh nghiệp","en":"Corporate","ja":"コーポレート"}',
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('sites', function (Blueprint $table): void {
            $table->foreignId('site_type_id')->nullable()->constrained('site_types')->nullOnDelete();
            $table->index('site_type_id');
        });

        // One-shot backfill: every site that predates the type axis becomes corporate.
        DB::table('sites')->whereNull('site_type_id')->update(['site_type_id' => $corporateId]);

        Schema::table('roles', function (Blueprint $table): void {
            $table->foreignId('forked_from_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->index('forked_from_role_id');
        });
    }

    public function down(): void
    {
        // Drop the FK columns BEFORE the table they reference (correct FK teardown order).
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('forked_from_role_id');
        });

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('site_type_id');
        });

        Schema::dropIfExists('site_types');
    }
};
