<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Content engine (design.md §8/§10). Content tier (2xxx). ALL tenant tables
 * (site_id) → RLS + FORCE + fail-closed policy (site_id only; soft-delete is an
 * app-layer concern so Trash can withTrashed) — auto-verified by RlsCoverageTest.
 *
 *   entries            — type page|post (product = own table); status lifecycle
 *   entry_translations — per-locale title/slug/body; body = Page-JSON (page) or
 *                        {"html":…} (post). UNIQUE(entry_id, locale).
 *   routes             — TYPED full path → exactly one target (entry/product/taxonomy);
 *                        UNIQUE(site_id, locale, path). product/taxonomy FKs land with
 *                        those tables; only entry_id is wired now.
 *
 * D-rules: D2 ulid, D11 timestamptz, D12 citext slug, D13 status/type = varchar+CHECK,
 * D4 soft-delete + cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entries', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('type');                         // page|post
            $table->string('status')->default('draft');     // draft|pending|published|archived
            $table->string('workflow_state')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('template_override')->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->unsignedBigInteger('cover_media_id')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->integer('position')->default(0);
            $table->jsonb('meta')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('parent_id')->references('id')->on('entries')->nullOnDelete();
            $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->index(['site_id', 'type', 'status']);
            $table->index(['site_id', 'parent_id']);
        });

        Schema::create('entry_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('entries')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('title');
            $table->string('slug');
            $table->jsonb('body')->default('{}');
            $table->string('excerpt')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description')->nullable();
            $table->unsignedBigInteger('og_image_id')->nullable();
            $table->string('status')->default('draft');     // per-locale translation status
            $table->timestampsTz();

            $table->unique(['entry_id', 'locale']);
            $table->index(['site_id', 'locale']);
        });

        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('path');
            $table->unsignedBigInteger('entry_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('taxonomy_id')->nullable();
            $table->timestampsTz();

            $table->foreign('entry_id')->references('id')->on('entries')->cascadeOnDelete();
            $table->unique(['site_id', 'locale', 'path']);
            $table->index(['site_id', 'entry_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // D12: slug = citext (case-insensitive compare/unique within a locale).
        DB::statement('ALTER TABLE entry_translations ALTER COLUMN slug TYPE citext');

        // D13: enums via varchar + CHECK.
        DB::statement("ALTER TABLE entries ADD CONSTRAINT entries_type_check CHECK (type IN ('page','post'))");
        DB::statement("ALTER TABLE entries ADD CONSTRAINT entries_status_check CHECK (status IN ('draft','pending','published','archived'))");
        DB::statement("ALTER TABLE entry_translations ADD CONSTRAINT entry_translations_status_check CHECK (status IN ('draft','pending','published','archived'))");

        // D3: a route points at EXACTLY one target.
        DB::statement('ALTER TABLE routes ADD CONSTRAINT routes_single_target CHECK (num_nonnulls(entry_id, product_id, taxonomy_id) = 1)');

        foreach (['entries', 'entry_translations', 'routes'] as $table) {
            $this->enableRls($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('routes');
        Schema::dropIfExists('entry_translations');
        Schema::dropIfExists('entries');
    }

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
