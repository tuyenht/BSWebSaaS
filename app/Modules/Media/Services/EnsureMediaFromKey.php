<?php

namespace App\Modules\Media\Services;

use App\Modules\Media\Models\Media;

/**
 * Ensures a Media master exists for a storage_key already sitting in R2, returning it (idempotent
 * per site via firstOrCreate on the unique storage_key). Materializes a library row for images that
 * were referenced by a bare key/URL before content moved to media_id — the seeder for An Vinh's
 * fixed brand images, and the one-time migration that rewrites frozen URLs to media_id.
 *
 * Metadata is DERIVED from the key ({tenant}/{collection}/{slug}-{hash}.{ext}). The stored `hash` is
 * synthetic (sha256 of the key) because the original file bytes aren't at hand; it only needs to be
 * unique, which the storage_key already is. size is 0 (unknown without fetching the object). An
 * existing master for the key is returned untouched — a real upload is never clobbered.
 */
final class EnsureMediaFromKey
{
    private const array MIME_BY_EXT = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

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
        $filename = (string) array_pop($segments);
        $collection = ($segments[1] ?? '') !== '' ? $segments[1] : 'library';

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $slug = (string) preg_replace('/-[0-9a-f]{6,}$/i', '', pathinfo($filename, PATHINFO_FILENAME));

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
