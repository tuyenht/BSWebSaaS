<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Tenancy\Context\SiteDirectory;
use Illuminate\Http\Request;
use Throwable;

/**
 * The name an error page shows in its header, title and footer. Error views render after the
 * tenancy context is gone (Tenancy::enter clears it on the way out), so the site is resolved
 * again from the request host through Tenancy's public SiteDirectory. The operator host shows
 * the platform brand; an unknown host shows the bare host. Any failure while resolving also
 * falls back to the host: an error page must never raise an error of its own.
 */
final class ErrorPageBrand
{
    public static function for(Request $request): string
    {
        $host = $request->getHost();

        try {
            $name = $host === config('tenancy.platform_host')
                ? config('admin.brand.name')
                : app(SiteDirectory::class)->nameForHost($host);
        } catch (Throwable) {
            return $host;
        }

        return is_string($name) && trim($name) !== '' ? $name : $host;
    }
}
