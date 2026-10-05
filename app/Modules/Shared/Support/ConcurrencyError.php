<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\DeadlockException;
use PDOException;
use Throwable;

/**
 * Tells whether a failure means the surrounding PostgreSQL transaction can no longer be used, so it
 * must reach whoever owns that transaction instead of being caught and carried on from.
 *
 * Every tenant request and per-site sweep runs in one transaction (Tenancy::enter), and work inside
 * it runs in savepoints (DB::transaction at level > 1). On a deadlock or serialization failure
 * Laravel rethrows it out of a savepoint as DeadlockException WITHOUT rolling back to that
 * savepoint, so the transaction stays aborted: a catch that reports and carries on leaves every
 * later statement failing and the final COMMIT silently rolling back everything the request or
 * site wrote. Best-effort code (audit, per-row loops) must therefore rethrow anything matched here.
 */
final class ConcurrencyError
{
    /**
     * 40001 serialization failure, 40P01 deadlock, and 25P02 "current transaction is aborted":
     * the last is not a conflict itself but the sign that an earlier failure already aborted the
     * transaction, so nothing more can run in it — carrying on is exactly as wrong.
     */
    private const array SQLSTATES = ['40001', '40P01', '25P02'];

    public static function is(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            // Laravel builds DeadlockException with code 0, dropping the SQLSTATE: matched by class.
            if ($current instanceof DeadlockException) {
                return true;
            }

            // QueryException is a PDOException carrying the SQLSTATE as its code.
            if ($current instanceof PDOException && in_array((string) $current->getCode(), self::SQLSTATES, true)) {
                return true;
            }
        }

        return self::detector()->causedByConcurrencyError($e);
    }

    /** The detector Laravel's own retry logic uses (DetectsConcurrencyErrors), so messages match too. */
    private static function detector(): ConcurrencyErrorDetectorContract
    {
        $container = Container::getInstance();

        return $container->bound(ConcurrencyErrorDetectorContract::class)
            ? $container->make(ConcurrencyErrorDetectorContract::class)
            : new ConcurrencyErrorDetector;
    }
}
