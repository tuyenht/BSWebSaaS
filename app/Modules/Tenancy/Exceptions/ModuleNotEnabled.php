<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The current site's plan does not include the module a request goes into. A plain 403 for any
 * caller that only reads the status; the admin's error page names the module from its key.
 */
final class ModuleNotEnabled extends HttpException
{
    public function __construct(public readonly string $module)
    {
        parent::__construct(403, "Module [{$module}] is not enabled for this site.");
    }
}
