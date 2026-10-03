<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Context\ResolvedHost;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense layer #1 (design.md §5): resolve hostname -> site, then run the whole
 * request inside Tenancy::enter (transaction + SET LOCAL + assertion + cleanup).
 * Unknown host = fail-closed 404; so is a domain that is attached but not yet verified. The
 * spelling of a name that only redirects answers 301 to the one that runs, before any tenant work.
 */
final readonly class SetCurrentSite
{
    public function __construct(private Tenancy $tenancy, private SiteResolver $sites) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // ServeOnPrimaryDomain, when it ran first, already resolved this request's host.
        $resolved = $request->attributes->get(ResolvedHost::class);

        if (! $resolved instanceof ResolvedHost) {
            $resolved = $this->sites->resolve($request->getHost());
        }

        abort_if($resolved === null, 404);

        // Request-scoped (never worker state): the security headers read the kind of host from it.
        $request->attributes->set(ResolvedHost::class, $resolved);

        // The spelling of a name that only redirects (with or without `www.`) sends every page —
        // admin included — to the spelling that runs, path and query kept (design.md §28).
        if ($resolved->redirectTo !== null && $request->isMethodSafe()) {
            return new RedirectResponse(
                $request->getScheme().'://'.$resolved->redirectTo.$request->getRequestUri(),
                Response::HTTP_MOVED_PERMANENTLY,
                ['Cache-Control' => 'public, max-age='.ServeOnPrimaryDomain::REDIRECT_MAX_AGE],
            );
        }

        return $this->tenancy->enter($resolved->siteId, fn (): Response => $next($request));
    }
}
