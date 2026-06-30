<?php

namespace App\Modules\Media\Actions;

use App\Modules\Media\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Securely ingest an uploaded file into the media library (design.md §2.5 / §16 / ADR-17). The
 * real MIME is sniffed from the file's MAGIC BYTES (finfo) — never the client header or extension,
 * so a `.jpg` carrying PHP is rejected. Content is SHA-256 hashed → deduped within (site, collection,
 * hash) → stored to R2 under the opaque content-hash key (the source of truth; URL derived later by
 * MediaUrlResolver). site_id is stamped by BelongsToSite from the current tenant context.
 *
 * GĐ1 allow-list excludes SVG (XSS surface; needs a dedicated sanitizer dependency — deferred).
 */
final class UploadMedia
{
    /** Max upload size in KB (10 MB). */
    public const MAX_KB = 10240;

    /** Real (magic-byte) MIME → stored extension. The render-safe GĐ1 allow-list. */
    private const MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
    ];

    public function handle(UploadedFile $file, string $siteName, string $collection = 'library', ?int $createdBy = null): Media
    {
        // 1) Size guard (defense-in-depth — the upload FormRequest bounds it too).
        if ($file->getSize() > self::MAX_KB * 1024) {
            throw new RuntimeException('Tệp vượt quá dung lượng cho phép (tối đa 10MB).');
        }

        // 2) MIME from magic bytes (finfo reads content) — NEVER trust the client mime/extension.
        $mime = (string) $file->getMimeType();
        $ext = self::MIME_EXT[$mime] ?? null;
        if ($ext === null) {
            throw new RuntimeException('Định dạng tệp không được hỗ trợ.');
        }

        // 3) Content hash (full SHA-256; the key embeds the first 12 chars — ADR-17).
        $contents = (string) file_get_contents((string) $file->getRealPath());
        $hash = hash('sha256', $contents);

        // 4) Dedup within (site, collection, hash) — RLS-scoped; return the master, skip re-upload.
        $existing = Media::query()->where('collection', $collection)->where('hash', $hash)->first();
        if ($existing instanceof Media) {
            return $existing;
        }

        // 5) Opaque content-hash storage key (ADR-17): {tenant}/{collection}/{slug}-{hash12}.{ext}
        $slug = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'tep';
        $key = Str::slug($siteName)."/{$collection}/{$slug}-".substr($hash, 0, 12).".{$ext}";

        // 6) Store the single master to R2 (private; the public URL is derived, never stored).
        Storage::disk('r2')->put($key, $contents);

        [$width, $height] = $this->dimensions((string) $file->getRealPath(), $mime);

        // 7) Persist — BelongsToSite stamps site_id from the current context.
        return Media::query()->create([
            'collection' => $collection,
            'slug' => $slug,
            'hash' => $hash,
            'storage_key' => $key,
            'disk' => 'r2',
            'original_filename' => $file->getClientOriginalName(),
            'mime' => $mime,
            'size' => (int) $file->getSize(),
            'width' => $width,
            'height' => $height,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Raster dimensions (to reserve space against CLS); null for non-raster (pdf) or unreadable.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensions(string $path, string $mime): array
    {
        if (! str_starts_with($mime, 'image/')) {
            return [null, null];
        }

        $info = @getimagesize($path);

        return $info === false ? [null, null] : [(int) $info[0], (int) $info[1]];
    }
}
