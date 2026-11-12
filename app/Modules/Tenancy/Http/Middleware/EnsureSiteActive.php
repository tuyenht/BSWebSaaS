<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Exceptions\SiteNotActive;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request is rejected unless the resolved site's lifecycle status is 'active'
 * (provisioning/suspended/archived are not served: a 403 that carries which of them it is, for the
 * admin's error page). Runs after SetCurrentSite.
 */
final readonly class EnsureSiteActive
{
    public function __construct(private Entitlement $entitlement) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $status = $this->entitlement->siteStatus();
        if ($status !== 'active') {
            throw new SiteNotActive($status ?? 'unknown');
        }

        return $next($request);
    }
}
