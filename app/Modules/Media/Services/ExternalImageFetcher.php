<?php

namespace App\Modules\Media\Services;

use App\Modules\Media\Actions\PresignMediaUpload;
use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Tenancy\Context\CurrentSite;
use finfo;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Server-side fetch of an EXTERNAL image URL for the picker's "Ảnh từ URL" source. This is the
 * SSRF choke point: the admin types an arbitrary URL and the APP fetches it, so every hop is
 * validated — http(s) only, no private/reserved IPs (literal or DNS-resolved), redirects followed
 * manually and re-validated, body streamed under the upload cap, real type sniffed from magic
 * bytes. The verified bytes land on the SAME `_incoming/` staging as presigned uploads (one R2
 * lifecycle rule covers abandons) with the same ownership cache, so RegisterMediaUpload can
 * finalize an import through the existing hardened pipeline.
 */
final readonly class ExternalImageFetcher
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
            throw new RuntimeException('Liên kết không trỏ tới một ảnh hợp lệ (JPG, PNG, WEBP, GIF).');
        }

        $dimensions = @getimagesizefromstring($bytes);

        // Same staging shape + ownership guard as the presigned flow (PresignMediaUpload):
        // `_incoming/` stays the left-anchored prefix so one lifecycle rule expires abandons.
        $tempKey = '_incoming/'.Str::slug($siteName).'/url-'.Str::ulid().'.'.$ext;
        if (! Storage::disk(MediaKey::disk())->put($tempKey, $bytes)) {
            throw new RuntimeException('Không lưu được tệp lên kho lưu trữ. Vui lòng thử lại.');
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
            $this->assertSafeUrl($url);

            $response = Http::withOptions(['allow_redirects' => false, 'stream' => true])
                ->timeout(10)->connectTimeout(5)
                ->withHeaders(['Accept' => 'image/*'])
                ->get($url);

            if ($response->redirect()) {
                $location = (string) $response->header('Location');
                if ($location === '') {
                    throw new RuntimeException('Liên kết chuyển hướng không hợp lệ.');
                }
                $url = $this->absolutize($location, $url);

                continue;
            }

            if (! $response->successful()) {
                throw new RuntimeException('Không tải được ảnh từ liên kết (HTTP '.$response->status().').');
            }

            return [$this->readCapped($response), $url];
        }

        throw new RuntimeException('Liên kết chuyển hướng quá nhiều lần.');
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
                throw new RuntimeException('Ảnh vượt quá dung lượng cho phép (tối đa 10MB).');
            }
        }
        if ($bytes === '') {
            throw new RuntimeException('Liên kết trả về nội dung rỗng.');
        }

        return $bytes;
    }

    /** http(s) only; the host — IP literal or every DNS answer — must be publicly routable. */
    private function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('Liên kết chưa hợp lệ — cần là URL ảnh công khai (http/https).');
        }

        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            $this->assertPublicIp($literal);

            return;
        }

        $ips = $this->resolve($host);
        if ($ips === []) {
            throw new RuntimeException('Không phân giải được tên miền của liên kết.');
        }
        foreach ($ips as $ip) {
            $this->assertPublicIp($ip);
        }
    }

    /** @return list<string> */
    private function resolve(string $host): array
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
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('Liên kết trỏ tới địa chỉ nội bộ — không được phép.');
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
