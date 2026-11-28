<?php

namespace App\Modules\Media\Support;

use App\Modules\Media\Exceptions\MediaKeyRejectedException;
use App\Modules\Media\Models\Media;
use App\Modules\Shared\Support\Slugger;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use LogicException;
use RuntimeException;

/**
 * Builds the storage key of a new media object (ADR-17): {code}/{slug}-{hashN}.{ext}. {code} is the
 * website's permanent code (sites.code: six random characters, unique over every website, never
 * changed or issued again), so two websites never share a key, whatever their names; the database
 * refuses a key under another website's code. The collection is a column, not part of the key. In
 * single-site mode (`media.tenant_prefix` false) the prefix is the collection, as before. Keys
 * written before website codes keep their old layout.
 *
 * First segments that are never a website's (RESERVED_FIRST_SEGMENTS is the one list; a website code is
 * six characters with no `_`): `sys` holds public files of the platform itself; any segment that
 * starts with `_` is private (the file server refuses it), such as upload staging and lead files.
 * Folders of old keys frozen when codes arrived live in the database (`reserved_site_codes`).
 *
 * Media is public, so anti-enumeration is intentionally NOT a goal — the hash suffix is for
 * cache-busting (different content → different key → the CDN serves fresh) and uniqueness. It starts
 * at config('media.url_hash_length') (never below 4) and grows only when the stem {slug}-{hashN} is
 * already taken in the website under ANY extension, so `x-ab12.jpg` and `x-ab12.png` never coexist.
 * This is SEPARATE from the full 64-hex media.hash column used for dedup.
 */
final class MediaKey
{
    /** Public files of the platform itself, which belong to no website (`sys/{slug}-{hash}.{ext}`). */
    public const string SYSTEM_SEGMENT = 'sys';

    /** A first segment that starts with this is private: the file server never serves it. */
    public const string PRIVATE_MARK = '_';

    /** Presigned and fetched uploads waiting to be registered (expired by one R2 lifecycle rule). */
    public const string STAGING_SEGMENT = '_incoming';

    /** Files of website leads, on the `leads` disk when it shares the media bucket. */
    public const string PRIVATE_SEGMENT = '_private';

    /**
     * The collections of the single-site layout (`media.tenant_prefix` false), where the collection is
     * the first folder (`library/logo-ab12.png`). The database accepts such a key only under one of
     * these names (reserved_site_codes, reason `collection`), so no other first folder can pass for one.
     */
    public const array COLLECTIONS = ['library', 'cover', 'og', 'product'];

    /** The first segments that are never a website's: the one list (with every other `_…` segment). */
    public const array RESERVED_FIRST_SEGMENTS = [self::SYSTEM_SEGMENT, self::STAGING_SEGMENT, self::PRIVATE_SEGMENT];

    /** A website code (sites.code; the database checks the same pattern). */
    public const string CODE_PATTERN = '/^[0-9a-hjkmnp-tv-z]{6}$/D';

    /** Extensions a new key may carry; the stem check looks for every one of them. */
    public const array EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'pdf'];

    /** The shortest hash suffix, whatever the configuration says. */
    public const int MIN_HASH_LENGTH = 4;

    /** What MediaKeyPrefix hands out: one or more clean segments, each followed by a slash. */
    public const string PREFIX_PATTERN = '#^(?:[A-Za-z0-9][A-Za-z0-9._-]*/)+$#D';

    /**
     * A clean relative path: ASCII segments that start with a letter or digit, joined by single
     * slashes — so no `.`/`..`/empty/`_` segment, no leading or trailing slash, no backslash, space
     * or control character. Storage (Flysystem) rewrites any other path (`\` → `/`, `.` and empty
     * segments dropped, `..` resolved), so only a clean key can be judged by its first segment. The
     * trigger on media applies the same pattern (`D`: `$` must not match before a final newline).
     */
    public const string CLEAN_PATH_PATTERN = '#^[A-Za-z0-9][A-Za-z0-9._-]*(?:/[A-Za-z0-9][A-Za-z0-9._-]*)+$#D';

    /** A link-path slug: lowercase ASCII words joined by single dashes — never a dot or a slash. */
    public const string SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

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
     * Server-side copy that REPLACES the object's metadata with headers derived from the stored MIME
     * (objectOptions), so nothing set on the source object reaches the copy. Returns false on
     * failure, like the disk's own copy() on this ('throw' => false) disk.
     */
    public static function copyAs(Filesystem $disk, string $from, string $to, string $mime): bool
    {
        if (! $disk instanceof FilesystemAdapter) {
            throw new LogicException('Media storage must be a Flysystem-backed disk to replace object headers on copy.');
        }

        try {
            $disk->getDriver()->copy($from, $to, self::objectOptions($mime) + ['MetadataDirective' => 'REPLACE']);
        } catch (FilesystemException) {
            return false; // the disk is configured not to report; the caller decides what a failure means
        }

        return true;
    }

    /**
     * Deletes the object at $key if the transaction now open rolls back (a savepoint included), and
     * only then: for an object just stored for a row this transaction created, so a row that never
     * commits leaves no object behind and one that commits keeps it. Outside a transaction nothing is
     * registered (the row is already written). The delete runs after the rollback, so it makes no
     * query; a re-upload of the same bytes puts the object back (UploadMedia's self-heal).
     */
    public static function dropOnRollBack(string $key): void
    {
        if (DB::transactionLevel() === 0) {
            return;
        }
        $disk = self::disk();
        DB::afterRollBack(static function () use ($disk, $key): void {
            Storage::disk($disk)->delete($key);
        });
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

    /** Whether a first path segment belongs to the platform rather than to a website. */
    public static function isReservedFirstSegment(string $segment): bool
    {
        return in_array($segment, self::RESERVED_FIRST_SEGMENTS, true) || str_starts_with($segment, self::PRIVATE_MARK);
    }

    /**
     * Whether $key lies under a demo seed source (`media.seed_folders`): a shared seed file every
     * seeded website copies from, which no website may delete.
     */
    public static function isSeedKey(string $key): bool
    {
        $folders = config('media.seed_folders', []);

        return is_array($folders) && in_array(explode('/', $key, 2)[0], $folders, true);
    }

    /** Refuses a storage key that is not a clean relative path (CLEAN_PATH_PATTERN). */
    public static function assertCleanPath(string $key): void
    {
        if (preg_match(self::CLEAN_PATH_PATTERN, $key) !== 1) {
            throw MediaKeyRejectedException::malformed('storage key', $key);
        }
    }

    /** The slug part of a new key, from the uploaded file's name ('tep' when nothing usable is left). */
    public static function slugFromFilename(string $filename): string
    {
        $slug = Slugger::make(pathinfo($filename, PATHINFO_FILENAME));

        return preg_match(self::SLUG_PATTERN, $slug) === 1 ? $slug : 'tep';
    }

    /**
     * A key under $prefix (MediaKeyPrefix) whose stem ({prefix}{slug}-{hashN}) no row of the current
     * website uses with any extension. RLS and the BelongsToSite scope keep the lookup inside the
     * website; withTrashed, because a row in the trash still owns its key.
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
        if (preg_match('/^[0-9a-f]{'.self::MIN_HASH_LENGTH.',64}$/D', $hash) !== 1) {
            throw MediaKeyRejectedException::malformed('content hash', $hash);
        }

        $configured = config('media.url_hash_length', self::MIN_HASH_LENGTH);
        $start = is_int($configured) ? max(self::MIN_HASH_LENGTH, $configured) : self::MIN_HASH_LENGTH;

        for ($len = min($start, strlen($hash)); $len <= strlen($hash); $len++) {
            $stem = $prefix.$slug.'-'.substr($hash, 0, $len);

            if (! self::stemTaken($stem)) {
                $key = "{$stem}.{$ext}";
                self::assertCleanPath($key);

                return $key;
            }
        }

        // Unreachable in practice: the full 64-hex content hash is already unique within the website.
        throw new RuntimeException("No free media key for stem {$prefix}{$slug}.");
    }

    /**
     * Exact keys over the closed extension list, not `LIKE '{stem}.%'`: the lookup stays on the
     * unique (site_id, storage_key) index instead of scanning every key of the website.
     */
    private static function stemTaken(string $stem): bool
    {
        $keys = array_map(fn (string $ext): string => "{$stem}.{$ext}", self::EXTENSIONS);

        return Media::query()->withTrashed()->whereIn('storage_key', $keys)->exists();
    }
}
