<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Entitlement\Entitlement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense layer #1: a request into a module is rejected unless the current site is
 * entitled to it. Usage: ->middleware('module:cms'). Runs after SetCurrentSite.
 */
final class EnsureModuleEnabled
{
    public function __construct(private readonly Entitlement $entitlement) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($this->entitlement->moduleEnabled($module), 403, "Module [{$module}] is not enabled for this site.");

        return $next($request);
    }
}
