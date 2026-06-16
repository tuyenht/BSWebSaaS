<?php

use App\Modules\Rbac\Http\Middleware\EnsurePlatformAccess;
use App\Modules\Tenancy\Http\Middleware\EnsureModuleEnabled;
use App\Modules\Tenancy\Http\Middleware\EnsureSiteActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Defense layer #1 (entitlement). Apply after SetCurrentSite has resolved
        // the tenant: 'site.active' gates lifecycle, 'module:<key>' gates modules.
        $middleware->alias([
            'site.active' => EnsureSiteActive::class,
            'module' => EnsureModuleEnabled::class,
            'platform' => EnsurePlatformAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
