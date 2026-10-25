<?php

namespace App\Modules\Media\Actions;

use App\Modules\Media\Exceptions\MediaRejectedException;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Media\Support\MediaKeyPrefix;
use App\Modules\Tenancy\Context\CurrentSite;
use finfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Finalizes a presigned direct-to-R2 upload (DAM P1). The browser PUT the bytes straight to R2 under
 * an opaque temp key (PresignMediaUpload) — bypassing PHP's upload limit / the Livewire-temp endpoint
 * / the reverse-proxy entirely. THIS is the real gate (presign's checks are advisory): it verifies
 * the temp key was issued to THIS site (cross-tenant guard), reads the object once to sniff magic
 * bytes + compute the full SHA-256 + check the real size, dedups, then R2-internal-copies it to the
 * final content-hash key and records the row. A spoofed / oversize / foreign upload is rejected and
 * its temp object deleted — the bytes never become a usable media row.
 */
final readonly class RegisterMediaUpload
{
    public function __construct(
        private CurrentSite $current,
        private MediaKeyPrefix $prefix,
    ) {}

    public function handle(string $tempKey, string $filename, ?int $createdBy = null, ?int $width = null, ?int $height = null, string $collection = 'library'): Media
    {
        MediaKey::assertConfigured(); // fail loud + clean if the R2 disk has no bucket/creds (.env drift)

        $disk = Storage::disk(MediaKey::disk());

        // 1) Cross-tenant guard — the temp key MUST have been issued to the current site (presign
        // recorded it). A client replaying another site's temp key cannot register its object.
        $ownershipKey = PresignMediaUpload::ownershipKey($tempKey);
        $owner = Cache::get($ownershipKey);
        // Cache may return the site_id as a string; a missing/forged key normalizes to 0 (never a
        // real site_id) → rejected. site_id is never 0, so this is a safe equality.
        $ownerId = is_numeric($owner) ? (int) $owner : 0;
        if ($ownerId !== $this->current->id()) {
            // The key came from the client: it may name another site's file, so storage is never
            // touched here (abandoned staging objects expire through the `_incoming/` lifecycle),
            // and the real owner's claim stays in the cache so a replay cannot spoil its upload.
            throw new MediaRejectedException('upload_session_invalid');
        }
        Cache::forget($ownershipKey);
        if (! $disk->exists($tempKey)) {
            throw new MediaRejectedException('upload_missing');
        }

        // 2) Size FIRST via HEAD — the presigned PUT does NOT cap size, so reject a huge object
        // BEFORE loading it into memory (otherwise get() would OOM the worker on a malicious upload).
        if ($disk->size($tempKey) > UploadMedia::MAX_KB * 1024) {
            $disk->delete($tempKey);
            throw new MediaRejectedException('too_large');
        }

        // 3) Now safe to read the object (≤ cap) — sniff + hash come from the same bytes.
        $contents = (string) $disk->get($tempKey);

        // 4) Real MIME from magic bytes — NEVER trust the client header/extension.
        $mime = (string) new finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        $ext = UploadMedia::MIME_EXT[$mime] ?? null;
        if ($ext === null) {
            $disk->delete($tempKey);
            throw new MediaRejectedException('unsupported_type');
        }

        // 5) Full SHA-256 — the dedup column (separate from the short cache-bust suffix in the key).
        $hash = hash('sha256', $contents);

        // 6) Dedup within (site, collection, hash) — return the master, drop the temp object.
        // withTrashed: the UNIQUE(site,collection,hash) index doesn't know about soft-deletes,
        // so a trashed master would turn the insert below into a guaranteed violation —
        // re-uploading a binned file RESTORES its master instead of dead-ending. And a master
        // whose object went missing (an interrupted past finalize) self-heals: these are the
        // same bytes, copied back under the row's existing storage_key — otherwise dedup would
        // pin that hash to a dead key forever and re-uploading could never fix the broken image.
        $existing = Media::query()->withTrashed()->where('collection', $collection)->where('hash', $hash)->first();
        if ($existing instanceof Media) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            if (! $disk->exists($existing->storage_key) && ! $disk->copy($tempKey, $existing->storage_key)) {
                throw new MediaRejectedException('storage_failed');
            }
            $disk->delete($tempKey);

            return $existing;
        }

        // 7) Final site-code content-hash key (stem unique within the site) + R2-internal copy, drop temp.
        $slug = MediaKey::slugFromFilename($filename);
        $finalKey = MediaKey::unique($this->prefix->forNewObject(), $slug, $hash, $ext);
        // copy() returns bool on this disk ('throw' => false) — unchecked failure would persist
        // a row whose object never existed: an unrenderable orphan the picker shows as broken.
        if (! $disk->copy($tempKey, $finalKey)) {
            throw new MediaRejectedException('storage_failed');
        }
        $disk->delete($tempKey);

        // 8) Persist — BelongsToSite stamps site_id; createOrFirst is race-safe on (site,collection,hash).
        return Media::query()->createOrFirst(
            ['collection' => $collection, 'hash' => $hash],
            [
                'slug' => $slug,
                'storage_key' => $finalKey,
                'disk' => MediaKey::disk(),
                'original_filename' => $filename,
                'mime' => $mime,
                'size' => strlen($contents),
                'width' => $width,
                'height' => $height,
                'created_by' => $createdBy,
            ],
        );
    }
}
