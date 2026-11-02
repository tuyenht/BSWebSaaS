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
     * @return array<int, string> media_id => public URL (only for ids that resolve in this site)
     */
    public function urlsForIds(array $ids, ?int $width = null): array
    {
        return $this->resolve($ids, $width)['urls'];
    }

    /**
     * The URLs of urlsForIds() plus, from the same single SELECT, a `srcset` per URL at
     * $srcsetWidths (capped at each master's stored width). A URL whose srcset would hold fewer
     * than two distinct candidates has none (MediaUrlResolver::srcset).
     *
     * @param  array<array-key, mixed>  $ids
     * @param  list<int>  $srcsetWidths
     * @return array{urls: array<int, string>, srcsets: array<string, string>}
     */
    public function resolve(array $ids, ?int $width = null, array $srcsetWidths = []): array
    {
        $wanted = [];
        foreach ($ids as $id) {
            if (is_int($id) && $id > 0) {
                $wanted[$id] = true;
            }
        }

        if ($wanted === []) {
            return ['urls' => [], 'srcsets' => []];
        }

        $urls = [];
        $srcsets = [];
        foreach (Media::query()->whereIn('id', array_keys($wanted))->get(['id', 'storage_key', 'width']) as $media) {
            $key = (string) $media->storage_key;
            $url = $this->resolver->url($key, $width);
            $urls[(int) $media->id] = $url;
            $srcset = $srcsetWidths !== [] ? $this->resolver->srcset($key, $srcsetWidths, $media->width) : null;
            if ($srcset !== null) {
                $srcsets[$url] = $srcset;
            }
        }

        return ['urls' => $urls, 'srcsets' => $srcsets];
    }
}
