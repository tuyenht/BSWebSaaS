<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Per-locale short description (teaser) for catalog products: shown on every list card and
 * used as the og:description fallback, so it graduates to a real column (not products.meta,
 * which is not per-locale). Rich-basic HTML, sanitized on write like description. Nullable —
 * brochure rows may omit it. Expand-only; RLS on product_translations already covers the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_translations', function (Blueprint $table): void {
            $table->text('summary')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('product_translations', function (Blueprint $table): void {
            $table->dropColumn('summary');
        });
    }
};
