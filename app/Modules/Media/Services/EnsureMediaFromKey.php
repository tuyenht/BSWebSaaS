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
 * Metadata is DERIVED from the key: `{code}/[{yyyy}/{mm}/]{slug}-{hash}.{ext}` under the website's own
 * code (collection `library`), or the old layout `{folder}/{collection}/{slug}-{hash}.{ext}` of keys
 * written before website codes. A key that is not a clean relative path, or that starts with another
 * website's code or old folder, is refused before anything is written (the database refuses all three
 * too, but its error would abort the caller's transaction). The stored `hash` is synthetic (sha256
 * of the key) because the original file bytes aren't at hand; it only needs to be unique, which the
 * storage_key already is. size is 0 (unknown without fetching the object). An existing master for
 * the key is returned untouched — a real upload is never clobbered.
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

    public function __construct(
        private CurrentSite $current,
        private SiteDirectory $sites,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides  known metadata (e.g. real size/width) to prefer over derived
     */
    public function handle(string $storageKey, array $overrides = []): Media
    {
        MediaKey::assertCleanPath($storageKey);
        $folder = explode('/', $storageKey, 2)[0];
        if ($this->sites->isCodeOfAnotherSite($this->current, $folder)) {
            throw MediaKeyRejectedException::notOwnCode($storageKey);
        }
        if ($this->sites->isOldFolderOfAnotherSite($this->current, $folder)) {
            throw MediaKeyRejectedException::notOwnFolder($storageKey);
        }

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
        $filename = (string) array_pop($segments);
        $own = $segments[0] === $this->sites->codeOfCurrent($this->current);
        // Under the website's code the collection is not in the key; the old layout had it second.
        $collection = ! $own && count($segments) >= 2 ? $segments[1] : 'library';

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        // The hash suffix: at least 4 hex characters under a code, 6 or more in the old layout.
        $suffix = $own ? '/-[0-9a-f]{'.MediaKey::MIN_HASH_LENGTH.',}$/i' : '/-[0-9a-f]{6,}$/i';
        $slug = (string) preg_replace($suffix, '', pathinfo($filename, PATHINFO_FILENAME));

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
