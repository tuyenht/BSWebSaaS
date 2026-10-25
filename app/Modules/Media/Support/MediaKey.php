<?php

namespace App\Modules\Media\Support;

use App\Modules\Media\Exceptions\MediaKeyRejectedException;
use App\Modules\Media\Models\Media;
use App\Modules\Shared\Support\Slugger;
use RuntimeException;

/**
 * Builds the storage key of a new media object (ADR-17): {code}/{slug}-{hashN}.{ext}, or
 * {code}/{yyyy}/{mm}/{slug}-{hashN}.{ext} when the site has year/month folders (MediaKeyPrefix).
 * {code} is the site's permanent code (sites.code), unique across websites, so two sites can never
 * share a key; the database refuses a key under another site's code. The collection is a column,
 * not part of the key. Keys written before site codes keep their legacy layout until re-keyed.
 *
 * Media is public, so anti-enumeration is intentionally NOT a goal — the hash suffix is for
 * cache-busting (different content → different key → the CDN serves fresh) and uniqueness. It starts
 * at config('media.url_hash_length') (never below 4) and grows only when the stem {slug}-{hashN} is
 * already taken in the site under ANY extension, so `x-ab12.jpg` and `x-ab12.png` never coexist.
 * This is SEPARATE from the full 64-hex media.hash column used for dedup.
 */
final class MediaKey
{
    /** Extensions a site-code key may carry; the stem check looks for every one of them. */
    public const array EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'pdf'];

    /** The shortest hash suffix, whatever the configuration says. */
    public const int MIN_HASH_LENGTH = 4;

    /** {code}/ or {code}/{yyyy}/{mm}/ — what MediaKeyPrefix hands out. */
    public const string PREFIX_PATTERN = '#^[0-9a-hjkmnp-tv-z]{8}/(?:[0-9]{4}/(?:0[1-9]|1[0-2])/)?$#';

    /** A link-path slug: lowercase ASCII words joined by single dashes — never a dot or a slash. */
    public const string SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** The configured media storage disk (filesystems.php), as a string (config() returns mixed). */
    public static function disk(): string
    {
        $disk = config('media.disk');

        return is_string($disk) ? $disk : 'r2';
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

    /** The slug part of a new key, from the uploaded file's name ('tep' when nothing usable is left). */
    public static function slugFromFilename(string $filename): string
    {
        $slug = Slugger::make(pathinfo($filename, PATHINFO_FILENAME));

        return preg_match(self::SLUG_PATTERN, $slug) === 1 ? $slug : 'tep';
    }

    /**
     * A key under $prefix whose stem ({prefix}{slug}-{hashN}) no row of the current site uses with
     * any extension. RLS and the BelongsToSite scope keep the lookup inside the site; withTrashed,
     * because a row in the trash still owns its key (the UNIQUE index covers it).
     */
    public static function unique(string $prefix, string $slug, string $hash, string $ext): string
    {
        if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw MediaKeyRejectedException::malformed('key prefix', $prefix);
        }
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw MediaKeyRejectedException::malformed('slug', $slug);
        }
        if (! in_array($ext, self::EXTENSIONS, true)) {
            throw MediaKeyRejectedException::malformed('extension', $ext);
        }
        if (preg_match('/^[0-9a-f]{'.self::MIN_HASH_LENGTH.',64}$/', $hash) !== 1) {
            throw MediaKeyRejectedException::malformed('content hash', $hash);
        }

        $configured = config('media.url_hash_length', self::MIN_HASH_LENGTH);
        $start = is_int($configured) ? max(self::MIN_HASH_LENGTH, $configured) : self::MIN_HASH_LENGTH;

        for ($len = min($start, strlen($hash)); $len <= strlen($hash); $len++) {
            $stem = $prefix.$slug.'-'.substr($hash, 0, $len);

            if (! self::stemTaken($stem)) {
                return "{$stem}.{$ext}";
            }
        }

        // Unreachable in practice: the full 64-hex content hash is already unique within the site.
        throw new RuntimeException("No free media key for stem {$prefix}{$slug}.");
    }

    /**
     * Exact keys over the closed extension list, not `LIKE '{stem}.%'`: the lookup stays on the
     * UNIQUE (site_id, storage_key) index instead of scanning every key of the site.
     */
    private static function stemTaken(string $stem): bool
    {
        $keys = array_map(fn (string $ext): string => "{$stem}.{$ext}", self::EXTENSIONS);

        return Media::query()->withTrashed()->whereIn('storage_key', $keys)->exists();
    }
}
