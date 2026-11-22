<?php

declare(strict_types=1);

namespace App\Modules\Admin\Errors;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The website's admin is limited to a list of network addresses and this request came from none
 * of them (RestrictAdminAddress). A plain 403 for every caller that only reads the status; the
 * admin error page says it in the member's language and names the way back (platform support),
 * never the list itself.
 */
final class AdminAddressNotAllowed extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'The admin of this website cannot be opened from here.');
    }
}
