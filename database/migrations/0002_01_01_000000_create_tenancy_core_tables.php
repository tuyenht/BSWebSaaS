<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Gate #1 — core tenancy tables (design.md §5 / D1). GLOBAL tables (D6: no
 * site_id, no RLS): tenants, sites, domains. These are the source of truth the
 * site-resolver reads to map a request host -> site_id.
 *
 * D-rules honored: D2 (bigint PK + ulid for externally-exposed entities),
 * D11 (timestamptz everywhere), D12 (citext for the compare/unique hostname),
 * D13 (status/kind = varchar + CHECK, never native ENUM), D4 (FK cascade,
 * soft-delete only where a Trash is needed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name');
            $table->string('status')->default('active');
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('provisioning');
            $table->string('default_locale', 12)->default('vi');
            $table->unsignedBigInteger('theme_id')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('hostname');
            $table->string('kind')->default('subdomain');
            $table->boolean('is_primary')->default(false);
            $table->string('ssl_status')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();

            $table->index('site_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // D12: hostname is the canonical compare/unique column → citext (case-insensitive
        // host matching). Convert after creation, then add the unique index on citext.
        DB::statement('ALTER TABLE domains ALTER COLUMN hostname TYPE citext');
        DB::statement('ALTER TABLE domains ADD CONSTRAINT domains_hostname_unique UNIQUE (hostname)');

        // D13: status/kind = varchar + CHECK (extensible, unlike native ENUM).
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('active','suspended'))");
        DB::statement("ALTER TABLE sites ADD CONSTRAINT sites_status_check CHECK (status IN ('provisioning','active','suspended','archived'))");
        DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_kind_check CHECK (kind IN ('subdomain','custom'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
        Schema::dropIfExists('sites');
        Schema::dropIfExists('tenants');
    }
};
