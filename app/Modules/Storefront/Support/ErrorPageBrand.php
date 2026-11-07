<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Tenancy\Context\SiteDirectory;
use Illuminate\Http\Request;
use Throwable;

/**
 * The name the plain error page shows in its header, title and footer. A storefront page that is
 * not found never gets here: the storefront answers it with the site's own 404 page, inside the
 * site's context. The plain page is for what has no site page to show: an unknown host, the
 * operator host, a scanner's path, a missing sitemap page or form, and errors raised outside a
 * site's context. It cannot rely on a site context, so the site is resolved again from the
 * request host through Tenancy's public SiteDirectory. The operator host shows
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
