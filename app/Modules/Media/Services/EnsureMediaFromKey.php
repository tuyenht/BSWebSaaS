<?php

namespace App\Modules\Media\Services;

use App\Modules\Media\Exceptions\MediaKeyRejectedException;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Media\Support\MediaKeyPrefix;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteDirectory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

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
        private MediaKeyPrefix $prefix,
    ) {}

    /**
     * A seeder's fixed file, filed for the current website: the object at $sourceKey (a shared seed
     * file in the bucket, such as `an-vinh/hero/hero-home-7ca90eafe212.jpg`) is copied server-side
     * to the website's own folder under the same file name (`{code}/hero-home-7ca90eafe212.jpg`)
     * unless a copy is there already, and the master for that key is ensured. So no seeder writes a
     * key outside the website's code, and re-seeding finds the same row. A new row records the seed
     * key in `meta.seed_source`, so a content pack's `legacy_key` and a stored reference to the seed
     * key still find it. The seed file is shared by every seeded website: unlike `meta.moved_from`
     * (a moved row's own old object), a permanent delete never removes it. A source that is not in
     * storage is logged and the row still made (as for any key whose object is missing).
     *
     * @param  array<string, mixed>  $overrides
     */
    public function fromSeedSource(string $sourceKey, array $overrides = []): Media
    {
        MediaKey::assertCleanPath($sourceKey);
        $key = $this->prefix->forNewObject('library').basename($sourceKey);
        MediaKey::assertCleanPath($key);

        $disk = Storage::disk(MediaKey::disk());
        if (! $disk->exists($key)) {
            if ($disk->exists($sourceKey)) {
                $mime = self::MIME_BY_EXT[strtolower(pathinfo($key, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
                if (! MediaKey::copyAs($disk, $sourceKey, $key, $mime)) {
                    throw new RuntimeException("Copying the seed file '{$sourceKey}' to '{$key}' failed.");
                }
            } else {
                Log::warning('Seed file missing from media storage', ['source' => $sourceKey, 'key' => $key]);
            }
        }

        if ($key !== $sourceKey) {
            $meta = $overrides['meta'] ?? [];
            $overrides['meta'] = [...(is_array($meta) ? $meta : []), 'seed_source' => $sourceKey];
        }

        return $this->handle($key, $overrides);
    }

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
