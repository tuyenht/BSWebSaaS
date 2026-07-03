<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Per-site configuration (design.md §13/§14). ALL tenant tables (have site_id) →
 * RLS + FORCE + fail-closed policy, auto-verified by RlsCoverageTest.
 *   • site_locales        — enabled locales per site (i18n; vi default + en + ja, An Vinh GĐ1)
 *   • site_settings       — grouped key/value JSONB config (general|seo|scripts|…)
 *   • site_profiles       — NAP / LocalBusiness identity (1 row per site)
 *   • site_profile_translations — localized brand/address/description per locale
 *
 * D-rules: D11 timestamptz, D7 no reserved words (group → group_name), JSONB for
 * open config, decimals for geo (no float for money — these are coordinates, ok).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_locales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('locale', 12);
            $table->boolean('is_default')->default(false);
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();

            $table->unique(['site_id', 'locale']);
        });

        Schema::create('site_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('group_name');
            $table->string('key');
            $table->jsonb('value')->default('{}');
            $table->timestampsTz();

            $table->unique(['site_id', 'group_name', 'key']);
        });

        Schema::create('site_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('legal_name')->nullable();
            $table->string('brand_name')->nullable();
            $table->string('tax_code')->nullable();
            $table->string('business_license_no')->nullable();
            $table->string('representative')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('hotline')->nullable();
            $table->string('address_line')->nullable();
            $table->string('ward')->nullable();
            $table->string('district')->nullable();
            $table->string('province')->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedBigInteger('logo_media_id')->nullable();
            $table->unsignedBigInteger('favicon_media_id')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->timestampsTz();

            $table->unique('site_id'); // one profile per site
        });

        Schema::create('site_profile_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('site_profile_id')->constrained('site_profiles')->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('brand_name')->nullable();
            $table->string('short_description')->nullable();
            $table->text('description')->nullable();
            $table->string('address_line')->nullable();
            $table->timestampsTz();

            $table->unique(['site_profile_id', 'locale']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['site_locales', 'site_settings', 'site_profiles', 'site_profile_translations'] as $table) {
            $this->enableRls($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_profile_translations');
        Schema::dropIfExists('site_profiles');
        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('site_locales');
    }

    /**
     * Fail-closed site isolation: rows are visible/writable only when the GUC matches
     * (no context → zero rows). FORCE so even the table owner cannot bypass.
     */
    private function enableRls(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(<<<SQL
            CREATE POLICY {$table}_site_isolation ON {$table}
                FOR ALL
                USING      (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
                WITH CHECK (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
            SQL);
    }
};
