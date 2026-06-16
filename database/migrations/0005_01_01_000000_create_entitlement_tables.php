<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Entitlement (defense-in-depth layer #1 — design.md §15.2). Bảo Sơn enables
 * modules + content sections per site by plan/contract; the app enforces it before
 * RBAC + RLS. Both are tenant tables (site_id) → RLS + FORCE + fail-closed policy.
 *   • site_modules     — which modules a site may use (cms, commerce, media, …)
 *   • content_sections — dynamic content-type registry / admin nav (page|post + template)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('module_key');
            $table->boolean('enabled')->default(true);
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();

            $table->unique(['site_id', 'module_key']);
        });

        Schema::create('content_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->string('icon')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('entry_type')->nullable();          // page|post (D13 CHECK below)
            $table->unsignedBigInteger('taxonomy_id')->nullable();
            $table->string('list_template')->nullable();
            $table->string('detail_template')->nullable();
            $table->string('permission_prefix')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->timestampsTz();

            $table->unique(['site_id', 'key']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // D13: entry_type = varchar + CHECK (extensible, not native ENUM).
        DB::statement("ALTER TABLE content_sections ADD CONSTRAINT content_sections_entry_type_check CHECK (entry_type IS NULL OR entry_type IN ('page','post'))");

        foreach (['site_modules', 'content_sections'] as $table) {
            $this->enableRls($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('content_sections');
        Schema::dropIfExists('site_modules');
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
