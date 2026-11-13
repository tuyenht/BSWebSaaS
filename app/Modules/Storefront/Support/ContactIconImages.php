<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Theme\Chrome\FloatingContact;
use Illuminate\Support\Str;

/**
 * The pictures the floating contact's channels draw instead of their glyph: each channel's `img` is
 * a media library picture's ulid (never a URL or a raw id), turned here into its public address.
 * One query for every picture of the setting, through the site's media scope (BelongsToSite, an
 * explicit site_id, RLS underneath), and none at all when no channel has one. A picture in the trash,
 * of another site, or no longer a PNG or WebP of at most 50 KB resolves to nothing, so the channel
 * keeps its glyph and the page never shows a broken image.
 *
 * The address is the stored master (no width transform: the file is at most 50 KB).
 */
final readonly class ContactIconImages
{
    /** Width and height printed when the library does not know the picture's size. */
    private const int FALLBACK_SIZE = 48;

    public function __construct(
        private MediaUrlResolver $urls,
        private CurrentSite $current,
    ) {}

    /**
     * The pictures the setting's channels name, by ulid.
     *
     * @param  mixed  $raw  the stored or draft floating-contact setting
     * @return array<string, array{src: string, w: int, h: int}>
     */
    public function for(mixed $raw): array
    {
        $images = [];
        foreach ($this->usable(self::ulids($raw)) as $ulid => $media) {
            $images[$ulid] = [
                'src' => $this->urls->url($media->storage_key),
                'w' => is_int($media->width) && $media->width > 0 ? $media->width : self::FALLBACK_SIZE,
                'h' => is_int($media->height) && $media->height > 0 ? $media->height : self::FALLBACK_SIZE,
            ];
        }

        return $images;
    }

    /**
     * Why each picture cannot be a channel's icon: `missing` (no such picture in this site's library),
     * `type` (not a PNG or WebP by its content) or `size` (larger than 50 KB). One query; a usable
     * picture is left out.
     *
     * @param  list<string>  $ulids
     * @return array<string, 'missing'|'type'|'size'>
     */
    public function problems(array $ulids): array
    {
        $ulids = array_values(array_unique(array_filter($ulids, static fn (string $u): bool => $u !== '')));
        $found = $ulids === [] ? [] : $this->rows($ulids);
        $problems = [];
        foreach ($ulids as $ulid) {
            $media = $found[$ulid] ?? null;
            $problems[$ulid] = match (true) {
                $media === null => 'missing',
                ! in_array($media->mime, FloatingContact::ICON_MIMES, true) => 'type',
                $media->size > FloatingContact::ICON_MAX_BYTES => 'size',
                default => 'ok',
            };
        }

        return array_filter($problems, static fn (string $p): bool => $p !== 'ok');
    }

    /**
     * The ulids the setting's channels name (a well-formed ulid only; anything else is no picture).
     *
     * @return list<string>
     */
    public static function ulids(mixed $raw): array
    {
        $ulids = [];
        foreach (Cast::arr(Cast::arr($raw)['channels'] ?? null) as $channel) {
            $img = Cast::arr($channel)['img'] ?? null;
            if (is_string($img) && Str::isUlid($img)) {
                $ulids[] = $img;
            }
        }

        return array_values(array_unique($ulids));
    }

    /**
     * The usable pictures (PNG or WebP, at most 50 KB) among the ulids, keyed by ulid.
     *
     * @param  list<string>  $ulids
     * @return array<string, Media>
     */
    private function usable(array $ulids): array
    {
        if ($ulids === []) {
            return [];
        }

        return array_filter($this->rows($ulids), static fn (Media $m): bool => in_array($m->mime, FloatingContact::ICON_MIMES, true)
            && $m->size <= FloatingContact::ICON_MAX_BYTES);
    }

    /**
     * This site's library pictures for the ulids (not in the trash), keyed by ulid: one query.
     *
     * @param  list<string>  $ulids
     * @return array<string, Media>
     */
    private function rows(array $ulids): array
    {
        $ulids = array_values(array_filter($ulids, static fn (string $u): bool => Str::isUlid($u)));
        if ($ulids === [] || ! $this->current->hasId()) {
            return [];
        }

        $rows = [];
        foreach (Media::query()->where('site_id', $this->current->id())->whereIn('ulid', $ulids)
            ->get(['ulid', 'storage_key', 'mime', 'size', 'width', 'height']) as $media) {
            $rows[$media->ulid] = $media;
        }

        return $rows;
    }
}
