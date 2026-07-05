<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Catalog (design.md §11) — brochure-mode for GĐ1. Content tier (2xxx), lands after
 * media (2006) so product_images can FK the media table.
 *
 *   products             — the catalog item; carries site_id → RLS + FORCE.
 *   product_translations — per-locale name/slug/description/seo; site_id → RLS + FORCE.
 *   product_taxonomies   — typed pivot (§9); NO site_id → parent-scoped like entry_taxonomies.
 *   product_images       — ordered gallery → media_id (§11); NO site_id → parent-scoped.
 *   routes.product_id    — the reserved FK is finally wired to products here.
 *
 * Commerce columns (sku/price/sale_price/stock…) are NULLABLE: brochure hides them, so
 * flipping a site to commerce later just fills them — the §11 shape is built once, no
 * expand-migration. Pivots have no site_id by design (D2), so they are reached ONLY through
 * the site-scoped Product; RlsCoverageTest auto-verifies the two site_id tables.
 *
 * D-rules: D2 ulid · D5 money = integer VND · D11 timestamptz · D12 citext slug ·
 * D13 status = varchar+CHECK · D15 CHECK(stock>=0) + UNIQUE(site_id, sku) · D4 soft-delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('sku')->nullable();                 // brochure: no sku
            $table->integer('price')->nullable();              // VND integer (D5); brochure: hidden
            $table->integer('sale_price')->nullable();
            $table->timestampTz('sale_starts_at')->nullable();
            $table->timestampTz('sale_ends_at')->nullable();
            $table->integer('stock')->nullable();              // brochure: no stock tracking
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_bestseller')->default(false);
            $table->string('status')->default('draft');        // draft|published|archived
            $table->jsonb('meta')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['site_id', 'status']);
        });

        Schema::create('product_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();           // rich HTML specs (prose)
            $table->string('seo_title')->nullable();
            $table->string('seo_description')->nullable();
            $table->timestampsTz();

            $table->unique(['product_id', 'locale']);
            $table->index(['site_id', 'locale']);
        });

        // Typed pivot (§9). No site_id: reached only via the site-scoped Product.
        Schema::create('product_taxonomies', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('taxonomy_id')->constrained('taxonomies')->cascadeOnDelete();
            $table->primary(['product_id', 'taxonomy_id']);
        });

        // Ordered gallery → media (§11). No site_id: parent-scoped via the Product.
        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->timestampsTz();

            $table->unique(['product_id', 'media_id']);
            $table->index(['product_id', 'position']);
        });

        // Wire the reserved routes.product_id → products (entry_id was wired in 2000).
        Schema::table('routes', function (Blueprint $table): void {
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // D12: slug = citext (case-insensitive unique within a locale).
        DB::statement('ALTER TABLE product_translations ALTER COLUMN slug TYPE citext');

        // D13/D15: enum + integrity guards (NULL-tolerant for brochure rows).
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft','published','archived'))");
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_stock_check CHECK (stock IS NULL OR stock >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_check CHECK (price IS NULL OR price >= 0)');

        // D15: SKU unique per site — partial, so many brochure rows can share a NULL sku.
        DB::statement('CREATE UNIQUE INDEX products_site_sku_unique ON products (site_id, sku) WHERE sku IS NOT NULL');

        // site_id tables → RLS + FORCE. Pivots are intentionally absent (parent-scoped).
        foreach (['products', 'product_translations'] as $table) {
            $this->enableRls($table);
        }
    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table): void {
            $table->dropForeign(['product_id']);
        });
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_taxonomies');
        Schema::dropIfExists('product_translations');
        Schema::dropIfExists('products');
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
