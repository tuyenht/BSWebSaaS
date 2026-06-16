<?php

use App\Modules\Rbac\Context\TeamContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Arch', 'Concurrency');

/*
|--------------------------------------------------------------------------
| Query Budget helper (CLAUDE.md §6)
|--------------------------------------------------------------------------
| Counts the DB queries executed during a callback so Query Budget tests
| can assert a hard ceiling per hot route and catch N+1 regressions.
*/
function countQueries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $callback();

        return count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}

/*
|--------------------------------------------------------------------------
| Tenancy seed helper (Gate #1)
|--------------------------------------------------------------------------
| Seeds one tenant + sites A(1)/B(2) + domains a.test/b.test via the PRIVILEGED
| admin connection (so RLS does not block setup). Assertions then run on the
| default app_user connection.
*/
function seedTenancy(): void
{
    $admin = DB::connection('pgsql_admin');
    $admin->statement('truncate tenant_probes, domains, sites, tenants restart identity cascade');

    $admin->table('tenants')->insert([
        'ulid' => 'TENANT0000000000000000001', 'name' => 'T1', 'settings' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $admin->table('sites')->insert([
        ['ulid' => 'SITEA000000000000000000001', 'tenant_id' => 1, 'name' => 'A', 'status' => 'active', 'default_locale' => 'vi', 'created_at' => now(), 'updated_at' => now()],
        ['ulid' => 'SITEB000000000000000000001', 'tenant_id' => 1, 'name' => 'B', 'status' => 'active', 'default_locale' => 'vi', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $admin->table('domains')->insert([
        ['site_id' => 1, 'hostname' => 'a.test', 'kind' => 'subdomain', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'hostname' => 'b.test', 'kind' => 'subdomain', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
}

/*
|--------------------------------------------------------------------------
| RBAC reset helper (P0.3)
|--------------------------------------------------------------------------
| Clears the (global, non-RLS) authz tables + spatie cache + team-context
| override so each RBAC test starts from a deterministic, leak-free state.
*/
function truncateRbac(): void
{
    DB::connection('pgsql_admin')->statement(
        'truncate roles, permissions, model_has_roles, model_has_permissions, role_has_permissions restart identity cascade'
    );

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(TeamContext::class)->forget();
}
