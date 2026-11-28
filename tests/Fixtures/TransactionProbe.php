<?php

namespace Tests\Fixtures;

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * Counts the transactions the code under test opens: top-level ones, savepoints (a transaction begun
 * inside another), and the most subtransactions with their own id any transaction held as it
 * committed, as PostgreSQL itself counts them (pg_stat_get_backend_subxact; past 64 a transaction's
 * snapshot overflows and every other session pays for it). Listens from start() on.
 */
final class TransactionProbe
{
    public int $transactions = 0;

    public int $savepoints = 0;

    public int $subtransactions = 0;

    public static function start(): self
    {
        $probe = new self;
        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use ($probe): void {
            $event->connection->transactionLevel() > 1 ? $probe->savepoints++ : $probe->transactions++;
        });
        Event::listen(TransactionCommitting::class, function (TransactionCommitting $event) use ($probe): void {
            // the first read of the backend table in this transaction, so it sees the count as it is now
            $row = $event->connection->selectOne('select subxact_count as n from pg_stat_get_backend_subxact((select b from pg_stat_get_backend_idset() as b where pg_stat_get_backend_pid(b) = pg_backend_pid()))');
            if (! is_object($row) || ! is_numeric($row->n ?? null)) {
                throw new RuntimeException('The probe could not read this transaction\'s subtransaction count.');
            }
            $probe->subtransactions = max($probe->subtransactions, (int) $row->n);
        });

        return $probe;
    }
}
