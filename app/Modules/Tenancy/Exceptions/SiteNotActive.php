<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The resolved site is not open: still being set up, paused or closed. Still a plain 403 for
 * every caller that only looks at the status; the admin's error page reads which of the three
 * it is, so it can say so (the site's status is a lifecycle word, never an internal detail).
 */
final class SiteNotActive extends HttpException
{
    /**
     * @param  string  $siteStatus  provisioning | suspended | archived — or 'unknown' when no site is resolved
     */
    public function __construct(public readonly string $siteStatus)
    {
        parent::__construct(403, 'This site is not active.');
    }
}
