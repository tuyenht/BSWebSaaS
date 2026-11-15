<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Theme\Components\StyleProps;
use App\Modules\Theme\Rendering\RichHtml;

/**
 * The «Soạn thảo» body of the post and page editor (ADR-19, one body / two editors).
 *
 * - A body that is empty or holds ONE formatted-text block (`richtext_html`) is edited as one
 *   rich-text document.
 * - A body made only of the older plain-text blocks (`richtext`: a heading and paragraphs) is shown
 *   as that one document: the conversion is one way and happens in memory when the editor opens;
 *   the person's save writes it (the editor keeps a revision of the old body first).
 * - Any other body is a page layout, edited block by block in «Trình dựng».
 *
 * Pictures: the stored HTML names a library picture by its id (`img[data-media-id]`,
 * RichTextSanitizer::cms); the editor's HTML carries the picture's address instead, built from the
 * content-hash storage key, so no id reaches the browser. forEditor() turns ids into addresses,
 * forStorage() turns addresses of this site's library back into ids; an address that is not one of
 * this site's library pictures (another site, a pasted link) is counted, never dropped silently.
 * Each direction costs one media query whatever the number of pictures. Holds no state.
 */
final readonly class EntryProseBody
{
    /** The block the document is stored in. */
    public const string TYPE = 'richtext_html';

    /** The older plain-text block a body may be converted from. */
    public const string PLAIN_TYPE = 'richtext';

    /** The editor's width for a picture (the CDN resizes on demand). */
    private const int EDITOR_WIDTH = 1200;

    /** The picture attributes the CMS profile keeps besides the id (RichTextSanitizer::cms). */
    private const array KEPT = ['alt', 'data-size', 'data-align'];

    private const string IMG = '~<img\b([^>]*?)\s?/?>~i';

    public function __construct(private MediaUrlResolver $urls) {}

    /**
     * How the editor shows $blocks: the document in the editor's form, the look props of its block
     * (kept as they are; their panel is the builder's), and whether it was converted from plain-text
     * blocks. Null when the body is a layout. Plain-text blocks convert only when they all share one
     * look, so no background, spacing or anchor is lost on the way.
     *
     * @param  list<array{type: string, props: array<string, mixed>}>  $blocks
     * @return array{html: string, look: array<string, mixed>, converted: bool}|null
     */
    public function read(array $blocks): ?array
    {
        if ($blocks === []) {
            return ['html' => '', 'look' => [], 'converted' => false];
        }

        if (count($blocks) === 1 && $blocks[0]['type'] === self::TYPE) {
            $html = $blocks[0]['props']['html'] ?? null;

            return ['html' => $this->forEditor(is_string($html) ? $html : ''), 'look' => self::look($blocks[0]['props']), 'converted' => false];
        }

        $look = null;
        $html = '';
        foreach ($blocks as $block) {
            if ($block['type'] !== self::PLAIN_TYPE) {
                return null;
            }
            $own = self::look($block['props']);
            if ($look !== null && $own !== $look) {
                return null;
            }
            $look = $own;
            $html .= self::fromPlainText($block['props']);
        }

        return ['html' => $html, 'look' => $look, 'converted' => true];
    }

    /**
     * The body to store for the editor's document: no block when it holds nothing to show, else the
     * one formatted-text block with its look props. `foreign` counts pictures that are not this
     * site's library pictures (left in the HTML; the sanitizer would drop them).
     *
     * @param  array<string, mixed>  $look
     * @return array{blocks: list<array{type: string, props: array<string, mixed>}>, foreign: int}
     */
    public function write(string $html, array $look): array
    {
        $stored = $this->forStorage($html);
        if (self::isBlank($stored['html'])) {
            return ['blocks' => [], 'foreign' => 0];
        }

        return ['blocks' => [['type' => self::TYPE, 'props' => $look + ['html' => $stored['html']]]], 'foreign' => $stored['foreign']];
    }

    /**
     * Stored HTML → the editor's: each library picture gets its address and loses its id. A picture
     * in the library's Trash still shows (its id is kept on the next save, so restoring it brings it
     * back on the website); one gone from the library for good is left out.
     */
    public function forEditor(string $html): string
    {
        $ids = RichHtml::imageIds($html);
        $keys = [];
        if ($ids !== []) {
            foreach (Media::withTrashed()->whereIn('id', $ids)->get(['id', 'storage_key']) as $media) {
                $keys[(int) $media->id] = $media->storage_key;
            }
        }

        $out = preg_replace_callback(self::IMG, function (array $match) use ($keys): string {
            $attributes = self::attributes($match[1]);
            $id = filter_var($attributes['data-media-id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $key = is_int($id) ? ($keys[$id] ?? null) : null;
            if ($key === null) {
                return '';
            }

            return self::tag('src="'.e($this->editorUrl($key)).'"', $attributes);
        }, $html);

        // A pattern that failed (backtrack limit) shows no picture rather than an id.
        return $out ?? (string) preg_replace('~<img\b[^>]*>~i', '', $html);
    }

    /**
     * The editor's HTML → stored: each picture whose address is one of this site's library pictures
     * names it by id (address dropped); any other picture is left as it is and counted.
     *
     * @return array{html: string, foreign: int}
     */
    public function forStorage(string $html): array
    {
        if (stripos($html, '<img') === false) {
            return ['html' => $html, 'foreign' => 0];
        }

        preg_match_all(self::IMG, $html, $matches);
        $keys = [];
        foreach ($matches[1] as $raw) {
            $key = $this->keyOf(self::attributes($raw));
            if ($key !== null) {
                $keys[$key] = true;
            }
        }
        $ids = $keys === [] ? [] : Media::withTrashed()->whereIn('storage_key', array_keys($keys))->pluck('id', 'storage_key')->all();

        $foreign = 0;
        $out = preg_replace_callback(self::IMG, function (array $match) use ($ids, &$foreign): string {
            $attributes = self::attributes($match[1]);
            $key = $this->keyOf($attributes);
            $id = $key !== null ? ($ids[$key] ?? null) : null;
            if (! is_numeric($id)) {
                $foreign++;

                return $match[0];
            }

            return self::tag('data-media-id="'.(int) $id.'"', $attributes);
        }, $html);

        if ($out === null) {
            return ['html' => $html, 'foreign' => max(1, count($matches[0]))];
        }

        return ['html' => $out, 'foreign' => $foreign];
    }

    /**
     * The address the editor shows a library picture at: the resized one when it can be read back
     * to its storage key (Cloudflare and static drivers), else the file itself.
     */
    public function editorUrl(string $key): string
    {
        $url = $this->urls->url($key, self::EDITOR_WIDTH);

        return $this->urls->keyFromUrl($url) === $key ? $url : $this->urls->fileUrl($key);
    }

    /**
     * Older plain-text block props → formatted text, drawn as the plain-text block drew them: the
     * heading as a level-2 heading, a line break kept as a line break, text between two pairs of
     * asterisks closed in the same line bold, everything else escaped. Empty paragraphs are left out.
     *
     * @param  array<string, mixed>  $props
     */
    public static function fromPlainText(array $props): string
    {
        $html = '';
        $heading = $props['heading'] ?? null;
        if (is_scalar($heading) && trim((string) $heading) !== '') {
            $html .= '<h2>'.e(trim((string) $heading)).'</h2>';
        }

        foreach ((array) ($props['paragraphs'] ?? []) as $paragraph) {
            if (! is_scalar($paragraph) || trim((string) $paragraph) === '') {
                continue;
            }
            $lines = array_map(self::bold(...), preg_split('/\R/u', (string) $paragraph) ?: []);
            $html .= '<p>'.implode('<br>', $lines).'</p>';
        }

        return $html;
    }

    /**
     * The look props of a block (StyleProps), in a fixed order so two blocks compare by value.
     *
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    public static function look(array $props): array
    {
        $look = array_intersect_key($props, array_flip(StyleProps::RESERVED));
        ksort($look);

        return $look;
    }

    /** A short plain-text glimpse of the document (the builder's row for it). */
    public static function preview(string $html, int $limit = 56): string
    {
        $text = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5)));

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit)).'…' : $text;
    }

    /** Whether the HTML shows nothing: no text, no picture, frame, rule or table. */
    public static function isBlank(string $html): bool
    {
        if (preg_match('~<(?:img|iframe|hr|table)\b~i', $html) === 1) {
            return false;
        }

        return preg_replace('/[\s\x{00A0}\x{200B}]+/u', '', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)) === '';
    }

    /** One line of a plain-text paragraph, as the plain-text block draws it. */
    private static function bold(string $line): string
    {
        $parts = explode('**', $line);
        $closed = count($parts) - (count($parts) % 2 === 0 ? 1 : 0);
        $out = '';
        foreach ($parts as $index => $part) {
            if ($index >= $closed && $index > 0) {
                $out .= '**'.e($part);
            } else {
                $out .= $index % 2 === 1 ? '<strong>'.e($part).'</strong>' : e($part);
            }
        }

        return $out;
    }

    /**
     * The storage key a picture's address names in this site's library form, or null.
     *
     * @param  array<string, string>  $attributes
     */
    private function keyOf(array $attributes): ?string
    {
        $src = html_entity_decode($attributes['src'] ?? '', ENT_QUOTES | ENT_HTML5);

        return $src !== '' ? $this->urls->keyFromUrl($src) : null;
    }

    /**
     * An img tag with $first, then the kept attributes as they were written (HTML-escaped values).
     *
     * @param  array<string, string>  $attributes
     */
    private static function tag(string $first, array $attributes): string
    {
        $tag = '<img '.$first;
        foreach (self::KEPT as $name) {
            if (isset($attributes[$name])) {
                $tag .= ' '.$name.'="'.e(html_entity_decode($attributes[$name], ENT_QUOTES | ENT_HTML5)).'"';
            }
        }

        return $tag.'>';
    }

    /**
     * The double-quoted attributes of a tag (the browser writes every attribute that way).
     *
     * @return array<string, string>
     */
    private static function attributes(string $raw): array
    {
        preg_match_all('~\s([a-zA-Z][a-zA-Z0-9-]*)="([^"]*)"~', ' '.$raw, $pairs, PREG_SET_ORDER);
        $attributes = [];
        foreach ($pairs as [, $name, $value]) {
            $attributes[strtolower($name)] ??= $value;
        }

        return $attributes;
    }
}
