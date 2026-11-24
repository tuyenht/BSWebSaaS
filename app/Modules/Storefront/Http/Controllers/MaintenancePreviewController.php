<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Storefront\Support\SiteMaintenance;
use App\Modules\Storefront\Support\SiteStatePage;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Chrome\StorefrontCsp;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Xem trang khách sẽ thấy» on Cài đặt › Bảo mật & Nâng cao: the maintenance page with the words
 * and reopen time last saved, whether maintenance is on or not. A signed-in member sees the website
 * as usual while it is under maintenance, so this is the only way for them to see what visitors see.
 * It lives under the admin's protected routes (signed in, a member of this site, settings.manage —
 * routes/web.php) and reads only the current site's settings. `?locale=` picks one of the site's
 * languages (the default without it; any other value is a 404). Never cached or indexed, with the
 * storefront's own Content-Security-Policy, framed only by the admin itself.
 */
final readonly class MaintenancePreviewController
{
    public function __construct(
        private SiteMaintenance $maintenance,
        private SiteStatePage $page,
        private SiteLocale $locale,
    ) {}

    public function __invoke(Request $request): Response
    {
        $locale = $request->query('locale', $this->locale->default());
        abort_unless(is_string($locale) && $this->locale->isEnabled($locale), 404);

        $response = $this->page->maintenance($request, $this->maintenance->saved(), $this->maintenance->zone(), $locale, preview: true);
        $response->headers->add([
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => StorefrontCsp::header()."; frame-ancestors 'self'",
        ]);

        return $response;
    }
}
