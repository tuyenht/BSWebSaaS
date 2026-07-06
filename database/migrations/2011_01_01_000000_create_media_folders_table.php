<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Media folders (DAM P2 / picker redesign): one flat folder level per site organizing the
 * library. media.folder_id was held at birth (2006, no FK) — this adds the target table, the FK
 * (deleting a folder never deletes files: SET NULL drops them back to the root "Tất cả ảnh"
 * view) and media.position for manual drag ordering in the picker grid.
 * Tenant table → site_id + RLS FORCE fail-closed (RlsCoverageTest picks it up automatically).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');                              // → citext below (D12)
            $table->integer('position')->default(0);             // sidebar order (D7: never "order")
            $table->timestampsTz();

            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'position']);              // folder list, display order
        });

        Schema::table('media', function (Blueprint $table): void {
            $table->foreign('folder_id')->references('id')->on('media_folders')->nullOnDelete();
            $table->integer('position')->default(0);             // manual drag order in the grid
            $table->index(['site_id', 'folder_id', 'position']); // folder-filtered grid, custom order
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE media_folders ALTER COLUMN slug TYPE citext'); // D12

        DB::statement('ALTER TABLE media_folders ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE media_folders FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY media_folders_site_isolation ON media_folders
                FOR ALL
                USING      (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
                WITH CHECK (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
            SQL);
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropForeign(['folder_id']);
            $table->dropIndex(['site_id', 'folder_id', 'position']);
            $table->dropColumn('position');
        });

        Schema::dropIfExists('media_folders');
    }
};
