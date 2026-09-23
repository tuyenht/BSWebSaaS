<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use DomainException;

/**
 * A change to a site's languages refused for a BUSINESS reason the caller should explain in plain
 * words. Its own type so no broad `catch (RuntimeException)` swallows it. The message IS the reason
 * key, one of: locale_invalid · default_locked · limit_reached.
 */
final class SiteLocaleChangeRejectedException extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
