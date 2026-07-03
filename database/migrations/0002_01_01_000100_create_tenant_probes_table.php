<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Gate #1 — "isolation canary". A minimal TENANT table (has site_id + RLS) whose
 * only job is to be the thing the isolation / concurrency / RLS tests assert on,
 * permanently. It is infrastructure, NOT a business feature — so building it does
 * not violate "chưa viết logic nghiệp vụ".
 *
 * Index leads with site_id (every tenant index does — design.md §5 / §17).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_probes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('value');
            $table->timestampsTz();

            $table->index(['site_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_probes');
    }
};
