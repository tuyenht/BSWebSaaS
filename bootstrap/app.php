<?php

use App\Http\Middleware\AddSecurityHeaders;
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
        // Trust the loopback AND the Docker-internal reverse proxy. In prod the edge Caddy is a
        // SEPARATE container that reaches the app over the bridge network on a private IP (NOT
        // loopback), so a loopback-only list ignored its X-Forwarded-Host — making signed-URL
        // validation reconstruct the internal host:port (…:80) and reject every signed URL
        // (notably Livewire's file-upload endpoint) with a 401 invalid-signature. The RFC-1918
        // private ranges trust that in-cluster proxy while still rejecting any spoofed
        // X-Forwarded-* from a public client (whose source IP is never private).
        $middleware->trustProxies(at: ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']);

        // Append security headers to every web response. Placement at the end of the
        // web group means headers are applied after routing but before the response
        // leaves the pipeline, and they survive any earlier post-processing middleware.
        $middleware->web(append: [AddSecurityHeaders::class]);

        // Storefront lead capture (POST /forms/{key}) is a public, session-less endpoint defended
        // by Turnstile + honeypot + rate-limit (§23/R20), not the session CSRF token — which can't
        // ride along on edge/full-page-cacheable storefront HTML. Exempt it so the public form
        // submits cleanly; every authenticated admin/platform POST keeps CSRF protection.
        $middleware->validateCsrfTokens(except: ['forms/*']);

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
            // Platform console (operator host) lives at root paths on its own host, so it
            // matches neither admin branch — send its guests to the platform login instead
            // of the global 401 JSON stub.
            if ($request->getHost() === config('tenancy.platform_host')) {
                return route('platform.login');
            }

            $prefix = (string) config('admin.prefix', 'admin');

            return $request->is($prefix, $prefix.'/*') ? route('admin.login') : route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
