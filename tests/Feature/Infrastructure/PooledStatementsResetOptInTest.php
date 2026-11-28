<?php

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * After a test that changes a column, the suite's TestCase may close the pooler's idle server connections
 * of the app role. That is only allowed on a private scratch stack (plans/tools/pest-scratch.sh sets the
 * opt-in): on the shared dev database the same pool serves dev Octane and Horizon. This takes the opt-in
 * away (or points a database host at the dev stack, or at another scratch project), changes a column and
 * runs the cleanup: every idle server connection must still be there. Behind a pooler with the opt-in on
 * (a scratch run) it has teeth; on a direct connection (CI) or without the opt-in (a plain dev run) the
 * cleanup is off anyway and nothing can be closed.
 */

it('closes no pooled server connection of the app role unless a scratch stack opted in', function (?string $optInNow, string $appHost, string $adminHost): void {
    $admin = DB::connection('pgsql_admin');
    $seenFromApp = DB::selectOne('select inet_client_addr()::text as addr');
    $seenFromAdmin = $admin->selectOne('select inet_client_addr()::text as addr');
    $appAddress = is_object($seenFromApp) && is_string($seenFromApp->addr) ? $seenFromApp->addr : null;
    $adminAddress = is_object($seenFromAdmin) && is_string($seenFromAdmin->addr) ? $seenFromAdmin->addr : null;
    $behindPooler = $appAddress !== null && $appAddress !== $adminAddress;
    if ($behindPooler && ! is_string(getenv(TestCase::SCRATCH_STACK_ENV))) {
        // The shared dev pool also serves dev Octane and Horizon, whose idle connections come and go on
        // their own; comparing them would fail with nothing wrong. Every dataset runs on a scratch stack.
        $this->markTestSkipped('Behind the shared dev pooler: runs on a scratch stack (plans/tools/pest-scratch.sh).');
    }

    /** @return list<int> */
    $idleAppBackends = static fn (): array => $appAddress === null ? [] : array_map(
        static fn (object $row): int => (int) $row->pid,
        $admin->select(
            "select pid from pg_stat_activity where usename = ? and client_addr = ?::inet and datname = current_database() and state = 'idle' order by pid",
            [DB::connection()->getConfig('username'), $appAddress],
        ),
    );

    $before = $idleAppBackends();
    $optIn = getenv(TestCase::SCRATCH_STACK_ENV);
    putenv($optInNow === null ? TestCase::SCRATCH_STACK_ENV : TestCase::SCRATCH_STACK_ENV.'='.str_replace('{run}', is_string($optIn) ? $optIn : 'bsweb-pest-000000-000000-1', $optInNow));
    // Only the hosts the guard reads change; the connections already open keep their own.
    config()->set('database.connections.pgsql.host', $appHost === '{same}' ? config('database.connections.pgsql.host') : $appHost);
    config()->set('database.connections.pgsql_admin.host', $adminHost === '{same}' ? config('database.connections.pgsql_admin.host') : $adminHost);

    try {
        $admin->statement('create table pool_reset_opt_in_probes (id bigint primary key)');
        Closure::bind(static function (): void {
            TestCase::forgetPooledStatementsAfterColumnChange();
        }, null, TestCase::class)();
    } finally {
        $admin->statement('drop table if exists pool_reset_opt_in_probes');
        if (is_string($optIn)) {
            putenv(TestCase::SCRATCH_STACK_ENV.'='.$optIn);
        } else {
            putenv(TestCase::SCRATCH_STACK_ENV);
        }
    }

    if ($behindPooler) {
        expect($before)->not->toBe([]);
    }
    expect(array_values(array_diff($before, $idleAppBackends())))->toBe([]);
})->with([
    'no opt-in (a plain run)' => [null, '{same}', '{same}'],
    'opt-in that names no scratch project' => ['bswebsaas', '{same}', '{same}'],
    'opt-in, app host is the dev pooler' => ['{run}', 'pgbouncer', '{same}'],
    'opt-in, admin host is the dev database' => ['{run}', '{same}', 'bsweb_dev_pg'],
    'opt-in, hosts of another scratch project' => ['{run}', 'bsweb-pest-000000-000000-2-pgbouncer', 'bsweb-pest-000000-000000-2-pg'],
]);
