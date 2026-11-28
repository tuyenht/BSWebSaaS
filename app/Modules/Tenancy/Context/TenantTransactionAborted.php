<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

use RuntimeException;

/**
 * @internal Thrown and caught only by Tenancy::enter.
 *
 * Carries a site's work out of its transaction closure when PostgreSQL has already aborted that
 * transaction, so Laravel takes its own rollback path (after-rollback callbacks run, after-commit ones
 * are dropped, at the right savepoint when enter() is nested) instead of a COMMIT the database would
 * silently turn into a ROLLBACK. It carries no SQLSTATE code and its message names no lock or
 * deadlock, so Laravel never treats it as a concurrency error to retry.
 */
final class TenantTransactionAborted extends RuntimeException
{
    public function __construct(public readonly mixed $result, public readonly ?string $failureState)
    {
        parent::__construct('The site transaction was already aborted by a failed statement.');
    }
}
