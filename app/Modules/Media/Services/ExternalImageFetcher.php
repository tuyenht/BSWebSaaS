<?php

namespace App\Modules\Media\Services;

use App\Modules\Media\Actions\PresignMediaUpload;
use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Exceptions\MediaRejectedException;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Shared\Http\SafeHttp;
use App\Modules\Shared\Http\SafeHttpException;
use App\Modules\Tenancy\Context\CurrentSite;
use finfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Server-side fetch of an EXTERNAL image URL for the picker's "Ảnh từ URL" source. The admin
 * types an arbitrary URL and the APP fetches it, so it goes through the shared SSRF guard
 * (SafeHttp: http(s) only, no private/reserved IPs, every redirect hop re-validated); the body is
 * streamed under the upload cap and the real type sniffed from magic bytes. The verified bytes land on the SAME `_incoming/` staging as presigned uploads (one R2
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
     * GET through the shared SSRF guard (every redirect hop re-validated), then the body streamed
     * under the upload cap.
     *
     * @return array{0: string, 1: string} [bytes, final url]
     */
    private function download(string $url): array
    {
        try {
            ['response' => $response, 'url' => $url] = new SafeHttp($this->resolveHostIps(...))
                ->get($url, ['Accept' => 'image/*'], timeout: 10, connectTimeout: 5);
        } catch (SafeHttpException $e) {
            throw new MediaRejectedException($e->reason);
        }

        if (! $response->successful()) {
            SafeHttp::discard($response);

            throw new MediaRejectedException('fetch_failed', ['status' => $response->status()]);
        }

        // A lying Content-Length can never balloon memory: read at most one byte over the cap.
        [$bytes, $more] = SafeHttp::readUpTo($response, UploadMedia::MAX_KB * 1024);
        if ($more) {
            throw new MediaRejectedException('image_too_large');
        }
        if ($bytes === '') {
            throw new MediaRejectedException('empty_response');
        }

        return [$bytes, $url];
    }

    /**
     * Every A/AAAA answer for the host. Protected so tests can override it to drive the guard
     * (a hostname resolving to a private address) without touching real DNS.
     *
     * @return list<string>
     */
    protected function resolveHostIps(string $host): array
    {
        return SafeHttp::lookup($host);
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
