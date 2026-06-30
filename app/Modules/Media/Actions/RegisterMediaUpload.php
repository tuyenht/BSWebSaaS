<?php

namespace App\Modules\Media\Actions;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Models\Site;
use finfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Finalizes a presigned direct-to-R2 upload (DAM P1). The browser PUT the bytes straight to R2 under
 * an opaque temp key (PresignMediaUpload) — bypassing PHP's upload limit / the Livewire-temp endpoint
 * / the reverse-proxy entirely. THIS is the real gate (presign's checks are advisory): it verifies
 * the temp key was issued to THIS site (cross-tenant guard), reads the object once to sniff magic
 * bytes + compute the full SHA-256 + check the real size, dedups, then R2-internal-copies it to the
 * final content-hash key and records the row. A spoofed / oversize / foreign upload is rejected and
 * its temp object deleted — the bytes never become a usable media row.
 */
final class RegisterMediaUpload
{
    public function __construct(private readonly CurrentSite $current) {}

    public function handle(string $tempKey, string $filename, ?int $createdBy = null, ?int $width = null, ?int $height = null, string $collection = 'library'): Media
    {
        $disk = Storage::disk(MediaKey::disk());

        // 1) Cross-tenant guard — the temp key MUST have been issued to the current site (presign
        // recorded it). A client replaying another site's temp key cannot register its object.
        $owner = Cache::pull(PresignMediaUpload::ownershipKey($tempKey));
        // Cache may return the site_id as a string; a missing/forged key normalizes to 0 (never a
        // real site_id) → rejected. site_id is never 0, so this is a safe equality.
        $ownerId = is_numeric($owner) ? (int) $owner : 0;
        if ($ownerId !== $this->current->id()) {
            $disk->delete($tempKey);
            throw new RuntimeException('Phiên tải lên không hợp lệ. Vui lòng thử lại.');
        }
        if (! $disk->exists($tempKey)) {
            throw new RuntimeException('Không tìm thấy tệp đã tải lên.');
        }

        // 2) Read the object ONCE (sniff + hash + size from the same bytes).
        $contents = (string) $disk->get($tempKey);

        // 3) Real size — the presigned PUT does not cap it, so enforce here (defense in depth).
        if (strlen($contents) > UploadMedia::MAX_KB * 1024) {
            $disk->delete($tempKey);
            throw new RuntimeException('Tệp vượt quá dung lượng cho phép (tối đa 10MB).');
        }

        // 4) Real MIME from magic bytes — NEVER trust the client header/extension.
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $ext = UploadMedia::MIME_EXT[$mime] ?? null;
        if ($ext === null) {
            $disk->delete($tempKey);
            throw new RuntimeException('Định dạng tệp không được hỗ trợ.');
        }

        // 5) Full SHA-256 — the dedup column (separate from the short cache-bust suffix in the key).
        $hash = hash('sha256', $contents);
        $siteName = (string) Site::query()->find($this->current->id())?->name;

        // 6) Dedup within (site, collection, hash) — return the master, drop the temp object.
        $existing = Media::query()->where('collection', $collection)->where('hash', $hash)->first();
        if ($existing instanceof Media) {
            $disk->delete($tempKey);

            return $existing;
        }

        // 7) Final opaque content-hash key (unique within the site) + R2-internal copy, drop temp.
        $slug = Str::slug(pathinfo($filename, PATHINFO_FILENAME)) ?: 'tep';
        $finalKey = MediaKey::unique($siteName, $collection, $slug, $hash, $ext);
        $disk->copy($tempKey, $finalKey);
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
