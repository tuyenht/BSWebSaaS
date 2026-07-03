<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 301 history (design.md §16 "redirects"; §213 "sửa slug → tạo 301"). Content tier
 * (2xxx, after entries — entry_id FK). A tenant table (site_id) → RLS + FORCE +
 * fail-closed policy, auto-verified by RlsCoverageTest. Per (site, locale) an old
 * path maps to its current target; the storefront serves it before 404.
 *
 * D-rules: D11 timestamptz, D13 status_code = smallint + CHECK, D4 entry_id SET NULL
 * (history outlives its entry).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('from_path');
            $table->string('to_path');
            $table->smallInteger('status_code')->default(301);   // 301|302 (D13 CHECK below)
            $table->unsignedBigInteger('entry_id')->nullable();   // provenance; survives the entry
            $table->timestampsTz();

            $table->foreign('entry_id')->references('id')->on('entries')->nullOnDelete();
            $table->unique(['site_id', 'locale', 'from_path']);
            $table->index(['site_id', 'entry_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE redirects ADD CONSTRAINT redirects_status_code_check CHECK (status_code IN (301, 302))');

        $this->enableRls('redirects');
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
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
