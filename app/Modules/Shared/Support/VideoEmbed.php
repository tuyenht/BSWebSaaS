<?php

namespace App\Modules\Shared\Support;

/**
 * Turns a pasted youtube/vimeo URL (any common shape: watch?v=, youtu.be, shorts, embed,
 * vimeo.com/id, player.vimeo) into the canonical responsive embed the rich editor inserts —
 * T5G-style "Video URL + autoplay" but standardized: a `.video-embed` wrapper styled by CSS
 * (aspect-ratio 16/9, no inline layout styles), youtube-nocookie, lazy iframe, and autoplay
 * always paired with mute (browsers block audible autoplay). Returns null for anything that
 * is not a recognizable youtube/vimeo URL — the caller shows a friendly error, and the
 * sanitizer's SafeIframe regexp is the server-side net behind it all.
 */
final class VideoEmbed
{
    public static function html(string $url, bool $autoplay = false): ?string
    {
        $src = self::embedSrc(trim($url), $autoplay);
        if ($src === null) {
            return null;
        }

        return '<div class="video-embed"><iframe src="'.e($src).'" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>';
    }

    private static function embedSrc(string $url, bool $autoplay): ?string
    {
        if (! preg_match('~^https?://~i', $url)) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $youtubeId = null;
        if (in_array($host, ['www.youtube.com', 'youtube.com', 'm.youtube.com', 'www.youtube-nocookie.com'], true)) {
            if (isset($query['v']) && is_string($query['v'])) {
                $youtubeId = $query['v'];
            } elseif (preg_match('~^/(?:shorts|embed|live)/([\w-]{5,20})~', $path, $m)) {
                $youtubeId = $m[1];
            }
        } elseif ($host === 'youtu.be' && preg_match('~^/([\w-]{5,20})~', $path, $m)) {
            $youtubeId = $m[1];
        }

        if ($youtubeId !== null && preg_match('~^[\w-]{5,20}$~', $youtubeId)) {
            $params = 'rel=0'.($autoplay ? '&autoplay=1&mute=1' : '');

            return "https://www.youtube-nocookie.com/embed/{$youtubeId}?{$params}";
        }

        $vimeoId = null;
        if (in_array($host, ['vimeo.com', 'www.vimeo.com'], true) && preg_match('~^/(\d{6,12})(?:$|/)~', $path, $m)) {
            $vimeoId = $m[1];
        } elseif ($host === 'player.vimeo.com' && preg_match('~^/video/(\d{6,12})~', $path, $m)) {
            $vimeoId = $m[1];
        }

        if ($vimeoId !== null) {
            $params = $autoplay ? '?autoplay=1&muted=1' : '';

            return "https://player.vimeo.com/video/{$vimeoId}{$params}";
        }

        return null;
    }
}
