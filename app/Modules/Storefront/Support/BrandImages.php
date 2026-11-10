<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;

/**
 * The addresses of a site's four brand images — logo, logo on a dark background, favicon, share
 * image — from the media ids SiteIdentity returns. One query for the four, through the site's
 * media scope (BelongsToSite, an explicit site_id, RLS underneath), and none at all when no id is
 * set; a picture in the trash or of another site resolves to nothing.
 *
 * Until an image is first saved on the settings screen, the URL the older general.brand setting
 * holds for it (SiteIdentity's `legacy_images`) is what the page shows, so no live page changes by
 * a byte before the site is edited; that save removes the URL, and the media id is the one source
 * from then on (an image the user removes stays removed). The logo on a dark background falls back
 * to the normal logo.
 *
 * Widths follow what the older setting stored: the logo and its dark twin are the master file, the
 * favicon 64px, the share image 1280px (snapped to the widths the media host allows).
 */
final readonly class BrandImages
{
    /** Slot → the width its address asks for (null = the master file). */
    private const array WIDTHS = ['logo' => null, 'logo_dark' => null, 'favicon' => 64, 'og_image' => 1280];

    public function __construct(
        private MediaUrlResolver $urls,
        private CurrentSite $current,
    ) {}

    /**
     * @param  array<string, mixed>|null  $identity  SiteIdentity::for()
     * @return array{logo: string|null, logo_dark: string|null, favicon: string|null, og_image: string|null}
     */
    public function for(?array $identity): array
    {
        $legacy = Cast::arr($identity['legacy_images'] ?? null);
        $ids = array_filter(Cast::arr($identity['media_ids'] ?? null), is_int(...));

        $keys = [];
        if ($ids !== [] && $this->current->hasId()) {
            $keys = Media::query()
                ->where('site_id', $this->current->id())
                ->whereIn('id', array_values(array_unique($ids)))
                ->pluck('storage_key', 'id')
                ->all();
        }

        $images = [];
        foreach (self::WIDTHS as $slot => $width) {
            $old = $legacy[$slot] ?? null;
            $key = isset($ids[$slot]) ? ($keys[$ids[$slot]] ?? null) : null;
            $images[$slot] = match (true) {
                is_string($old) && $old !== '' => $old,
                is_string($key) && $key !== '' => $this->urls->url($key, $width),
                default => null,
            };
        }
        $images['logo_dark'] ??= $images['logo'];

        return $images;
    }

    /**
     * The identity with the four addresses under the keys the theme reads (`logo`, `logo_dark`,
     * `favicon`, `og_image`), the ids and older URLs taken out; null stays null.
     *
     * @param  array<string, mixed>|null  $identity
     * @return array<string, mixed>|null
     */
    public function apply(?array $identity): ?array
    {
        if ($identity === null) {
            return null;
        }

        $images = array_filter($this->for($identity), fn (?string $url): bool => $url !== null);
        unset($identity['media_ids'], $identity['legacy_images']);

        return [...$identity, ...$images];
    }
}
