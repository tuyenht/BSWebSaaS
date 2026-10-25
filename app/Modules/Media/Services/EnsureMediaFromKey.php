<?php

namespace App\Modules\Media\Services;

use App\Modules\Media\Exceptions\MediaKeyRejectedException;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteDirectory;

/**
 * Ensures a Media master exists for a storage_key already sitting in R2, returning it (idempotent
 * per site via firstOrCreate on the unique storage_key). Materializes a library row for images that
 * were referenced by a bare key/URL before content moved to media_id — the seeder for An Vinh's
 * fixed brand images, and the one-time migration that rewrites frozen URLs to media_id.
 *
 * Metadata is DERIVED from the key. Two layouts are read:
 * - site-code keys `{code}/[{yyyy}/{mm}/]{slug}-{hashN}.{ext}` — only under the CURRENT site's code
 *   (another website's code is refused), collection `library`;
 * - legacy keys `{name-slug}/{collection}/{slug}-{hash}.{ext}`, read as before so An Vinh's seeded
 *   images keep resolving until they are re-keyed (the database still accepts a legacy key whose
 *   first segment is no website's code).
 * Staging keys (`_incoming/…`) are never a media row. The stored `hash` is synthetic (sha256 of the
 * key) because the original bytes aren't at hand; it only needs to be unique, which the storage_key
 * already is. size is 0 (unknown without fetching the object). An existing master for the key is
 * returned untouched — a real upload is never clobbered.
 */
final readonly class EnsureMediaFromKey
{
    private const array MIME_BY_EXT = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'avif' => 'image/avif',
        'pdf' => 'application/pdf',
    ];

    /** {slug}-{hashN}.{ext} of a site-code key (the slug never holds a dot). */
    private const string SITE_CODE_FILE = '/^(?<slug>[a-z0-9]+(?:-[a-z0-9]+)*)-[0-9a-f]{4,64}\.(?<ext>[a-z0-9]+)$/';

    public function __construct(
        private CurrentSite $current,
        private SiteDirectory $sites,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides  known metadata (e.g. real size/width) to prefer over derived
     */
    public function handle(string $storageKey, array $overrides = []): Media
    {
        return Media::query()->firstOrCreate(
            ['storage_key' => $storageKey],
            array_merge($this->deriveFromKey($storageKey), $overrides),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function deriveFromKey(string $storageKey): array
    {
        $segments = explode('/', $storageKey);
        $first = $segments[0];

        if ($first === '' || str_starts_with($first, '_')) {
            throw MediaKeyRejectedException::malformed('storage key', $storageKey);
        }

        if ($first === $this->sites->codeOfCurrent($this->current)) {
            return $this->deriveFromSiteCodeKey($storageKey, array_slice($segments, 1));
        }

        if (preg_match('/^[0-9a-hjkmnp-tv-z]{8}$/', $first) === 1 && $this->sites->isSiteCode($first)) {
            throw MediaKeyRejectedException::foreignSite($storageKey);
        }

        return $this->deriveFromLegacyKey($storageKey, $segments);
    }

    /**
     * @param  list<string>  $rest  the segments after the site code
     * @return array<string, mixed>
     */
    private function deriveFromSiteCodeKey(string $storageKey, array $rest): array
    {
        $filename = array_pop($rest);
        $folders = $rest === [] || (count($rest) === 2
            && preg_match('/^[0-9]{4}$/', $rest[0]) === 1
            && preg_match('/^(?:0[1-9]|1[0-2])$/', $rest[1]) === 1);

        if (! $folders || $filename === null || preg_match(self::SITE_CODE_FILE, $filename, $parts) !== 1
            || ! in_array($parts['ext'], MediaKey::EXTENSIONS, true)) {
            throw MediaKeyRejectedException::malformed('storage key', $storageKey);
        }

        return $this->attributes($storageKey, 'library', $parts['slug'], $filename, $parts['ext']);
    }

    /**
     * @param  list<string>  $segments
     * @return array<string, mixed>
     */
    private function deriveFromLegacyKey(string $storageKey, array $segments): array
    {
        $filename = (string) array_pop($segments);
        $collection = ($segments[1] ?? '') !== '' ? $segments[1] : 'library';

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $slug = (string) preg_replace('/-[0-9a-f]{6,}$/i', '', pathinfo($filename, PATHINFO_FILENAME));

        return $this->attributes($storageKey, $collection, $slug, $filename, $extension);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(string $storageKey, string $collection, string $slug, string $filename, string $extension): array
    {
        return [
            'collection' => $collection,
            'slug' => $slug !== '' ? $slug : null,
            'hash' => hash('sha256', $storageKey),
            'disk' => 'r2',
            'mime' => self::MIME_BY_EXT[$extension] ?? 'application/octet-stream',
            'size' => 0,
            'original_filename' => $filename,
        ];
    }
}
