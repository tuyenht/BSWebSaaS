<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\ResolvedHost;
use App\Modules\Tenancy\Context\SiteResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A site's public pages live on ONE address, its primary domain (design.md §28). Reading them on
 * any other verified host of the site — the platform subdomain, the `www` twin — answers 301 to
 * the same path on the primary, so visitors and search engines see one website, and the canonical
 * link, sitemap and robots.txt (all built from the request's host) name the primary without
 * knowing about domains.
 *
 * Only the storefront's GET/HEAD routes carry this. The admin stays on the host the member signed
 * in on (cookies are per host), and a form post is never redirected.
 *
 * The redirect may be remembered for an hour, not forever: the primary can change.
 *
 * While the platform switch `tenancy.noindex_platform_subdomains` is on, a site still served on
 * its platform subdomain asks search engines not to index it. A customer's own domain never does.
 *
 * Runs before SetCurrentSite and hands it the resolved host, so the host is looked up once.
 */
final readonly class ServeOnPrimaryDomain
{
    public const int REDIRECT_MAX_AGE = 3600;

    public function __construct(private SiteResolver $sites) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = SiteResolver::normalize($request->getHost());
        $resolved = $host === null ? null : $this->sites->resolve($host);

        if ($resolved === null) {
            return $next($request);   // SetCurrentSite answers 404
        }

        if ($resolved->primaryHost !== $host && $request->isMethodSafe()) {
            return new RedirectResponse(
                $request->getScheme().'://'.$resolved->primaryHost.$request->getRequestUri(),
                Response::HTTP_MOVED_PERMANENTLY,
                ['Cache-Control' => 'public, max-age='.self::REDIRECT_MAX_AGE],
            );
        }

        $request->attributes->set(ResolvedHost::class, $resolved);

        $response = $next($request);

        if ($resolved->isPlatformSubdomain && Cast::bool(config('tenancy.noindex_platform_subdomains'))) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }
}
