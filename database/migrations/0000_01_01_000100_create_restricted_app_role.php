<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Gate #1 — restricted application role (the foundation that makes RLS REAL).
 *
 * Postgres bypasses RLS entirely for SUPERUSER / BYPASSRLS roles — and even for a
 * table's OWNER unless FORCE ROW LEVEL SECURITY is set. The default Docker/CI role
 * (bsweb) is a superuser, so connecting the app as bsweb makes RLS theatre.
 *
 * Fix: the app RUNTIME connects as `app_user` (NOSUPERUSER, NOBYPASSRLS) → RLS is
 * enforced. MIGRATIONS run as the privileged owner via the `pgsql_admin` connection
 * (which creates this role). Run: `php artisan migrate --database=pgsql_admin`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Role creation, attributes and grants are fully static (literal SQL).
        DB::unprepared(<<<'SQL'
            DO $do$
            BEGIN
              IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'app_user') THEN
                CREATE ROLE app_user LOGIN;
              END IF;
            END
            $do$;

            ALTER ROLE app_user WITH LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;

            GRANT USAGE ON SCHEMA public TO app_user;
            GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO app_user;
            GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO app_user;

            ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_user;
            ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO app_user;
        SQL);

        // The password is the only dynamic part. It comes from our own config (not
        // user input) and single quotes are doubled, so the interpolation is safe;
        // DDL cannot bind parameters for a role password.
        $configured = config('database.connections.pgsql.password');
        $password = str_replace("'", "''", is_string($configured) ? $configured : '');

        // @phpstan-ignore argument.type (escaped, trusted config value — see note above)
        DB::unprepared("ALTER ROLE app_user PASSWORD '{$password}'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM app_user;
            ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE USAGE, SELECT ON SEQUENCES FROM app_user;
            REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM app_user;
            REVOKE ALL ON ALL TABLES IN SCHEMA public FROM app_user;
            REVOKE USAGE ON SCHEMA public FROM app_user;
        SQL);
        // The role itself is intentionally NOT dropped (other sessions may hold it).
    }
};
