<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use RuntimeException;
use Throwable;

/**
 * One address of a permalink run's chunk could not move (ReflowSitePermalinks::chunk): the chunk runs
 * with no savepoint, so its whole transaction rolls back, and the caller runs the chunk again in a new
 * one with $route skipped (reported failed with $reason). $route names the address as
 * "{column}:{id}:{locale}" (ReflowSitePermalinks::route()); the cause is the previous exception.
 */
final class PermalinkMoveFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $route,
        public readonly string $reason,
        Throwable $previous,
    ) {
        parent::__construct("The permalink run could not move '{$route}' ({$reason}).", 0, $previous);
    }
}
