<?php

namespace App\Modules\Media\Services;

use App\Modules\Media\Actions\PresignMediaUpload;
use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Exceptions\MediaRejectedException;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Tenancy\Context\CurrentSite;
use finfo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Server-side fetch of an EXTERNAL image URL for the picker's "Ảnh từ URL" source. This is the
 * SSRF choke point: the admin types an arbitrary URL and the APP fetches it, so every hop is
 * validated — http(s) only, no private/reserved IPs (literal or DNS-resolved), redirects followed
 * manually and re-validated, body streamed under the upload cap, real type sniffed from magic
 * bytes. The verified bytes land on the SAME `_incoming/` staging as presigned uploads (one R2
 * lifecycle rule covers abandons) with the same ownership cache, so RegisterMediaUpload can
 * finalize an import through the existing hardened pipeline.
 *
 * NOT final on purpose: the tests subclass it to override resolveHostIps() and exercise the
 * DNS-rebind guard (a hostname resolving to a private address) without real DNS.
 */
class ExternalImageFetcher
{
    /** What an <img> can render — pdf etc. stay out of the URL source. */
    private const array IMAGE_MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
    ];

    private const int MAX_REDIRECTS = 3;

    public function __construct(private CurrentSite $current) {}

    /**
     * Fetch + validate the URL and stage its bytes for a later RegisterMediaUpload.
     *
     * @return array{tempKey: string, url: string, filename: string, mime: string, size: int, width: int|null, height: int|null}
     */
    public function handle(string $url, string $siteName): array
    {
        MediaKey::assertConfigured();

        [$bytes, $finalUrl] = $this->download($url);

        $mime = (string) new finfo(FILEINFO_MIME_TYPE)->buffer($bytes);
        $ext = self::IMAGE_MIME_EXT[$mime] ?? null;
        if ($ext === null) {
            throw new MediaRejectedException('not_an_image');
        }

        $dimensions = @getimagesizefromstring($bytes);

        // Same staging shape + ownership guard as the presigned flow (PresignMediaUpload):
        // `_incoming/` stays the left-anchored prefix so one lifecycle rule expires abandons.
        $tempKey = '_incoming/'.Str::slug($siteName).'/url-'.Str::ulid().'.'.$ext;
        if (! Storage::disk(MediaKey::disk())->put($tempKey, $bytes)) {
            throw new MediaRejectedException('storage_failed');
        }
        // Longer than presign's 5m: the admin reads the preview before deciding to insert.
        Cache::put(PresignMediaUpload::ownershipKey($tempKey), $this->current->id(), now()->addMinutes(30));

        return [
            'tempKey' => $tempKey,
            'url' => $finalUrl,
            'filename' => $this->filenameFrom($finalUrl, $ext),
            'mime' => $mime,
            'size' => strlen($bytes),
            'width' => $dimensions === false ? null : $dimensions[0],
            'height' => $dimensions === false ? null : $dimensions[1],
        ];
    }

    /**
     * GET with manual redirect handling — every hop re-passes the SSRF gate.
     *
     * @return array{0: string, 1: string} [bytes, final url]
     */
    private function download(string $url): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            // Resolve + validate ONCE, then PIN the request to that exact IP (CURLOPT_RESOLVE).
            // Without pinning, cURL re-resolves the host and a DNS-rebind (TTL 0: public on the
            // check, private on the fetch) would walk straight past the guard to 169.254.169.254.
            $conn = $this->safeConnection($url);

            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'stream' => true,
                    'curl' => [CURLOPT_RESOLVE => ["{$conn['host']}:{$conn['port']}:{$conn['ip']}"]],
                ])
                    ->timeout(10)->connectTimeout(5)
                    ->withHeaders(['Accept' => 'image/*'])
                    ->get($url);
            } catch (ConnectionException) {
                // A timeout or a refused connection is the link's problem, not a server error.
                throw new MediaRejectedException('fetch_unreachable');
            }

            if ($response->redirect()) {
                $location = (string) $response->header('Location');
                if ($location === '') {
                    throw new MediaRejectedException('redirect_invalid');
                }
                $url = $this->absolutize($location, $url);

                continue;
            }

            if (! $response->successful()) {
                throw new MediaRejectedException('fetch_failed', ['status' => $response->status()]);
            }

            return [$this->readCapped($response), $url];
        }

        throw new MediaRejectedException('too_many_redirects');
    }

    /** Stream the body up to the upload cap — a lying Content-Length can never balloon memory. */
    private function readCapped(Response $response): string
    {
        $cap = UploadMedia::MAX_KB * 1024;
        $body = $response->toPsrResponse()->getBody();

        $bytes = '';
        while (! $body->eof()) {
            $bytes .= $body->read(65536);
            if (strlen($bytes) > $cap) {
                throw new MediaRejectedException('image_too_large');
            }
        }
        if ($bytes === '') {
            throw new MediaRejectedException('empty_response');
        }

        return $bytes;
    }

    /**
     * Validate http(s) + host, resolve to a single publicly-routable IP, and return the exact
     * (host, port, ip) the request must be pinned to. EVERY resolved address is checked — a
     * hostname that resolves to even one private IP is rejected — and the pinned IP is one of
     * those verified answers. A host is either a plain IP literal or a name: number-like
     * spellings of an address are refused outright.
     *
     * @return array{host: string, port: int, ip: string}
     */
    private function safeConnection(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $port = is_int($parts['port'] ?? null) ? $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new MediaRejectedException('url_invalid');
        }

        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            $this->assertPublicIp($literal);

            return ['host' => $host, 'port' => $port, 'ip' => $literal];
        }

        // cURL reads a host whose last label is a number (0x7f.0.0.1, 2130706433, 127.1,
        // 0177.0.0.1) as an IPv4 address and connects to it, ignoring the pin below. No real
        // hostname ends that way, so it is refused here rather than left to DNS: a resolver that
        // answered for such a name (a wildcard search domain) would be vouching for loopback.
        if (preg_match('/(^|\.)(\d+|0x[0-9a-f]*)\.?$/i', $host) === 1) {
            throw new MediaRejectedException('url_invalid');
        }

        $ips = $this->resolveHostIps($host);
        if ($ips === []) {
            throw new MediaRejectedException('host_unresolvable');
        }
        foreach ($ips as $ip) {
            $this->assertPublicIp($ip);
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /**
     * Every A/AAAA answer for the host. Protected so tests can override it to drive the guard
     * (a hostname resolving to a private address) without touching real DNS.
     *
     * @return list<string>
     */
    protected function resolveHostIps(string $host): array
    {
        $ips = [];
        foreach (dns_get_record($host, DNS_A + DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }

    private function assertPublicIp(string $ip): void
    {
        // An IPv6 address can carry an IPv4 one inside it — IPv4-mapped/compatible
        // (::ffff:169.254.169.254, ::a.b.c.d) or NAT64 (64:ff9b::a.b.c.d) — and so smuggle a
        // private IPv4 past an IPv6-only check: validate the embedded IPv4 too.
        if (str_contains($ip, ':')) {
            $packed = @inet_pton($ip);
            if ($packed !== false && strlen($packed) === 16
                && (str_starts_with($packed, str_repeat("\x00", 10)."\xff\xff")
                    || str_starts_with($packed, str_repeat("\x00", 12))
                    || str_starts_with($packed, "\x00\x64\xff\x9b".str_repeat("\x00", 8)))) {
                $embedded = inet_ntop(substr($packed, 12));
                if (is_string($embedded)) {
                    $this->rejectIfNotPublic($embedded);
                }
            }
        }

        $this->rejectIfNotPublic($ip);
    }

    private function rejectIfNotPublic(string $ip): void
    {
        // GLOBAL_RANGE adds the blocks that PHP counts as neither private nor reserved yet are
        // never public: carrier-grade NAT (100.64.0.0/10 — some clouds serve instance metadata
        // there), protocol, benchmark and documentation ranges, 6to4 and Teredo.
        $publicOnly = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE;
        if (filter_var($ip, FILTER_VALIDATE_IP, $publicOnly) === false) {
            throw new MediaRejectedException('private_address');
        }
    }

    private function absolutize(string $location, string $base): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $dir = rtrim(dirname((string) ($parts['path'] ?? '/')), '/');

        return $origin.$dir.'/'.$location;
    }

    /** A readable filename off the URL path (register re-slugs it anyway); decoded, bounded. */
    private function filenameFrom(string $url, string $ext): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = rawurldecode(basename($path));
        if ($base === '' || $base === '/' || ! str_contains($base, '.')) {
            return 'anh-tu-url.'.$ext;
        }

        return Str::limit($base, 120, '');
    }
}
