<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Database;

/**
 * A database connection that counts the statements the database refused. Tenancy::enter reads the
 * count before and after a site's work: only when it moved does it ask PostgreSQL whether the
 * transaction is still alive, so a request with no failed statement pays nothing.
 */
interface CountsFailedStatements
{
    /** How many statements failed on this connection since it was made. Only ever grows. */
    public function failedStatements(): int;

    /**
     * The SQLSTATE of the latest failed statement that was a cause, not the "transaction is
     * aborted" echo (25P02) every later statement gets. For the log line only.
     */
    public function lastFailureState(): ?string;
}
