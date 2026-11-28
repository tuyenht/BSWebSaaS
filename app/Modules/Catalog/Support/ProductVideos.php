<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Media\Models\Media;
use App\Modules\Shared\Support\Cast;

/**
 * A product's YouTube videos, kept in `products.meta.videos` as a list of `{yt, title}` (the 11-character
 * video id and an optional title, in the order they were added). Only the id is stored, never the link
 * it came from; a link of any other host, a playlist without a video and a script URL give no id.
 */
final class ProductVideos
{
    /** The most videos one product keeps. */
    public const int MAX = 20;

    /**
     * The video id of a YouTube link (watch?v= · youtu.be/ · shorts/ · embed/, with or without
     * https, www. or m.), or null when it is not exactly one video of YouTube.
     */
    public static function id(string $url): ?string
    {
        $url = trim($url);
        $found = preg_match('~^(?:https?://)?(?:(?:www\.|m\.)?youtube\.com/(?:watch\?(?:[^#]*&)?v=|shorts/|embed/)|youtu\.be/)([A-Za-z0-9_-]{11})(?:[?&#/].*)?$~D', $url, $m);

        return $found === 1 ? $m[1] : null;
    }

    /** A title as it is stored: one line, trimmed, at most the media title length. */
    public static function title(string $title): string
    {
        return mb_substr(trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $title)), 0, Media::TITLE_MAX_LENGTH);
    }

    /**
     * Whatever was stored or sent, as a clean list: an entry whose `yt` is not an id, or whose id came
     * before, is dropped; titles are tidied; the list stops at MAX.
     *
     * @return list<array{yt: string, title: string}>
     */
    public static function clean(mixed $videos): array
    {
        if (! is_array($videos)) {
            return [];
        }
        $clean = [];
        $seen = [];
        foreach ($videos as $video) {
            if (! is_array($video) || ! is_string($video['yt'] ?? null) || preg_match('~^[A-Za-z0-9_-]{11}$~D', $video['yt']) !== 1 || isset($seen[$video['yt']])) {
                continue;
            }
            $seen[$video['yt']] = true;
            $clean[] = ['yt' => $video['yt'], 'title' => self::title(Cast::string($video['title'] ?? ''))];
            if (count($clean) === self::MAX) {
                break;
            }
        }

        return $clean;
    }
}
