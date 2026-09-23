<?php

use Tests\TestCase;

/*
 * The test suite runs against a shared, never-rolled-back database: seedTenancy()/truncateRbac()
 * truncate tables with CASCADE. Two Pest runs at once (two sessions on one dev box, or a person
 * plus an agent) therefore wipe each other's rows mid-test — duplicate-key errors in rbac:sync,
 * audit rows vanishing between write and assert. TestCase takes a session-level Postgres advisory
 * lock (on the direct pgsql_admin connection — PgBouncer transaction mode would drop a session
 * lock) before the first test, so a second run waits for the first instead of corrupting it.
 */

function suiteLockPdo(): PDO
{
    $config = config('database.connections.pgsql_admin');

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
        (string) $config['username'],
        (string) $config['password'],
    );
}

it('holds the shared test-database lock for the whole run', function (): void {
    $held = suiteLockPdo()->query(sprintf(
        "select count(*) from pg_locks where locktype = 'advisory' and granted and classid = 0 and objid = %d and objsubid = 1",
        TestCase::SUITE_LOCK_KEY,
    ))->fetchColumn();

    expect((int) $held)->toBe(1);
});

it('keeps a second run out while this one is running', function (): void {
    $taken = suiteLockPdo()->query(sprintf('select pg_try_advisory_lock(%d)::int', TestCase::SUITE_LOCK_KEY))->fetchColumn();

    expect((int) $taken)->toBe(0);
});
