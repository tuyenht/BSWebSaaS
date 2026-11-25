<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use LogicException;

/**
 * Application code tried to change a website's code (sites.code). The code is the first folder of
 * every media key the website ever wrote, so it is issued once and never changed (the database
 * refuses it too). A programming error, never something a person can trigger, hence a LogicException.
 */
final class SiteCodeIsPermanentException extends LogicException
{
    public function __construct(string $code)
    {
        parent::__construct("The website code \"{$code}\" is permanent and cannot be changed.");
    }
}
