<?php

use App\Modules\Tenancy\Database\TrackedPostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The pgsql connection counts every statement the database refused, in a transaction or not, so
 * Tenancy::enter can tell whether its transaction may have been aborted without asking the database
 * on every request. Successful statements, savepoints, commits and rollbacks never move the count.
 * (A feature test, not a unit one: it needs the real PostgreSQL connection.)
 */

function trackedConnection(): TrackedPostgresConnection
{
    $connection = DB::connection();
    expect($connection)->toBeInstanceOf(TrackedPostgresConnection::class);
    assert($connection instanceof TrackedPostgresConnection);

    return $connection;
}

function trackedConnectionFail(TrackedPostgresConnection $connection, string $sql = 'select 1/0'): void
{
    try {
        $connection->select($sql);
    } catch (QueryException) {
        // counted, then swallowed
    }
}

it('counts a failed statement outside a transaction and not a successful one', function (): void {
    $connection = trackedConnection();
    $start = $connection->failedStatements();

    $connection->select('select 1');
    expect($connection->failedStatements())->toBe($start);

    trackedConnectionFail($connection);
    expect($connection->failedStatements())->toBe($start + 1)
        ->and($connection->lastFailureState())->toBe('22012');

    $connection->select('select 2');
    expect($connection->failedStatements())->toBe($start + 1);
});

it('counts each failed statement inside a transaction, keeping the cause over the aborted echo', function (): void {
    $connection = trackedConnection();
    $start = $connection->failedStatements();

    $connection->beginTransaction();
    try {
        $connection->select('select 1');
        trackedConnectionFail($connection);
        trackedConnectionFail($connection, 'select 2');
    } finally {
        $connection->rollBack();
    }

    expect($connection->failedStatements())->toBe($start + 2)
        ->and($connection->lastFailureState())->toBe('22012');
});

it('does not count savepoints, their rollback, or commits', function (): void {
    $connection = trackedConnection();

    $connection->transaction(function () use ($connection): void {
        $start = $connection->failedStatements();

        $connection->transaction(fn () => $connection->select('select 1'));
        $connection->beginTransaction();
        $connection->rollBack();

        expect($connection->failedStatements())->toBe($start);
    });

    $before = $connection->failedStatements();
    $connection->transaction(fn () => null);
    expect($connection->failedStatements())->toBe($before);
});
