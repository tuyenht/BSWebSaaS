<?php

namespace App\Modules\Media\Services;

use App\Modules\Media\Models\Media;

/**
 * Batch-resolves a set of media ids to their public URLs in ONE query (site-scoped by
 * BelongsToSite + RLS). Content stores media_id — never a URL (ADR-17: the storage_key is the
 * single source of truth) — so any surface that renders content (block sections, product body)
 * turns ids into URLs through here. Missing / other-tenant / trashed ids are simply omitted: the
 * caller treats an absent id as "no image" and skips it (fail-safe). One SELECT regardless of how
 * many ids a page references — the anti-N+1 contract for content rendering at scale.
 */
final readonly class MediaLibraryUrls
{
    public function __construct(private MediaUrlResolver $resolver) {}

    /**
     * @param  array<array-key, mixed>  $ids  raw media_id values pulled from content props (untyped)
     * @return array<int, string>  media_id => public URL (only for ids that resolve in this site)
     */
    public function urlsForIds(array $ids, ?int $width = null): array
    {
        $wanted = [];
        foreach ($ids as $id) {
            if (is_int($id) && $id > 0) {
                $wanted[$id] = true;
            }
        }

        if ($wanted === []) {
            return [];
        }

        $urls = [];
        foreach (Media::query()->whereIn('id', array_keys($wanted))->get(['id', 'storage_key']) as $media) {
            $urls[(int) $media->id] = $this->resolver->url((string) $media->storage_key, $width);
        }

        return $urls;
    }
}
