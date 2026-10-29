<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The application may add audit rows and read them, never change or delete one: whatever takes
 * over the app's database role cannot rewrite or erase the trail of what it did. The default
 * privileges of the restricted role (0000_01_01_000100) grant UPDATE and DELETE on every table;
 * this takes them back on audit_logs alone.
 *
 * Nothing in the app updates or deletes audit rows, and audit_logs has no foreign key in either
 * direction, so no cascade needs these rights. Removing rows past the retention period, or
 * anonymising them on a data-subject request, runs on the privileged pgsql_admin connection.
 *
 * The restricted role's default privileges still grant UPDATE and DELETE on every NEW table, so a
 * migration that re-creates audit_logs or adds partitions to it must repeat this REVOKE for each.
 *
 * Re-runnable (REVOKE of a right not held is a no-op). down() grants both rights back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('REVOKE UPDATE, DELETE ON audit_logs FROM app_user');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('GRANT UPDATE, DELETE ON audit_logs TO app_user');
    }
};
