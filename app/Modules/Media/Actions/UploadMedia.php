<?php

namespace App\Modules\Media\Actions;

use App\Modules\Media\Exceptions\MediaRejectedException;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Media\Support\MediaKeyPrefix;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Securely ingest an uploaded file into the media library (design.md §2.5 / §16 / ADR-17). The
 * real MIME is sniffed from the file's MAGIC BYTES (finfo) — never the client header or extension,
 * so a `.jpg` carrying PHP is rejected. Content is SHA-256 hashed → deduped within (site, collection,
 * hash) → stored to R2 under the site-code content-hash key (the source of truth; URL derived later
 * by MediaUrlResolver). site_id is stamped by BelongsToSite from the current tenant context.
 *
 * GĐ1 allow-list excludes SVG (XSS surface; needs a dedicated sanitizer dependency — deferred).
 */
final class UploadMedia
{
    /** Max upload size in KB (10 MB). */
    public const int MAX_KB = 10240;

    /** Real (magic-byte) MIME → stored extension. The render-safe GĐ1 allow-list. */
    public const array MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
    ];

    public function __construct(private readonly MediaKeyPrefix $prefix) {}

    /**
     * @param  bool  $mayRestore  whether the uploader may take a file back out of the Trash (the
     *                            media.delete right, resolved by the caller). The action holds no user
     *                            of its own, so the caller decides; false refuses with `in_trash`.
     */
    public function handle(UploadedFile $file, string $collection = 'library', ?int $createdBy = null, bool $mayRestore = false): Media
    {
        MediaKey::assertConfigured(); // fail loud + clean if the R2 disk has no bucket/creds (.env drift)

        // 1) Size guard (defense-in-depth — the upload FormRequest bounds it too).
        if ($file->getSize() > self::MAX_KB * 1024) {
            throw new MediaRejectedException('too_large');
        }

        // 2) MIME from magic bytes (finfo reads content) — NEVER trust the client mime/extension.
        $mime = (string) $file->getMimeType();
        $ext = self::MIME_EXT[$mime] ?? null;
        if ($ext === null) {
            throw new MediaRejectedException('unsupported_type');
        }

        // 3) Content hash (full SHA-256; the key embeds a short prefix of it — ADR-17).
        $contents = (string) file_get_contents((string) $file->getRealPath());
        $hash = hash('sha256', $contents);

        // 4) Dedup within (site, collection, hash) — RLS-scoped; return the master, skip re-upload.
        // withTrashed: the UNIQUE index doesn't know about soft-deletes — re-uploading a binned
        // file restores its master instead of dead-ending on the violation, but only for someone
        // allowed to restore from the Trash; anyone else is told so, and the row stays binned.
        // Self-heal: if the master's object went missing, these same bytes go back under its key.
        $existing = Media::query()->withTrashed()->where('collection', $collection)->where('hash', $hash)->first();
        if ($existing instanceof Media) {
            if ($existing->trashed()) {
                if (! $mayRestore) {
                    throw new MediaRejectedException('in_trash');
                }
                $existing->restore();
            }
            $disk = Storage::disk(MediaKey::disk());
            if (! $disk->exists($existing->storage_key) && ! $disk->put($existing->storage_key, $contents)) {
                throw new MediaRejectedException('storage_failed');
            }

            return $existing;
        }

        // 5) Site-code content-hash storage key (ADR-17) — its stem is unique within the site (MediaKey
        // extends the hash suffix only on a collision). Anti-enumerate waived (public media).
        $slug = MediaKey::slugFromFilename($file->getClientOriginalName());
        $key = MediaKey::unique($this->prefix->forNewObject(), $slug, $hash, $ext);

        // 6) Store the single master (private; the public URL is derived, never stored).
        // Laravel's filesystem does NOT throw by default — put() returns false on failure. Guard it,
        // or a failed write would still create a media row pointing at a missing object (broken image).
        if (! Storage::disk(MediaKey::disk())->put($key, $contents)) {
            throw new MediaRejectedException('storage_failed');
        }

        [$width, $height] = $this->dimensions((string) $file->getRealPath(), $mime);

        // 7) Persist — BelongsToSite stamps site_id. createOrFirst (not create): the step-4 dedup
        // check is a fast path a concurrent identical upload can race past, so the insert runs in a
        // SAVEPOINT — on the UNIQUE(site_id,collection,hash) violation it rolls back to the savepoint
        // (the request transaction stays valid) and returns the winner. A double-submit / concurrent
        // upload is thus idempotent, never a 500 / aborted transaction. (site_id is in the unique key
        // but stamped by the trait, so the dedup attributes are collection + hash within this site.)
        return Media::query()->createOrFirst(
            ['collection' => $collection, 'hash' => $hash],
            [
                'slug' => $slug,
                'storage_key' => $key,
                'disk' => MediaKey::disk(),
                'original_filename' => $file->getClientOriginalName(),
                'mime' => $mime,
                'size' => (int) $file->getSize(),
                'width' => $width,
                'height' => $height,
                'created_by' => $createdBy,
            ],
        );
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

        return $info === false ? [null, null] : [$info[0], $info[1]];
    }
}
