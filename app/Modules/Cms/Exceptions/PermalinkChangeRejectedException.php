<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use DomainException;

/**
 * A change of the site's address format («Đường dẫn») refused for a BUSINESS reason the caller
 * explains in plain words. The message IS the reason key, one of: pending (an earlier change is
 * still being applied) · off (the website may not choose its address format: `permalinks.custom`
 * is off; the stored format stays) · invalid (a section's choice or its prefix is not allowed;
 * `problems` says which, as ChangeSitePermalinks::problems() returns them).
 */
final class PermalinkChangeRejectedException extends DomainException
{
    /** @param array<string, array{code: string, other: string|null}> $problems */
    public function __construct(public readonly string $reason, public readonly array $problems = [])
    {
        parent::__construct($reason);
    }
}
