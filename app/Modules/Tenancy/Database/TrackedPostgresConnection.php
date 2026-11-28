<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Database;

use Closure;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;

/**
 * The PostgreSQL connection every `pgsql` connection resolves to (TenancyServiceProvider): Laravel's
 * own, plus a count of failed statements. PostgreSQL aborts the whole transaction on the first
 * failed statement, and answers a later COMMIT with a silent ROLLBACK, so a failure the code caught
 * and swallowed would otherwise look like a commit to Laravel (its after-commit work would run).
 *
 * Every statement Laravel sends passes runQueryCallback (select, insert, update, delete, statement,
 * affectingStatement, unprepared, cursor), so every refused one is counted, inside a transaction or
 * not. Savepoints, commits and rollbacks go straight to PDO and are never counted. The count lives on
 * the connection for its whole life in a worker and is never reset: it is only ever compared as a
 * difference within one Tenancy::enter call, so no request reads another request's value.
 */
final class TrackedPostgresConnection extends PostgresConnection implements CountsFailedStatements
{
    private int $failedStatements = 0;

    private ?string $lastFailureState = null;

    public function failedStatements(): int
    {
        return $this->failedStatements;
    }

    public function lastFailureState(): ?string
    {
        return $this->lastFailureState;
    }

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     */
    #[\Override]
    protected function runQueryCallback($query, $bindings, Closure $callback): mixed
    {
        try {
            return parent::runQueryCallback($query, $bindings, $callback);
        } catch (QueryException $e) {
            $this->failedStatements++;

            // PDO's code is the SQLSTATE string, whatever the parent class declares. 25P02 is the
            // echo of an earlier failure, never its cause.
            $state = (string) $e->getCode();
            if ($state !== self::IN_FAILED_TRANSACTION) {
                $this->lastFailureState = $state;
            }

            throw $e;
        }
    }
}
