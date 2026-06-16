<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Entitlement\Entitlement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request is rejected unless the resolved site's lifecycle status is 'active'
 * (provisioning/suspended/archived are not served). Runs after SetCurrentSite.
 */
final class EnsureSiteActive
{
    public function __construct(private readonly Entitlement $entitlement) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->entitlement->siteIsActive(), 403, 'This site is not active.');

        return $next($request);
    }
}
