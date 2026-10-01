<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense layer #1 (design.md §5): resolve hostname -> site, then run the whole
 * request inside Tenancy::enter (transaction + SET LOCAL + assertion + cleanup).
 * Unknown host = fail-closed 404; so is a domain that is attached but not yet verified.
 */
final readonly class SetCurrentSite
{
    public function __construct(private Tenancy $tenancy, private SiteResolver $sites) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $resolved = $this->sites->resolve($request->getHost());

        abort_if($resolved === null, 404);

        return $this->tenancy->enter($resolved->siteId, fn (): Response => $next($request));
    }
}
