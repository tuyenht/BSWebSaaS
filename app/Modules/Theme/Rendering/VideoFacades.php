<?php

declare(strict_types=1);

namespace App\Modules\Theme\Rendering;

use App\Modules\Shared\Support\VideoEmbed;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Turns the video frames stored in rich text — VideoEmbed's `<div class="video-embed"><iframe>` as
 * the editor inserts it, or a bare youtube/vimeo iframe — into the click-to-load façade
 * (partials.sf-video) at render time, so stored data never changes and nothing loads from the video
 * host before the visitor presses play. A youtube.com embed plays through youtube-nocookie. A frame
 * whose address VideoEmbed does not recognise is dropped: the sanitizer already strips those on
 * save, this is the second net for older data, so no iframe from rich text ever reaches the page.
 * The input is sanitized HTML (RichTextSanitizer), so its markup shape is known. Pure: no query.
 */
final readonly class VideoFacades
{
    /** The editor's wrapper around one empty frame (HTMLPurifier prints it exactly so). */
    private const string WRAPPED = '~<div class="video-embed">\s*<iframe\b([^>]*)>\s*</iframe>\s*</div>~i';

    /** A paragraph holding nothing but one frame: the façade is a block, so the paragraph goes. */
    // possessive filler: a paragraph of thousands of spaces must not exhaust the regex stack; the
    // sanitizer prints a no-break space as the raw character, not as &nbsp;
    private const string IN_PARAGRAPH = '~<p\b[^>]*>(?:\s++|&nbsp;|\xC2\xA0|<br\s*/?>)*+(<iframe\b[^>]*>\s*</iframe>)(?:\s++|&nbsp;|\xC2\xA0|<br\s*/?>)*+</p>~i';

    /** Any other frame's opening tag, with its closing tag when nothing but space sits between. */
    private const string BARE = '~<iframe\b([^>]*)>(?:\s*</iframe>)?~i';

    public function __construct(private ViewFactory $views) {}

    /**
     * @return array{html: string, videos: int} the HTML with every frame swapped for a façade (or
     *                                          dropped), and how many façades it now holds
     */
    public function render(string $html): array
    {
        if (stripos($html, '<iframe') === false) {
            return ['html' => $html, 'videos' => 0];
        }

        $videos = 0;
        // only frames that become a video count: a dropped unknown frame must not number the one video
        preg_match_all('~<iframe\b([^>]*)>~i', $html, $frames);
        $several = count(array_filter($frames[1], fn (string $attributes): bool => VideoEmbed::player(self::src($attributes)) !== null)) > 1;
        $swap = function (array $match) use (&$videos, $several): string {
            $video = VideoEmbed::player(self::src(is_string($match[1] ?? null) ? $match[1] : ''));
            if ($video === null) {
                return '';
            }
            $videos++;

            return $this->views->make('theme::partials.sf-video', [
                'video' => $video,
                // numbered when there are several, so the videos of a description are told apart by name
                'title' => __('storefront.video_frame_'.$video['provider']).($several ? ' '.$videos : ''),
                'poster' => null,
                'srcset' => null,
            ])->render();
        };

        $out = preg_replace(self::IN_PARAGRAPH, '$1', $html);
        $out = $out !== null ? preg_replace_callback(self::WRAPPED, $swap, $out) : null;
        if ($out !== null) {
            $out = preg_replace_callback(self::BARE, $swap, $out);
        }
        if ($out === null) {
            // A pattern failed (backtrack limit): drop every frame tag rather than print one.
            return ['html' => (string) preg_replace('~</?iframe\b[^>]*>~i', '', $html), 'videos' => 0];
        }

        // A stray closing tag left by an odd frame goes too.
        return ['html' => (string) preg_replace('~</iframe\s*>~i', '', $out), 'videos' => $videos];
    }

    /** The frame's src (entities decoded, a protocol-relative address made https), or ''. */
    private static function src(string $attributes): string
    {
        if (preg_match('~\bsrc\s*=\s*(["\'])(.*?)\1~is', $attributes, $m) !== 1) {
            return '';
        }
        $src = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5);

        return str_starts_with($src, '//') ? 'https:'.$src : $src;
    }
}
