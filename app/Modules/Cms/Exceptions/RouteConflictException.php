<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use RuntimeException;

/**
 * Raised when an entry would be routed to a path another entry already owns
 * (routes UNIQUE(site_id, locale, path)). Callers translate it into a friendly
 * "slug taken" validation error instead of a raw DB 500.
 */
final class RouteConflictException extends RuntimeException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct("The path '{$path}' is already used by another entry.");
    }
}
