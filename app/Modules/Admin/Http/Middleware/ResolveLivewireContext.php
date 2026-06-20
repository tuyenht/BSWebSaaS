<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Domain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plane-aware tenant context for the SHARED Livewire update endpoint (/livewire/update),
 * which both planes post to:
 *  - platform host → no tenant context (the operator console is tenant-less);
 *  - tenant host   → run inside Tenancy::enter (RLS), identical to SetCurrentSite.
 * Unknown tenant host = fail-closed 404. Keeping SetCurrentSite itself strict means the
 * tenant /admin/* routes still 404 on the platform host (no cross-plane bleed).
 */
final class ResolveLivewireContext
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getHost() === config('tenancy.platform_host')) {
            return $next($request);
        }

        $domain = Domain::query()->where('hostname', $request->getHost())->first();

        abort_if($domain === null, 404);

        return $this->tenancy->enter($domain->site_id, fn (): Response => $next($request));
    }
}
