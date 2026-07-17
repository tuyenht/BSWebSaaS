<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Plan → feature entitlement (design.md §15.2, ABP-style). This lands the SHAPE only: billing
 * (charging for a plan) is deferred, so there is no price here — a plan is a named bundle of
 * feature grants, nothing more. Getting the shape right now avoids a retrofit later
 * (optimize-irreversible-at-birth): a site's features resolve from its tenant's plan, overridable
 * per-site, exactly the way site_modules already overrides a site_type's modules.
 *
 *   plans          — GLOBAL catalog (D6: no site_id / no RLS), referenced by tenants.plan_id.
 *   plan_features  — GLOBAL: the features a plan grants + each grant's value (bool or limit).
 *   site_features  — TENANT (site_id + RLS): a per-site override of a single feature.
 *
 * feature_key is a soft reference into the code FeatureRegistry (like a permission name), so a
 * feature can be defined/retired without a schema change. `value` is jsonb to carry either a
 * boolean (granted?) or a number (a limit) under one column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->jsonb('meta')->default('{}');
            $table->timestampsTz();
        });

        Schema::create('plan_features', function (Blueprint $table): void {
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('feature_key');
            $table->jsonb('value')->default('true');   // boolean grant or a numeric limit

            $table->primary(['plan_id', 'feature_key']);
        });

        Schema::create('site_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('feature_key');
            $table->jsonb('value')->default('true');
            $table->timestampsTz();

            $table->unique(['site_id', 'feature_key']);
        });

        // tenants.plan_id has existed as an unconstrained stub since the core tables; wire the FK
        // now that plans exists. A tenant with no plan simply falls back to registry defaults.
        Schema::table('tenants', function (Blueprint $table): void {
            $table->foreign('plan_id')->references('id')->on('plans')->nullOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // site_features is a tenant table → same RLS + FORCE + fail-closed policy as its peers.
        DB::statement('ALTER TABLE site_features ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE site_features FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY site_features_site_isolation ON site_features
                FOR ALL
                USING      (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
                WITH CHECK (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
            SQL);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropForeign(['plan_id']);
        });

        Schema::dropIfExists('site_features');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plans');
    }
};
