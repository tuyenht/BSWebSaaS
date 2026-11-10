<?php

namespace App\Modules\Media\Support;

use App\Modules\Media\Models\Media;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds the opaque content-hash storage key (ADR-17): {tenant}/{collection}/{slug}-{hashN}.{ext}.
 * Media is public, so anti-enumeration is intentionally NOT a goal — the hash suffix is purely for
 * cache-busting (different content → different key → CDN serves fresh) and key uniqueness. It starts
 * short (config('media.url_hash_length'), default 4) and extends only on the rare same-site collision,
 * so URLs stay clean without ever overwriting another object. NOTE this is SEPARATE from the full
 * 64-hex media.hash column used for dedup — shortening this suffix never affects dedup.
 */
final class MediaKey
{
    /** The configured media storage disk (filesystems.php), as a string (config() returns mixed). */
    public static function disk(): string
    {
        $disk = config('media.disk');

        return is_string($disk) ? $disk : 'r2';
    }

    /**
     * Object headers for a stored master, written by the server from the sniffed MIME. A presigned
     * upload lets the client choose the temporary object's Content-Type, so a copy must REPLACE the
     * metadata, never carry it over; a PDF is always a download, never rendered inline.
     *
     * @return array<string, string>
     */
    public static function objectOptions(string $mime): array
    {
        $options = ['mimetype' => $mime, 'ContentType' => $mime];
        if ($mime === 'application/pdf') {
            $options['ContentDisposition'] = 'attachment';
        }

        return $options;
    }

    /**
     * Fail LOUD + EARLY when the configured media disk is an S3-family (r2/s3) disk with no bucket —
     * i.e. its credentials are missing from .env. Without this, Storage::disk() throws a cryptic
     * "bucket must be string, null given" TypeError deep in the adapter (which is exactly how a
     * missing R2_* block silently broke upload). Called at the upload entry points; the upload
     * endpoints log this RuntimeException for the operator and show the person uploading a plain
     * "try again" — a site admin cannot fix the platform's storage. (Deploy-time gate:
     * `php artisan env:check`.)
     */
    public static function assertConfigured(): void
    {
        $disk = self::disk();
        $cfg = config("filesystems.disks.{$disk}");

        if (is_array($cfg) && ($cfg['driver'] ?? null) === 's3' && empty($cfg['bucket'])) {
            throw new RuntimeException(
                "Media storage '{$disk}' is not configured (the R2 bucket/keys are missing from .env). ".
                'Run `php artisan env:check`.'
            );
        }
    }

    public static function unique(string $siteName, string $collection, string $slug, string $hash, string $ext): string
    {
        $configured = config('media.url_hash_length', 4);
        // 0 = no suffix at all (clean {slug}.{ext}); collisions still extend up from there.
        $start = is_int($configured) ? max(0, $configured) : 4;

        for ($len = $start; $len <= 64; $len++) {
            $key = self::compose($siteName, $collection, $slug, substr($hash, 0, $len), $ext);

            // RLS scopes the lookup to the current site (storage_key is unique within a site).
            if (! Media::query()->where('storage_key', $key)->exists()) {
                return $key;
            }
        }

        // Unreachable in practice (the full 64-hex hash is already unique within the site).
        return self::compose($siteName, $collection, $slug, $hash, $ext);
    }

    /**
     * Compose the key. An empty hash part (length 0) → a clean {slug}.{ext} (no dash). The tenant
     * prefix is dropped in standalone (non-SaaS) mode → {collection}/{slug}.{ext}.
     */
    private static function compose(string $siteName, string $collection, string $slug, string $hashPart, string $ext): string
    {
        $suffix = $hashPart === '' ? '' : "-{$hashPart}";
        $prefix = config('media.tenant_prefix', true) ? Str::slug($siteName).'/' : '';

        return "{$prefix}{$collection}/{$slug}{$suffix}.{$ext}";
    }
}
