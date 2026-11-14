<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Exceptions;

use DomainException;

/**
 * A re-run of provisioning for a website that already exists named an owner email that is not one
 * of its owners. Provisioning never changes who owns a website: an owner is given back only by
 * `site:recover-owner` (reason required, audited, owners told by mail). Nothing is written; the
 * caller explains it in plain words.
 */
final class OwnerChangeRefusedException extends DomainException
{
    public function __construct(public readonly string $hostname)
    {
        parent::__construct("The website {$hostname} already exists; provisioning never changes its owner.");
    }
}
