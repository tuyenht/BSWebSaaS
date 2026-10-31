<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * pg_trgm (bundled with PostgreSQL, PostgreSQL licence): GIN indexes that serve `LIKE '%…%'`,
 * used for the name and email search of the activity log (next migration). Mirrors
 * docker/postgres/init/01-extensions.sql so CI and managed PG, which do not run the init script,
 * get it too. pg_trgm is a trusted extension: the migration role needs CREATE on the database,
 * not superuser. No-op on non-pgsql (e.g. sqlite test runs).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    public function down(): void
    {
        // Intentionally left empty, like the other extensions: dropping a shared extension could
        // break other schemas, and the next migration's down() already drops the indexes using it.
    }
};
