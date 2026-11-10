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

    /**
     * The click-to-load parts of a recognised video (the storefront façade): the player address the
     * script loads on the visitor's press — youtube-nocookie or the vimeo player, with autoplay so
     * that one press plays (the press is the user gesture, so sound is allowed) — and the video's
     * own page, the link a visitor without the script follows. Null for anything not recognised.
     *
     * @return array{provider: 'youtube'|'vimeo', src: string, watch: string}|null
     */
    public static function player(string $url): ?array
    {
        $video = self::parse(trim($url));
        if ($video === null) {
            return null;
        }
        ['provider' => $provider, 'id' => $id, 'start' => $start, 'hash' => $hash] = $video;

        if ($provider === 'youtube') {
            return [
                'provider' => 'youtube',
                'src' => "https://www.youtube-nocookie.com/embed/{$id}?rel=0&autoplay=1".($start !== null ? "&start={$start}" : ''),
                'watch' => "https://www.youtube.com/watch?v={$id}".($start !== null ? "&t={$start}s" : ''),
            ];
        }

        // an unlisted vimeo video plays only with its private hash
        return [
            'provider' => 'vimeo',
            'src' => "https://player.vimeo.com/video/{$id}?autoplay=1".($hash !== null ? "&h={$hash}" : ''),
            'watch' => "https://vimeo.com/{$id}".($hash !== null ? "/{$hash}" : ''),
        ];
    }

    private static function embedSrc(string $url, bool $autoplay): ?string
    {
        $video = self::parse($url);
        if ($video === null) {
            return null;
        }
        ['provider' => $provider, 'id' => $id] = $video;

        return $provider === 'youtube'
            ? "https://www.youtube-nocookie.com/embed/{$id}?rel=0".($autoplay ? '&autoplay=1&mute=1' : '')
            : "https://player.vimeo.com/video/{$id}".($autoplay ? '?autoplay=1&muted=1' : '');
    }

    /**
     * The host and video id of a youtube/vimeo URL in any common shape, or null; plus a youtube
     * start second (`start=` or `t=`, whole seconds) and a vimeo private hash (`h=` or the path's
     * second part), each kept only when it matches its strict shape.
     *
     * @return array{provider: 'youtube'|'vimeo', id: string, start: int|null, hash: string|null}|null
     */
    private static function parse(string $url): ?array
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
            $at = $query['start'] ?? $query['t'] ?? null;
            $start = is_string($at) && preg_match('~^(\d{1,6})s?$~', $at, $s) && (int) $s[1] > 0 ? (int) $s[1] : null;

            return ['provider' => 'youtube', 'id' => $youtubeId, 'start' => $start, 'hash' => null];
        }

        $hash = isset($query['h']) && is_string($query['h']) && preg_match('~^[0-9a-f]{6,20}$~', $query['h']) ? $query['h'] : null;

        if (in_array($host, ['vimeo.com', 'www.vimeo.com'], true) && preg_match('~^/(\d{6,12})(?:/([0-9a-f]{6,20}))?(?:$|/)~', $path, $m)) {
            return ['provider' => 'vimeo', 'id' => $m[1], 'start' => null, 'hash' => $hash ?? (($m[2] ?? '') !== '' ? $m[2] : null)];
        }

        if ($host === 'player.vimeo.com' && preg_match('~^/video/(\d{6,12})~', $path, $m)) {
            return ['provider' => 'vimeo', 'id' => $m[1], 'start' => null, 'hash' => $hash];
        }

        return null;
    }
}
