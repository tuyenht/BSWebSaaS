<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Domain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense layer #1 (design.md §5): resolve hostname -> site, then run the whole
 * request inside Tenancy::enter (transaction + SET LOCAL + assertion + cleanup).
 * Unknown host = fail-closed 404.
 */
final class SetCurrentSite
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $domain = Domain::query()->where('hostname', $request->getHost())->first();

        abort_if($domain === null, 404);

        return $this->tenancy->enter($domain->site_id, fn (): Response => $next($request));
    }
}
