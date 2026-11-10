<?php

namespace App\Modules\Media\Services;

/**
 * Derives the public URL for a stored media key from config('media.driver') — the single switch
 * point (ADR-17 L0). The storage key (content-hash {tenant}/{collection}/{slug}-{hash}.{ext}) is
 * the source of truth; URLs are NEVER stored on a row, so flipping the driver (one env) re-points
 * every image without a migration.
 */
final class MediaUrlResolver
{
    public function url(string $key, ?int $width = null): string
    {
        return match (config('media.driver')) {
            'imgproxy' => $this->imgproxy($key, $width),
            'static' => $this->base('cloudflare').'/'.ltrim($key, '/'),   // raw master, no transform
            default => $this->cloudflare($key, $width),
        };
    }

    /**
     * The address of the stored file itself, never a transform — for a file that is not a picture (a
     * video). Every driver serves masters from the same plane the `static` driver uses: imgproxy only
     * resizes pictures, and the Worker passes a request without ?w through as the raw master.
     */
    public function fileUrl(string $key): string
    {
        return $this->base('cloudflare').'/'.ltrim($key, '/');
    }

    /**
     * A `srcset` value for the key at the given widths: `{url} {w}w` per width the Worker allows,
     * never wider than the master (the smallest width is always kept, so a small master still gets
     * one candidate), with duplicate URLs dropped. Null when fewer than two distinct URLs remain —
     * the `static` driver ignores the width, so it never prints a srcset. Pure.
     *
     * @param  list<int>  $widths
     */
    public function srcset(string $key, array $widths, ?int $masterWidth = null): ?string
    {
        sort($widths);
        $candidates = [];
        foreach ($widths as $index => $width) {
            $snapped = $this->snapWidth($width);
            if ($index > 0 && $masterWidth !== null && $masterWidth > 0 && $snapped > $masterWidth) {
                break;
            }
            $url = $this->url($key, $snapped);
            $candidates[$url] ??= $url.' '.$snapped.'w';
        }

        return count($candidates) >= 2 ? implode(', ', $candidates) : null;
    }

    /**
     * The scheme and host media URLs are served from, for a `preconnect` hint; null when the base is
     * not an absolute URL.
     */
    public function origin(): ?string
    {
        $base = $this->base(config('media.driver') === 'imgproxy' ? 'imgproxy' : 'cloudflare');
        $scheme = parse_url($base, PHP_URL_SCHEME);
        $host = parse_url($base, PHP_URL_HOST);
        if (! is_string($scheme) || ! is_string($host) || $host === '') {
            return null;
        }
        $port = parse_url($base, PHP_URL_PORT);

        return $scheme.'://'.$host.(is_int($port) ? ':'.$port : '');
    }

    /**
     * Inverse of url() for the cloudflare/static form: recover the storage_key baked into a public
     * URL, or null when the URL is not one this resolver would mint — a foreign host, or the
     * HMAC-signed imgproxy form, which is not reversible. Driver-independent (always inverts the
     * cloudflare base, the form legacy rows stored). Used ONLY to migrate content that froze a
     * resolved URL back to a media_id; new content stores media_id and never a URL.
     */
    public function keyFromUrl(string $url): ?string
    {
        $prefix = $this->base('cloudflare').'/';
        if ($prefix === '/' || ! str_starts_with($url, $prefix)) {
            return null;
        }

        $key = substr($url, strlen($prefix));
        foreach (['?', '#'] as $separator) {
            $position = strpos($key, $separator);
            if ($position !== false) {
                $key = substr($key, 0, $position);
            }
        }

        return $key !== '' ? $key : null;
    }

    /** Cloudflare Worker: it reads ?w and runs cf.image; no width → the raw master (canonical / L2). */
    private function cloudflare(string $key, ?int $width): string
    {
        $url = $this->base('cloudflare').'/'.ltrim($key, '/');

        return $width !== null ? $url.'?w='.$this->snapWidth($width) : $url;
    }

    /**
     * Snap a requested width up to the nearest whitelisted one (capped at the max). The Worker
     * rejects any non-whitelisted ?w with a 400 (anti resize-DoS) BEFORE the raw-master fallback,
     * so a caller must never mint a URL it would refuse — that ships a hard-broken image
     * (e.g. a pre-resolved ?w=800 on the about block → 400 on the home + about pages).
     */
    private function snapWidth(int $width): int
    {
        $configured = config('media.widths');
        $allowed = is_array($configured) ? array_values(array_filter($configured, is_int(...))) : [];
        if ($allowed === []) {
            return $width;
        }
        sort($allowed);
        foreach ($allowed as $w) {
            if ($width <= $w) {
                return $w;
            }
        }

        return end($allowed);
    }

    /** imgproxy: an HMAC-signed processing URL (verify format vs the imgproxy version at PR-5). */
    private function imgproxy(string $key, ?int $width): string
    {
        $resize = 'rs:fit:'.($width ?? 2048).':0';
        $source = rtrim($this->stringConfig('media.imgproxy.source_bucket'), '/').'/'.ltrim($key, '/');
        $path = "/{$resize}/plain/{$source}@webp";

        $signature = hash_hmac(
            'sha256',
            pack('H*', $this->stringConfig('media.imgproxy.salt')).$path,
            pack('H*', $this->stringConfig('media.imgproxy.key')),
            true,
        );
        $signature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return $this->base('imgproxy')."/{$signature}{$path}";
    }

    private function base(string $driver): string
    {
        return rtrim($this->stringConfig("media.{$driver}.base"), '/');
    }

    /** config() returns mixed; a media URL part must be a string (empty when unset/misconfigured). */
    private function stringConfig(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }
}
