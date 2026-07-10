<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Publication timestamp for catalog products — set when a product goes live, mirroring
 * entries.published_at. Orders the product index/grid (newest first) and backs a future publish
 * schedule. Nullable (a draft carries none). Expand-only; RLS on products already covers the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->timestampTz('published_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('published_at');
        });
    }
};
