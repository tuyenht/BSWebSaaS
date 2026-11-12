<?php

declare(strict_types=1);

namespace App\Modules\Theme\Chrome;

/**
 * The strict Content-Security-Policy of a storefront page. The security headers middleware sends it
 * on every storefront response; the admin's product preview sends it on the storefront page it
 * draws inside the admin (the admin plane itself carries no CSP).
 *
 * Origin inventory:
 *   default-src 'self'             – same-origin baseline for everything
 *   img-src      media planes      – the configured media hosts (media.cloudflare.base,
 *                                    media.imgproxy.base) — config, never a hardcoded brand
 *                data:             – inline SVG data URIs and favicons
 *   media-src    media planes      – the same media hosts as img-src: the hero's background
 *                                    video plays its file from the library (no data:, no wildcard)
 *   style-src    'unsafe-inline'   – Blade renders a <style>:root{} token block;
 *                                    no external stylesheet dependencies
 *   script-src   'self' + CF       – /build/* Vite assets + the Cloudflare Turnstile
 *                                    api.js (lead-form anti-spam, §23/R20)
 *                sha256 hash       – the one inline script: the text-size boot
 *                                    (StorefrontChrome::TEXT_SIZE_BOOT), no 'unsafe-inline'
 *                CF insights       – static.cloudflareinsights.com/beacon.min.js, the
 *                                    load-speed beacon the edge adds (cookieless)
 *   connect-src  'self' + CF       – where that beacon reports (cloudflareinsights.com)
 *   frame-src    google.com + CF   – Google Maps embed on /lien-he + the Turnstile
 *                                    challenge iframe (challenges.cloudflare.com)
 *                video players     – www.youtube-nocookie.com + player.vimeo.com, the
 *                                    frames the click-to-load video façade builds only
 *                                    when the visitor presses play (nothing loads before)
 *   font-src     'self'            – self-hosted web fonts
 *   base-uri     'self'            – prevent base-tag injection attacks
 *   form-action  'self'            – constrain POST submission targets
 */
final class StorefrontCsp
{
    public static function header(): string
    {
        $media = implode(' ', self::mediaOrigins());

        return implode('; ', [
            "default-src 'self'",
            trim("img-src 'self' ".$media.' data:'),
            trim("media-src 'self' ".$media),
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self' ".StorefrontChrome::textSizeBootHash().' https://challenges.cloudflare.com https://static.cloudflareinsights.com',
            "connect-src 'self' https://cloudflareinsights.com",
            'frame-src https://www.google.com https://challenges.cloudflare.com https://www.youtube-nocookie.com https://player.vimeo.com',
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
    private static function mediaOrigins(): array
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
