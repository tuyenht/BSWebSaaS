<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Media library (design.md §16 / ADR-17) — the platform DAM foundation: one reusable library per
 * site, R2-private, with the content-hash storage_key as the SOURCE OF TRUTH (URL derived by
 * MediaUrlResolver — no url column, so flipping the driver re-points every image with no
 * migration). Both are tenant tables (site_id) → RLS + FORCE + fail-closed policy; RlsCoverageTest
 * enforces that automatically for any site_id table.
 *   • media      — one master per asset (full-SHA-256 dedup; alt/title per-locale; w/h vs CLS; soft-delete)
 *   • mediables  — polymorphic soft attachment so one asset is reused across entries/products/banners
 * Deferred (held where useful): media_folders (ltree) — folder_id col kept; library_assets (global demo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->ulid('ulid')->unique();
            $table->string('collection')->default('library');     // {collection} of the key (library|cover|og|product…)
            $table->string('slug')->nullable();                   // {slug} (→ citext below) — SEO part of the key
            $table->string('hash', 64);                           // full SHA-256 (the key embeds [:12]) → dedup + cache-bust
            $table->string('storage_key');                        // {tenant}/{collection}/{slug}-{hash}.{ext} — SOURCE OF TRUTH; URL derived
            $table->string('disk')->default('r2');
            $table->string('original_filename')->nullable();
            $table->string('mime');
            $table->integer('size');                              // bytes (≤10MB GĐ1 → fits INTEGER)
            $table->integer('width')->nullable();                 // store w/h to reserve space (anti-CLS, CWV)
            $table->integer('height')->nullable();
            $table->jsonb('alt')->default('{}');                  // per-locale {"vi": …}
            $table->jsonb('title')->default('{}');
            $table->unsignedBigInteger('folder_id')->nullable();  // → media_folders (deferred; col held, no FK yet)
            $table->jsonb('meta')->default('{}');                 // focal point, exif, duration(video)…
            $table->unsignedBigInteger('created_by')->nullable(); // soft ref → users (global table); user-deletion is rare
            $table->timestampsTz();
            $table->softDeletesTz();                              // D4b — Thùng rác

            $table->unique(['site_id', 'collection', 'hash']);    // content dedup within a site
            $table->index(['site_id', 'created_at']);             // library list (newest-first) — indexed at birth
        });

        Schema::create('mediables', function (Blueprint $table): void {
            $table->id();
            // Soft ref: hard-purging a master NULLs the link (then the orphan sweep removes it),
            // so an attachment never blocks deleting a master. Nullable to allow the SET NULL.
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('mediable_type');
            $table->unsignedBigInteger('mediable_id');
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('collection')->default('default');     // gallery|attachments…
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['mediable_type', 'mediable_id']);
            $table->index('media_id');
            $table->unique(['media_id', 'mediable_type', 'mediable_id', 'collection']); // no duplicate attachment
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE media ALTER COLUMN slug TYPE citext'); // D12 — case-insensitive slug

        foreach (['media', 'mediables'] as $table) {
            $this->enableRls($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mediables');
        Schema::dropIfExists('media');
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
