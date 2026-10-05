<?php

use App\Modules\Shared\Support\ConcurrencyError;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;

/*
 * ConcurrencyError::is — the one test of "the transaction can no longer be used" shared by the
 * audit trail, the scheduler and the bulk actions: a deadlock, a serialization failure, or a
 * statement refused because the transaction is already aborted. Anything else stays an ordinary
 * failure that best-effort code may report and carry on from.
 */

/** A driver error carrying $sqlstate as its code, the way pdo_pgsql raises one. */
function pdoErrorWithState(string $sqlstate, string $message = 'SQLSTATE error'): PDOException
{
    $e = new PDOException($message);
    (new ReflectionProperty(Exception::class, 'code'))->setValue($e, $sqlstate);

    return $e;
}

function queryErrorWithState(string $sqlstate, string $message = 'SQLSTATE error'): QueryException
{
    return new QueryException('pgsql', 'insert into audit_logs …', [], pdoErrorWithState($sqlstate, $message));
}

it('recognises the DeadlockException Laravel throws out of a savepoint, though it carries code 0', function (): void {
    // ManagesTransactions builds it from the message only: the SQLSTATE is gone.
    $e = new DeadlockException('SQLSTATE[40001]: Serialization failure: 7 ERROR: could not serialize access', 0, queryErrorWithState('40001'));

    expect($e->getCode())->toBe(0)
        ->and(ConcurrencyError::is($e))->toBeTrue();
});

it('recognises a query refused for a concurrency reason by its SQLSTATE', function (string $sqlstate): void {
    // Messages that name no known conflict, so only the SQLSTATE can tell.
    expect(ConcurrencyError::is(queryErrorWithState($sqlstate, 'ERROR: raised by a trigger')))->toBeTrue();
})->with([
    'serialization failure' => '40001',
    'deadlock' => '40P01',
    'transaction already aborted' => '25P02',
]);

it('recognises a raw driver error by its SQLSTATE too', function (): void {
    expect(ConcurrencyError::is(pdoErrorWithState('40P01')))->toBeTrue();
});

it('recognises a concurrency error wrapped in another exception', function (): void {
    expect(ConcurrencyError::is(new RuntimeException('publishing failed', 0, queryErrorWithState('40P01'))))->toBeTrue();
});

it('recognises the conflict messages Laravel itself retries on', function (): void {
    expect(ConcurrencyError::is(new RuntimeException('ERROR: deadlock detected')))->toBeTrue();
});

it('leaves every other failure to the best-effort code', function (Throwable $e): void {
    expect(ConcurrencyError::is($e))->toBeFalse();
})->with([
    'a value too long' => fn (): Throwable => queryErrorWithState('22001', 'ERROR: value too long for type character varying(255)'),
    'a unique violation' => fn (): Throwable => queryErrorWithState('23505', 'ERROR: duplicate key value violates unique constraint'),
    'not a database error' => fn (): Throwable => new RuntimeException('mail transport failed'),
]);
