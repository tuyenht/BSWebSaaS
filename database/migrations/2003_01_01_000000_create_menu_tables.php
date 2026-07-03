<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Navigation menus (design.md §13). Content tier (2xxx, after taxonomy — items target
 * entries/taxonomies). An Vinh runs model-B i18n: many menus, one bound per
 * (region × locale) via menu_assignments.
 *
 *   menus                  — many per site; handle = stable key; locales JSONB (NULL = all)
 *   menu_assignments       — bind a menu into a theme region per locale (region × locale → menu)
 *   menu_items             — tree (parent_id + position); link_type ∈ entry|taxonomy|dynamic|url;
 *                            polymorphic routable_* target (D3 — no FK; index (type,id);
 *                            resolve-time orphan skip now, observer + sweep later)
 *   menu_item_translations — per-locale label (empty → routable's translated title)
 *
 * Same-site integrity: menu_assignments + menu_items + menu_item_translations reference
 * their parent through a COMPOSITE (site_id, …) FK so a row can never bind across tenants
 * (a single-column FK to id would accept a cross-site reference that RLS's WITH CHECK does
 * not catch on the foreign side). Composite FKs are CASCADE only — a composite SET NULL
 * would null site_id too — so the self-referential parent_id stays a single-column SET NULL.
 *
 * ALL four carry site_id → RLS + FORCE + fail-closed policy (auto-verified by
 * RlsCoverageTest). D-rules: D2 ulid, D3 polymorphic, D11 timestamptz, D12 citext handle,
 * D13 link_type = varchar + CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('handle');                       // stable key, e.g. 'main-vi'
            $table->string('name');
            $table->jsonb('locales')->nullable();           // ['vi','en']; NULL = all locales
            $table->jsonb('meta')->default('{}');
            $table->timestampsTz();

            $table->unique(['site_id', 'handle']);
            $table->unique(['site_id', 'id']);              // composite-FK target (same-site refs)
        });

        Schema::create('menu_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('region_key');                   // theme-declared: primary|top|footer|mobile
            $table->string('locale', 12);
            $table->unsignedBigInteger('menu_id');
            $table->timestampsTz();

            // Same-site binding (+ cascade when the menu is deleted).
            $table->foreign(['site_id', 'menu_id'])->references(['site_id', 'id'])->on('menus')->cascadeOnDelete();
            $table->unique(['site_id', 'region_key', 'locale']);   // one menu per region per locale
        });

        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->unsignedBigInteger('menu_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->integer('position')->default(0);
            $table->string('link_type');                    // entry|taxonomy|dynamic|url
            $table->string('routable_type')->nullable();    // morph alias (entry|taxonomy)
            $table->unsignedBigInteger('routable_id')->nullable();
            $table->string('url')->nullable();              // external link
            $table->string('dynamic_key')->nullable();      // computed link, e.g. 'home'
            $table->jsonb('meta')->default('{}');
            $table->timestampsTz();

            $table->unique(['site_id', 'id']);              // composite-FK target (translations)
            // Same-site: item belongs to a menu in its own site (+ cascade with the menu).
            $table->foreign(['site_id', 'menu_id'])->references(['site_id', 'id'])->on('menus')->cascadeOnDelete();
            // Self-reference for nesting; a deleted parent orphans (SET NULL) its children.
            // Single-column on purpose: a composite SET NULL would also null site_id.
            $table->foreign('parent_id')->references('id')->on('menu_items')->nullOnDelete();

            $table->index(['site_id', 'menu_id', 'parent_id', 'position']);   // ordered tree fetch
            $table->index(['site_id', 'routable_type', 'routable_id']);       // D3 (type,id) + orphan sweep
        });

        Schema::create('menu_item_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->unsignedBigInteger('menu_item_id');
            $table->string('locale', 12);
            $table->string('label');
            $table->timestampsTz();

            $table->foreign(['site_id', 'menu_item_id'])->references(['site_id', 'id'])->on('menu_items')->cascadeOnDelete();
            $table->unique(['menu_item_id', 'locale']);
            $table->index(['site_id', 'locale']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // D12: handle = citext (case-insensitive stable key within a site).
        DB::statement('ALTER TABLE menus ALTER COLUMN handle TYPE citext');

        // D13: link_type = varchar + CHECK.
        DB::statement("ALTER TABLE menu_items ADD CONSTRAINT menu_items_link_type_check CHECK (link_type IN ('entry','taxonomy','dynamic','url'))");

        // A menu item carries EXACTLY one kind of target for its link_type (mirrors routes_single_target).
        DB::statement(<<<'SQL'
            ALTER TABLE menu_items ADD CONSTRAINT menu_items_target_check CHECK (
                (link_type IN ('entry','taxonomy') AND routable_type IS NOT NULL AND routable_id IS NOT NULL AND url IS NULL AND dynamic_key IS NULL)
                OR (link_type = 'url'     AND url IS NOT NULL         AND routable_type IS NULL AND routable_id IS NULL AND dynamic_key IS NULL)
                OR (link_type = 'dynamic' AND dynamic_key IS NOT NULL AND routable_type IS NULL AND routable_id IS NULL AND url IS NULL)
            )
            SQL);

        foreach (['menus', 'menu_assignments', 'menu_items', 'menu_item_translations'] as $table) {
            $this->enableRls($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_translations');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menu_assignments');
        Schema::dropIfExists('menus');
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
