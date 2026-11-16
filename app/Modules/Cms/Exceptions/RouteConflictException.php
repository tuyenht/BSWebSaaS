<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use App\Modules\Cms\Routing\PathBuilder;
use RuntimeException;

/**
 * Raised when an entry would be routed to a path another entry already owns
 * (routes UNIQUE(site_id, locale, path)). Callers translate it into a friendly
 * "slug taken" validation error instead of a raw DB 500. A path the platform keeps for a page of
 * its own (the site search) is refused the same way; reserved() tells the two apart, so the
 * screen can say which it is.
 */
final class RouteConflictException extends RuntimeException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct("The path '{$path}' is already used by another entry.");
    }

    /** The path is one the platform keeps for a page of its own (PathBuilder::RESERVED_PATHS), not another item's. */
    public function reserved(): bool
    {
        return PathBuilder::isReserved($this->path);
    }
}
