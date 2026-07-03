<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The storage_key is the object's address in R2 — two rows must never share one, or a write would
 * overwrite another row's content. The original media schema only enforced dedup on the full hash
 * (UNIQUE site_id+collection+hash); add an explicit UNIQUE(site_id, storage_key) so the short
 * cache-bust hash suffix (DAM: 4 hex) can never collide two different objects onto one key. The
 * media table is empty in production, so this is a safe additive constraint (no CONCURRENTLY needed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->unique(['site_id', 'storage_key']);
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropUnique(['site_id', 'storage_key']);
        });
    }
};
