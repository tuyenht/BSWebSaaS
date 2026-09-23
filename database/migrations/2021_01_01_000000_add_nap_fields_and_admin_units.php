<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business identity (NAP) belongs in site_profiles + its translations (design.md §14, decision
 * 24/09 Q1). This adds what §14 lists and the table does not have yet, and the two-level
 * administrative units Vietnam has used since 01/07/2025 (34 provinces/cities, ~3,300 wards and
 * communes — district level abolished).
 *
 *  - provinces, wards: GLOBAL reference catalogs (D6 lists both; no site_id, no RLS), bigint PK
 *    (D2) + the official code, unique. Written only by the import command; `valid_to` lets a
 *    future reorganisation retire a unit without breaking the profiles that still point at it.
 *  - site_profiles (shared by every language): social_links, business_hours (structured, for
 *    schema.org), license_issued_at, moit_registered + moit_url (Bộ Công Thương badge), ward_id +
 *    province_id (§14's two-level address; the free-text ward/district/province stay until every
 *    site has codes — contract later).
 *  - site_profile_translations (per language): legal_name, tagline, representative,
 *    opening_hours_text, license_text — the text a visitor reads, in their language.
 *
 * Expand-only: every new column is nullable or has a constant default (catalog-only change, no
 * rewrite). down() drops exactly what up() added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provinces', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 120);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
        });

        Schema::create('wards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('province_id')->constrained('provinces')->restrictOnDelete();
            $table->string('code', 10)->unique();
            $table->string('name', 120);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();

            $table->index(['province_id', 'name']);   // "wards of this province", alphabetical
        });

        Schema::table('site_profiles', function (Blueprint $table): void {
            $table->jsonb('social_links')->default('{}');
            $table->jsonb('business_hours')->nullable();
            $table->date('license_issued_at')->nullable();
            $table->boolean('moit_registered')->default(false);
            $table->string('moit_url')->nullable();
            $table->foreignId('province_id')->nullable()->constrained('provinces')->nullOnDelete();
            $table->foreignId('ward_id')->nullable()->constrained('wards')->nullOnDelete();
        });

        Schema::table('site_profile_translations', function (Blueprint $table): void {
            $table->string('legal_name')->nullable();
            $table->string('tagline')->nullable();
            $table->string('representative')->nullable();
            $table->string('opening_hours_text')->nullable();
            $table->string('license_text')->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // That a profile's ward lies in its province is the writer's check (it only offers that
        // province's wards). citext keeps name lookups accent-exact but case-insensitive.
        DB::statement('ALTER TABLE provinces ALTER COLUMN name TYPE citext');
        DB::statement('ALTER TABLE wards ALTER COLUMN name TYPE citext');
    }

    public function down(): void
    {
        Schema::table('site_profile_translations', function (Blueprint $table): void {
            $table->dropColumn(['legal_name', 'tagline', 'representative', 'opening_hours_text', 'license_text']);
        });

        Schema::table('site_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ward_id');
            $table->dropConstrainedForeignId('province_id');
            $table->dropColumn(['social_links', 'business_hours', 'license_issued_at', 'moit_registered', 'moit_url']);
        });

        Schema::dropIfExists('wards');
        Schema::dropIfExists('provinces');
    }
};
