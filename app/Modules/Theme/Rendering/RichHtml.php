<?php

declare(strict_types=1);

namespace App\Modules\Theme\Rendering;

use App\Modules\Shared\Support\RichTextSanitizer;

/**
 * Prepares one stored formatted-text value (an `html` prop, ADR-19) for printing. Pure: no query of
 * its own — the media rows come from the page's one batched lookup (BlockRenderer).
 *
 * 1. The value was cleaned by RichTextSanitizer::cms when it was written (the body casts), so its
 *    shape is purifier output. A cheap structural check confirms that shape (every tag and
 *    attribute on the CMS allow-list, nested as purifier nests them and all closed, quoted values,
 *    plain web/mail/phone links, styles of allowed properties with plain values); a value that
 *    fails it — only data written round the model, which the write-path test bans — is sanitized
 *    again here. Clean data never pays for the sanitizer on the storefront.
 * 2. Each `img[data-media-id]` becomes a library picture: src/srcset/sizes/width/height from the
 *    library row, lazy loading, the library alt text when the editor gave none; the id itself
 *    is never printed. A picture whose id does not resolve in this site is dropped, and a figure
 *    left with no picture goes too.
 * 3. Video frames become the click-to-load façade (VideoFacades).
 * 4. Tables get their layout for narrow columns (ProseTables).
 */
final readonly class RichHtml
{
    /**
     * `sizes` per picture width (RichTextSanitizer::IMAGE_SIZES): a share of the 48rem text column,
     * matching storefront.css (on a narrow column a medium or small picture set left or right is
     * drawn at half width, so the small one asks for half too).
     */
    private const array SIZES = [
        'full' => '(min-width: 52rem) 48rem, 100vw',
        'lg' => '(min-width: 52rem) 36rem, 75vw',
        'md' => '(min-width: 52rem) 24rem, 50vw',
        'sm' => '(min-width: 52rem) 16rem, 50vw',
    ];

    private const array SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** Elements written `<x />`, never opened and closed. */
    private const array VOID = ['br' => true, 'hr' => true, 'img' => true];

    private const array INLINE = ['strong', 'em', 'b', 'i', 'u', 's', 'sub', 'sup', 'a', 'span', 'br', 'img', 'iframe'];

    private const array BLOCK = ['p', 'h2', 'h3', 'h4', 'h5', 'blockquote', 'ul', 'ol', 'table', 'div', 'hr', 'figure'];

    /**
     * What each element may hold, as purifier's content models print it for the CMS allow-list
     * (XHTML 1.0 Transitional + the figure/figcaption definitions): `flow` = inline and block,
     * `inline` = inline only, a list = exactly those children. '' is the body itself. An element
     * missing here is never accepted — a tag added to the allow-list without a rule fails closed.
     */
    private const array CONTENT = [
        '' => 'flow', 'li' => 'flow', 'td' => 'flow', 'th' => 'flow', 'blockquote' => 'flow', 'div' => 'flow',
        'figcaption' => 'flow', 'figure' => ['figcaption', 'flow'],
        'p' => 'inline', 'h2' => 'inline', 'h3' => 'inline', 'h4' => 'inline', 'h5' => 'inline',
        'strong' => 'inline', 'em' => 'inline', 'b' => 'inline', 'i' => 'inline', 'u' => 'inline', 's' => 'inline',
        'sub' => 'inline', 'sup' => 'inline', 'a' => 'inline', 'span' => 'inline',
        'ul' => ['li'], 'ol' => ['li'], 'table' => ['thead', 'tbody', 'tr'], 'thead' => ['tr'], 'tbody' => ['tr'],
        'tr' => ['td', 'th'],
        // the player's fallback content: text only (purifier keeps markup there, which is then re-sanitized)
        'iframe' => [],
    ];

    /** Containers purifier prints with nothing but whitespace between their children. */
    private const array NO_TEXT = ['ul' => true, 'ol' => true, 'table' => true, 'thead' => true, 'tbody' => true, 'tr' => true];

    /**
     * A colour function exactly as purifier prints one (numbers only) — the one `(` a clean style value
     * may carry.
     */
    private const string COLOUR_FUNCTION = '~\b(?:rgba?|hsla?)\([0-9.,%\s]*\)~i';

    /** Left by a dropped picture so its figure can go too; purified output never holds a comment. */
    private const string DROPPED = '<!--dropped-picture-->';

    public function __construct(
        private RichTextSanitizer $sanitizer,
        private VideoFacades $videos,
    ) {}

    /**
     * The library ids the value's pictures name, for the page's one media lookup.
     *
     * @return list<int>
     */
    public static function imageIds(string $html): array
    {
        if (stripos($html, 'data-media-id') === false) {
            return [];
        }

        preg_match_all('~<img\b[^>]*?\sdata-media-id="(\d{1,18})"~i', $html, $matches);
        $ids = [];
        foreach ($matches[1] as $raw) {
            $id = self::positiveInt($raw);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, string>  $urls  media_id => public URL (this site's resolvable ids only)
     * @param  array<string, string>  $srcsets  URL => srcset
     * @param  array<string, string>  $alts  URL => library alt text
     * @param  array<string, array{0: int, 1: int}>  $dims  URL => [width, height] of the master
     * @return array{html: string, videos: int}
     */
    public function render(string $html, array $urls, array $srcsets = [], array $alts = [], array $dims = []): array
    {
        if (! self::looksSanitized($html)) {
            $html = $this->sanitizer->cms($html);
        }

        if (stripos($html, '<img') !== false) {
            $html = self::pictures($html, $urls, $srcsets, $alts, $dims);
        }

        $out = $this->videos->render($html);
        $out['html'] = ProseTables::apply($out['html']);

        return $out;
    }

    /**
     * Whether $html has exactly the shape RichTextSanitizer::cms prints: every `<` opens or closes a
     * tag on the allow-list carrying only its allowed attributes, each double-quoted with nothing but
     * the four entities purifier writes; tags nest as purifier nests them (CONTENT: no block in a
     * paragraph or an inline, a caption only directly in a figure, no link in a link, list items in
     * lists, cells in rows) and every one is closed, innermost first; links use a web, mail or phone
     * scheme (or none); a style is `prop:value;` declarations of allowed properties with plain values;
     * the only class is the video wrapper. A false answer means "sanitize before printing", never
     * "print as is".
     */
    public static function looksSanitized(string $html): bool
    {
        $opened = substr_count($html, '<');
        if ($opened === 0) {
            return true;
        }

        // each match: the text before the tag, then the tag (closing slash, name, attributes, ` /`);
        // purifier separates attributes with one space — `\s` would also take a vertical tab, which HTML does not
        if (preg_match_all('~([^<]*+)<(/?)([a-z][a-z0-9]*)((?: [a-z][a-z-]*="[^"<>]*")*)( /)?>~', $html, $tags, PREG_SET_ORDER) !== $opened) {
            return false;
        }

        $allowed = self::allowList();
        $content = self::contentModel();
        $stack = [];
        $parent = '';
        $links = 0;
        // a body repeats the same few attribute sets (one style on every cell…): check each once
        $checked = [];
        foreach ($tags as $tag) {
            $name = $tag[3];
            if (isset(self::NO_TEXT[$parent]) && strspn($tag[1], " \t\r\n") !== strlen($tag[1])) {
                return false;
            }
            $selfClosed = isset($tag[5]);

            if ($tag[2] === '/') {
                if ($tag[4] !== '' || $selfClosed || $name !== $parent) {
                    return false;
                }
                array_pop($stack);
                $parent = $stack === [] ? '' : $stack[array_key_last($stack)];
                if ($name === 'a') {
                    $links--;
                }

                continue;
            }

            if (! isset($allowed[$name], $content[$parent][$name]) || $selfClosed !== isset(self::VOID[$name]) || ($name === 'a' && $links > 0)) {
                return false;
            }
            if (! $selfClosed) {
                $stack[] = $parent = $name;
                if ($name === 'a') {
                    $links++;
                }
            }
            $set = $tag[4];
            if ($set === '' || isset($checked[$name][$set])) {
                continue;
            }

            preg_match_all('~ ([a-z][a-z-]*)="([^"]*)"~', $set, $attributes, PREG_SET_ORDER);
            foreach ($attributes as [, $attribute, $value]) {
                if (! isset($allowed[$name][$attribute]) || ! self::valueLooksSanitized($attribute, $value)) {
                    return false;
                }
            }
            $checked[$name][$set] = true;
        }

        return $stack === [];
    }

    private static function valueLooksSanitized(string $attribute, string $value): bool
    {
        // purifier writes only these four entities in an attribute; any other `&` is someone else's encoding
        if (preg_match('~&(?!amp;|quot;|lt;|gt;)~', $value) === 1) {
            return false;
        }

        $decoded = htmlspecialchars_decode($value, ENT_QUOTES);

        return match ($attribute) {
            'href' => preg_match('~^\s|[\x00-\x1F\x7F]~', $decoded) !== 1
                && (preg_match('~^([a-z][a-z0-9+.-]*):~i', $decoded, $scheme) !== 1 || in_array(strtolower($scheme[1]), self::SCHEMES, true)),
            // only a frame carries src in the CMS list: the same players the sanitizer lets through
            'src' => preg_match('~[\x00-\x20\x7F]~', $decoded) !== 1 && preg_match(RichTextSanitizer::SAFE_IFRAME, $decoded) === 1,
            'style' => self::styleLooksSanitized($decoded),
            'class' => $decoded === 'video-embed',
            default => true,
        };
    }

    /**
     * `prop:value;` declarations only, each property on the sanitizer's CSS allow-list and each value
     * plain: letters, digits, `#`, `.`, `%`, `,`, `-` and spaces, plus purifier's numeric colour
     * functions — so no other `(`, no `\`, no quotes, no `url`.
     */
    private static function styleLooksSanitized(string $style): bool
    {
        $properties = self::cssAllowList();
        foreach (explode(';', $style) as $declaration) {
            if ($declaration === '') {
                continue;
            }
            $colon = strpos($declaration, ':');
            if ($colon === false || ! isset($properties[substr($declaration, 0, $colon)])) {
                return false;
            }
            $value = (string) preg_replace(self::COLOUR_FUNCTION, '0', substr($declaration, $colon + 1));
            if (preg_match('~^[#a-z0-9.%, -]+$~i', $value) !== 1 || stripos($value, 'url') !== false || preg_match('~(?:^|[ ,])-[a-z]~i', $value) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * tag => [attribute => true], read from the sanitizer's CMS allow-list so the two never drift.
     *
     * @return array<string, array<string, true>>
     */
    private static function allowList(): array
    {
        /** @var array<string, array<string, true>>|null $cache */
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $allowed = [];
        foreach (explode(',', RichTextSanitizer::CMS_ALLOWED) as $entry) {
            if (preg_match('~^([a-z0-9]+)(?:\[([^\]]*)\])?$~', trim($entry), $parts) !== 1) {
                continue;
            }
            $attributes = isset($parts[2]) && $parts[2] !== '' ? explode('|', $parts[2]) : [];
            $allowed[$parts[1]] = array_fill_keys($attributes, true);
        }

        return $cache = $allowed;
    }

    /**
     * property => true, read from the sanitizer's own CSS allow-list.
     *
     * @return array<string, true>
     */
    private static function cssAllowList(): array
    {
        /** @var array<string, true>|null $cache */
        static $cache = null;

        return $cache ??= array_fill_keys(array_map(trim(...), explode(',', RichTextSanitizer::ALLOWED_CSS)), true);
    }

    /**
     * parent => [child => true], CONTENT with `flow` and `inline` spelled out.
     *
     * @return array<string, array<string, true>>
     */
    private static function contentModel(): array
    {
        /** @var array<string, array<string, true>>|null $cache */
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $model = [];
        foreach (self::CONTENT as $parent => $rule) {
            $children = [];
            foreach ((array) $rule as $part) {
                $children = [...$children, ...match ($part) {
                    'flow' => [...self::INLINE, ...self::BLOCK],
                    'inline' => self::INLINE,
                    default => [$part],
                }];
            }
            $model[$parent] = array_fill_keys($children, true);
        }

        return $cache = $model;
    }

    /**
     * @param  array<int, string>  $urls
     * @param  array<string, string>  $srcsets
     * @param  array<string, string>  $alts
     * @param  array<string, array{0: int, 1: int}>  $dims
     */
    private static function pictures(string $html, array $urls, array $srcsets, array $alts, array $dims): string
    {
        $out = preg_replace_callback('~<img\b([^>]*?)\s?/?>~i', static function (array $match) use ($urls, $srcsets, $alts, $dims): string {
            preg_match_all('~\s([a-z][a-z-]*)="([^"]*)"~', $match[1], $pairs, PREG_SET_ORDER);
            $attributes = [];
            foreach ($pairs as [, $name, $value]) {
                $attributes[$name] = $value;
            }

            $id = self::positiveInt($attributes['data-media-id'] ?? '');
            $url = $id !== null ? ($urls[$id] ?? null) : null;
            if ($url === null) {
                return self::DROPPED;
            }

            $size = in_array($attributes['data-size'] ?? null, RichTextSanitizer::IMAGE_SIZES, true) ? $attributes['data-size'] : 'full';
            $align = in_array($attributes['data-align'] ?? null, RichTextSanitizer::IMAGE_ALIGNS, true) ? $attributes['data-align'] : 'center';
            // the stored alt is already escaped (sanitized output); the library's comes raw
            $alt = trim($attributes['alt'] ?? '') !== '' ? $attributes['alt'] : e($alts[$url] ?? '');

            $tag = '<img class="sf-prose__img sf-prose__img--'.$size.' sf-prose__img--'.$align.'" src="'.e($url).'"';
            if (isset($srcsets[$url])) {
                $tag .= ' srcset="'.e($srcsets[$url]).'" sizes="'.self::SIZES[$size].'"';
            }
            $tag .= ' alt="'.$alt.'"';
            if (isset($dims[$url])) {
                $tag .= ' width="'.$dims[$url][0].'" height="'.$dims[$url][1].'"';
            }

            return $tag.' loading="lazy" decoding="async">';
        }, $html);

        if ($out === null) {
            // A pattern failed (backtrack limit): print no picture rather than an unresolved one.
            return (string) preg_replace('~<img\b[^>]*>~i', '', $html);
        }

        // A figure whose picture was dropped and that holds nothing else keeps nothing worth showing
        // (at most its caption); a figure that never had a picture stays.
        $caption = '<figcaption\b[^>]*>(?:(?!</?figure\b).)*?</figcaption>';
        $dropped = preg_quote(self::DROPPED, '~');
        $out = preg_replace('~<figure\b[^>]*>(?:\s++|'.$caption.')*+'.$dropped.'(?:\s++|'.$caption.'|'.$dropped.')*+</figure>~is', '', $out) ?? $out;

        return str_replace(self::DROPPED, '', $out);
    }

    private static function positiveInt(string $raw): ?int
    {
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($id) ? $id : null;
    }
}
