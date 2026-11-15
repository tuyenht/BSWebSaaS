<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every page (HTML, storefront and admin) says `Cache-Control: no-transform`, so no proxy rewrites it.
 * Cloudflare otherwise injects its own analytics script from a dashboard switch, outside the
 * storefront's CSP and our tests; that copy reports to the site's own /cdn-cgi/rum, which answers
 * 404 when the switch is not fully set up — a console error on every page (Lighthouse Best
 * Practices). The storefront loads the analytics beacon itself. The cost: the edge passes on the
 * origin's gzip instead of re-encoding the page.
 *
 * The outermost global middleware, so it is the last to touch the header: Livewire's global
 * back-button middleware replaces Cache-Control on admin pages after the web group has run. It only
 * adds a directive; every other one (public, s-maxage, no-store…) stays as set.
 */
final readonly class KeepPagesUntransformed
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $type = strtolower((string) $response->headers->get('Content-Type', ''));
        if ($type === '' || str_starts_with($type, 'text/html')) {
            $response->headers->addCacheControlDirective('no-transform');
        }

        return $response;
    }
}
