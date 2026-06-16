<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * audit_logs — append-only audit trail (design.md §29). GLOBAL/platform (D6): it
 * carries a nullable site_id for context but is NOT RLS-protected — operators need
 * cross-site visibility and writes are best-effort (may occur for platform/login
 * actions with no tenant context). Tenant-scoped views filter by site_id in-app.
 * Documented as an exception in RlsCoverageTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('request_id')->nullable();           // X-Request-Id correlation
            $table->unsignedBigInteger('actor_id')->nullable(); // user id (no FK: survives user deletion)
            $table->string('actor_label')->nullable();          // denormalized email/name
            $table->unsignedBigInteger('site_id')->nullable();  // site context (nullable = platform)
            $table->string('action');                           // taxonomy: domain.entity.action
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->timestampTz('created_at')->nullable();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['site_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
