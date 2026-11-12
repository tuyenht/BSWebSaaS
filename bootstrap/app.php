<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Modules\Admin\Errors\AdminErrorPages;
use App\Modules\Admin\Errors\ErrorReference;
use App\Modules\Identity\Http\Middleware\EnsureTwoFactorEnabled;
use App\Modules\Monitoring\ErrorReporter;
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
        // Trust the loopback AND the Docker-internal reverse proxy (prod's edge Caddy is a separate
        // container on a private bridge IP), but ONLY for the visitor's IP (X-Forwarded-For) and the
        // scheme (X-Forwarded-Proto). Caddy forwards the visitor's own Host header unchanged, so the
        // host comes from Host alone: Caddy keeps an X-Forwarded-Host that arrives through
        // Cloudflare (a trusted proxy for Caddy) as the client wrote it, and trusting it here let any
        // visitor pick which site a request resolves to. Port and prefix are never trusted either.
        // Signed URLs still validate: the scheme comes from X-Forwarded-Proto and the host and
        // standard port from Host.
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );

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

        // Production error notices (app/Modules/Monitoring): count the fault and tell the operator.
        // Runs after Laravel's own ignore list (404, validation…); never returns false, so the
        // normal log entry is still written; never lets its own failure out.
        $exceptions->report(function (Throwable $e): void {
            // A short reference in the log context before the fault's log line is written: the
            // admin's error page shows the same one, so support finds the fault from it.
            ErrorReference::current();

            try {
                app(ErrorReporter::class)->report($e);
            } catch (Throwable) {
                // the reporter guards itself; this only catches a failure to build it
            }
        });

        // The admin's own error pages (no permission, not found, session ended, too fast, our
        // fault, maintenance, a site not open): /admin…, the platform console and Livewire updates
        // only — everything else (the storefront, JSON callers) keeps Laravel's handling (null).
        $exceptions->render(fn (Throwable $e, Request $request) => app(AdminErrorPages::class)->render($e, $request));
    })->create();
