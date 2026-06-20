<?php

use App\Modules\Identity\Http\Middleware\EnsureTwoFactorEnabled;
use App\Modules\Rbac\Http\Middleware\EnsurePlatformAccess;
use App\Modules\Rbac\Http\Middleware\EnsureTenantMember;
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
        // Behind the Caddy TLS terminator (staging): trust forwarded headers so the app
        // detects HTTPS and generates correct absolute URLs / redirects.
        $middleware->trustProxies(at: '*');

        // Defense layer #1 (entitlement). Apply after SetCurrentSite has resolved
        // the tenant: 'site.active' gates lifecycle, 'module:<key>' gates modules.
        $middleware->alias([
            'site.active' => EnsureSiteActive::class,
            'module' => EnsureModuleEnabled::class,
            'platform' => EnsurePlatformAccess::class,
            'tenant.member' => EnsureTenantMember::class,
            '2fa' => EnsureTwoFactorEnabled::class,
        ]);

        // Guests on the tenant admin plane land on the admin login; elsewhere the
        // platform/global login (P0.8 stub until its UI).
        $middleware->redirectGuestsTo(function (Request $request): string {
            $prefix = (string) config('admin.prefix', 'admin');

            return $request->is($prefix, $prefix.'/*') ? route('admin.login') : route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
