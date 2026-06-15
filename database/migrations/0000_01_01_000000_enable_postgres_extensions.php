<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enable required PostgreSQL extensions (CLAUDE.md §1).
 * Mirrors docker/postgres/init/01-extensions.sql so CI and managed PG
 * (which do not run the docker init script) get the same extensions.
 * Must run before any tenant schema. No-op on non-pgsql (e.g. sqlite test runs).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['pgcrypto', 'ltree', 'vector', 'citext'] as $extension) {
            DB::statement("CREATE EXTENSION IF NOT EXISTS {$extension}");
        }
    }

    public function down(): void
    {
        // Intentionally left empty: dropping shared extensions could break other schemas.
    }
};
