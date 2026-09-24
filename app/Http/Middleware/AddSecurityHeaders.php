<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds HTTP security headers to every web response on both planes.
 *
 * Five baseline headers apply unconditionally (storefront + admin + platform):
 *   Strict-Transport-Security, X-Content-Type-Options, X-Frame-Options,
 *   Referrer-Policy, Permissions-Policy.
 *
 * Content-Security-Policy is plane-specific:
 *   Storefront (pure Blade SSR, known origins) — strict allow-list CSP applied.
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
final class AddSecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // ── Five baseline headers (every web response, both planes) ─────────
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // ── Content-Security-Policy: storefront plane only ───────────────────
        if ($this->isStorefrontPlane($request)) {
            $response->headers->set('Content-Security-Policy', $this->storefrontCsp());
        }

        return $response;
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

    /**
     * The strict Content-Security-Policy applied to storefront responses only.
     *
     * Origin inventory:
     *   default-src 'self'             – same-origin baseline for everything
     *   img-src      media planes      – the configured media hosts (media.cloudflare.base,
     *                                    media.imgproxy.base) — config, never a hardcoded brand
     *                data:             – inline SVG data URIs and favicons
     *   style-src    'unsafe-inline'   – Blade renders a <style>:root{} token block;
     *                                    no external stylesheet dependencies
     *   script-src   'self' + CF       – /build/* Vite assets + the Cloudflare Turnstile
     *                                    api.js (lead-form anti-spam, §23/R20)
     *   frame-src    google.com + CF   – Google Maps embed on /lien-he + the Turnstile
     *                                    challenge iframe (challenges.cloudflare.com)
     *   font-src     'self'            – self-hosted web fonts
     *   base-uri     'self'            – prevent base-tag injection attacks
     *   form-action  'self'            – constrain POST submission targets
     */
    private function storefrontCsp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            trim("img-src 'self' ".implode(' ', $this->mediaOrigins()).' data:'),
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self' https://challenges.cloudflare.com",
            'frame-src https://www.google.com https://challenges.cloudflare.com',
            "font-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }

    /**
     * The origins (scheme://host[:port]) the configured media planes serve images from.
     *
     * @return list<string>
     */
    private function mediaOrigins(): array
    {
        $origins = [];

        foreach ([config('media.cloudflare.base'), config('media.imgproxy.base')] as $base) {
            $parts = is_string($base) ? parse_url($base) : false;

            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $origins[] = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            }
        }

        return array_values(array_unique($origins));
    }
}
