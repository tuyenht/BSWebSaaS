<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Tenancy\Context\ResolvedHost;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Domains\DomainName;
use App\Modules\Theme\Chrome\StorefrontCsp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds HTTP security headers to every web response on both planes.
 *
 * Five baseline headers apply unconditionally (storefront + admin + platform):
 *   Strict-Transport-Security (by kind of host, see hsts()), X-Content-Type-Options, X-Frame-Options,
 *   Referrer-Policy, Permissions-Policy.
 *
 * Content-Security-Policy is plane-specific:
 *   Storefront (pure Blade SSR, known origins) — strict allow-list CSP applied (StorefrontCsp).
 *   Admin + platform (Livewire 4 / Alpine.js) — CSP omitted entirely.
 *   Livewire hydration and Alpine reactivity require 'unsafe-eval'; shipping a
 *   strict CSP on those planes would break the admin UI. Omitting CSP there is
 *   acceptable because access is gated behind authentication and the five
 *   baseline headers still protect against the most common framing / sniffing
 *   attacks.
 *
 * Plane detection: a request is NOT on the storefront plane when it targets the
 * dedicated platform console host OR when its URL path starts with the
 * configured admin prefix.
 */
final readonly class AddSecurityHeaders
{
    public function __construct(private SiteResolver $sites) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // ── Five baseline headers (every web response, both planes) ─────────
        $response->headers->set('Strict-Transport-Security', $this->hsts($request));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        // A page that asked to send no referrer at all (the admin's product preview) keeps that
        // stricter value; every other response gets the baseline.
        if ($response->headers->get('Referrer-Policy') !== 'no-referrer') {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // ── Content-Security-Policy: storefront plane only ───────────────────
        if ($this->isStorefrontPlane($request)) {
            $response->headers->set('Content-Security-Policy', StorefrontCsp::header());
        }

        return $response;
    }

    /**
     * The platform's own names commit to HTTPS for a year, subdomains included. Any other host —
     * a customer's domain above all — commits for 30 days and speaks for itself only: its
     * subdomains may be served elsewhere (mail, another website), and the customer can still move
     * the domain away. One strong header on a customer's root domain would bind all of that for a
     * year, so the kind of host is decided here for EVERY response (redirects, Livewire updates,
     * errors), not only where the tenancy middleware ran.
     */
    private function hsts(Request $request): string
    {
        $resolved = $request->attributes->get(ResolvedHost::class);
        $host = rtrim(strtolower($request->getHost()), '.');

        if (! $resolved instanceof ResolvedHost) {
            $resolved = $this->sites->resolve($host);
        }

        return $resolved?->isPlatformSubdomain === true || DomainName::isReserved($host)
            ? 'max-age=31536000; includeSubDomains'
            : 'max-age=2592000';
    }

    /**
     * Returns true when the request belongs to the storefront plane.
     *
     * A request is NOT storefront when:
     *   – it targets the dedicated platform console host, or
     *   – its path falls under the configured admin URL prefix.
     */
    private function isStorefrontPlane(Request $request): bool
    {
        $rawHost = config('tenancy.platform_host');
        if ($request->getHost() === (is_string($rawHost) ? $rawHost : '')) {
            return false;
        }

        $rawPrefix = config('admin.prefix', 'admin');
        $adminPrefix = trim(is_string($rawPrefix) ? $rawPrefix : 'admin', '/');

        return ! $request->is($adminPrefix, $adminPrefix.'/*');
    }
}
