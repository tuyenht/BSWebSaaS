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
 * request host through Tenancy's public SiteDirectory. The operator host shows the platform brand.
 * A host no site owns gets null: the page is then the neutral "website not found" page, which
 * names neither a brand nor the host (a domain waiting for its proof is never announced). Any
 * failure while resolving also gives null: an error page must never raise an error of its own.
 */
final class ErrorPageBrand
{
    public static function for(Request $request): ?string
    {
        try {
            $name = $request->getHost() === config('tenancy.platform_host')
                ? config('admin.brand.name')
                : app(SiteDirectory::class)->nameForHost($request->getHost());
        } catch (Throwable) {
            return null;
        }

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    /**
     * The language of the neutral page: the address's first segment when it names one of the
     * platform's languages (`/en/…`), else Vietnamese, the platform's own fallback. No site means
     * no list of languages to ask, so only the shape and the translation files decide.
     */
    public static function locale(Request $request): string
    {
        $first = strtolower(explode('/', trim($request->path(), '/'))[0]);

        return preg_match('/^[a-z]{2}$/', $first) === 1 && is_file(lang_path($first.'.json')) ? $first : 'vi';
    }
}
