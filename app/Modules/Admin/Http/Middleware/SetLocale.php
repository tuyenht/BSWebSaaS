<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Support\Locales;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\SiteLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the admin UI locale for the request. An explicit switch (session `locale`) always wins,
 * then the signed-in member's saved language (users.preferences.locale); otherwise the admin opens in the CURRENT SITE's default language, so each tenant's operators get
 * their own language rather than the platform default (multi-tenant correct). On the platform console
 * there is no site context, so SiteLocale falls back to 'vi'. A resolved value is only applied when it
 * ships an admin translation (Locales::codes) — otherwise the app default stands. SetCurrentSite runs
 * before this middleware on the tenant planes, so the site is already resolved here.
 */
final class SetLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale');

        if (is_string($locale) && in_array($locale, Locales::codes(), true)) {
            app()->setLocale($locale); // an explicit language switch always wins

            return $next($request);
        }

        // No choice in this session → the member's own saved language (My account → Ngôn ngữ).
        $member = $request->user();
        $saved = $member instanceof User ? ($member->preferences['locale'] ?? null) : null;
        if (is_string($saved) && in_array($saved, Locales::codes(), true)) {
            app()->setLocale($saved);

            return $next($request);
        }

        // No explicit choice → the current site's default language (platform console → 'vi').
        $siteDefault = app(SiteLocale::class)->default();
        if (in_array($siteDefault, Locales::codes(), true)) {
            app()->setLocale($siteDefault);
        }

        return $next($request);
    }
}
