<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Taxonomy (design.md §9). Content tier (2xxx, after entries). Categories form a tree
 * (adjacency parent_id = source of truth; `path` ltree mirrors it for fast
 * ancestor/descendant queries via GiST). Localized name/slug in *_translations; the
 * URL path is composed from the per-locale slug chain (PathBuilder), NOT from `path`
 * (ltree is for structure/queries only).
 *
 * ALL three carry site_id → RLS + FORCE + fail-closed policy (D6: every tenant table;
 * the pivot included — auto-verified by RlsCoverageTest). D-rules: D11 timestamptz,
 * D12 citext slug, D3 typed pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxonomies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('vocabulary');                       // post_cat|product_cat|tag|…
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('list_template')->nullable();
            $table->string('detail_template')->nullable();
            $table->integer('position')->default(0);
            $table->jsonb('meta')->default('{}');
            $table->timestampsTz();

            $table->foreign('parent_id')->references('id')->on('taxonomies')->nullOnDelete();
            $table->index(['site_id', 'vocabulary']);
        });

        Schema::create('taxonomy_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taxonomy_id')->constrained('taxonomies')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('name');
            $table->string('slug');
            $table->timestampsTz();

            $table->unique(['taxonomy_id', 'locale']);
            $table->index(['site_id', 'locale', 'slug']);
        });

        Schema::create('entry_taxonomies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained('entries')->cascadeOnDelete();
            $table->foreignId('taxonomy_id')->constrained('taxonomies')->cascadeOnDelete();
            $table->integer('position')->default(0);            // order WITHIN the category (D8)
            $table->timestampsTz();

            $table->unique(['entry_id', 'taxonomy_id']);
            $table->index(['site_id', 'taxonomy_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // ltree structural path (GiST) — mirrors parent_id; URLs come from slug chain.
        DB::statement('ALTER TABLE taxonomies ADD COLUMN path ltree');
        DB::statement('CREATE INDEX taxonomies_path_gist ON taxonomies USING gist (path)');

        // D12: localized slug = citext (case-insensitive lookup within a locale).
        DB::statement('ALTER TABLE taxonomy_translations ALTER COLUMN slug TYPE citext');

        foreach (['taxonomies', 'taxonomy_translations', 'entry_taxonomies'] as $table) {
            $this->enableRls($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_taxonomies');
        Schema::dropIfExists('taxonomy_translations');
        Schema::dropIfExists('taxonomies');
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
