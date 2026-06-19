<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the session-selected admin locale (chosen via the language switcher) for the
 * current request. Falls back to the app default when nothing is selected or the chosen
 * locale no longer has a translation file on disk.
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale');

        if (is_string($locale) && in_array($locale, Locales::codes(), true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
