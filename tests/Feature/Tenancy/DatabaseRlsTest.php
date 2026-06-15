<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Gate #1 — DB-layer isolation ("lưới an toàn số 1"). Proves Row-Level Security is
 * REALLY enforced: the runtime connects as app_user (NOSUPERUSER/NOBYPASSRLS), so a
 * forgotten app-level scope still cannot leak across tenants. Seeding uses the
 * privileged pgsql_admin connection; assertions run on the default (app_user) one.
 */

/** Run a callback with app.current_site_id set for the current transaction (= SET LOCAL). */
function asSite(int $siteId, Closure $fn): mixed
{
    return DB::transaction(function () use ($siteId, $fn) {
        DB::select("select set_config('app.current_site_id', ?, true)", [(string) $siteId]);

        return $fn();
    });
}

beforeEach(function () {
    $admin = DB::connection('pgsql_admin');
    $admin->statement('truncate tenant_probes, domains, sites, tenants restart identity cascade');
    $admin->table('tenants')->insert(['ulid' => 'TENANT0000000000000000001', 'name' => 'T1', 'settings' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $admin->table('sites')->insert([
        ['ulid' => 'SITEA000000000000000000001', 'tenant_id' => 1, 'name' => 'A', 'status' => 'active', 'default_locale' => 'vi', 'created_at' => now(), 'updated_at' => now()],
        ['ulid' => 'SITEB000000000000000000001', 'tenant_id' => 1, 'name' => 'B', 'status' => 'active', 'default_locale' => 'vi', 'created_at' => now(), 'updated_at' => now()],
    ]);
    // Seed canary rows as the privileged role (bypasses RLS for setup only).
    $admin->table('tenant_probes')->insert([
        ['site_id' => 1, 'value' => 'a-secret', 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'value' => 'b-secret', 'created_at' => now(), 'updated_at' => now()],
    ]);
});

it('shows a site only its own rows even via raw queries (RLS, not app scope)', function () {
    $aSeen = asSite(1, fn () => DB::table('tenant_probes')->pluck('value')->all());
    $bSeen = asSite(2, fn () => DB::table('tenant_probes')->pluck('value')->all());

    expect($aSeen)->toBe(['a-secret']);
    expect($bSeen)->toBe(['b-secret']);
});

it('is fail-closed: no site context → zero rows (never all rows)', function () {
    expect(DB::table('tenant_probes')->count())->toBe(0);
});

it('blocks cross-site writes via WITH CHECK', function () {
    asSite(1, function () {
        DB::table('tenant_probes')->insert(['site_id' => 2, 'value' => 'cross', 'created_at' => now(), 'updated_at' => now()]);
    });
})->throws(QueryException::class);

it('runs as a NOSUPERUSER / NOBYPASSRLS role (otherwise RLS is theatre)', function () {
    $role = DB::selectOne('select rolsuper::int as super, rolbypassrls::int as bypass from pg_roles where rolname = current_user');

    expect((int) $role->super)->toBe(0)
        ->and((int) $role->bypass)->toBe(0);
});
