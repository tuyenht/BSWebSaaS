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
 *    attribute on the CMS allow-list, quoted values, plain web/mail/phone links, inert styles);
 *    a value that fails it — only data written round the model, which the write-path test bans —
 *    is sanitized again here. Clean data never pays for the sanitizer on the storefront.
 * 2. Each `img[data-media-id]` becomes a library picture: src/srcset/sizes/width/height from the
 *    library row, lazy loading, the library alt text when the editor gave none; the id itself
 *    is never printed. A picture whose id does not resolve in this site is dropped, and a figure
 *    left with no picture goes too.
 * 3. Video frames become the click-to-load façade (VideoFacades).
 */
final readonly class RichHtml
{
    /**
     * `sizes` per picture width (RichTextSanitizer::IMAGE_SIZES): a share of the 48rem text column,
     * matching storefront.css (below 40rem the large and medium widths fill the column, small is half).
     */
    private const array SIZES = [
        'full' => '(min-width: 52rem) 48rem, 100vw',
        'lg' => '(min-width: 52rem) 36rem, (min-width: 40rem) 75vw, 100vw',
        'md' => '(min-width: 52rem) 24rem, (min-width: 40rem) 50vw, 100vw',
        'sm' => '(min-width: 52rem) 16rem, (min-width: 40rem) 33vw, 50vw',
    ];

    private const array SCHEMES = ['http', 'https', 'mailto', 'tel'];

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

        return $this->videos->render($html);
    }

    /**
     * Whether $html has exactly the shape RichTextSanitizer::cms prints: every `<` opens a tag on
     * the allow-list carrying only its allowed attributes, each double-quoted with nothing but the
     * four entities purifier writes; links use a web, mail or phone scheme (or none); styles carry
     * no url()/expression()/escapes; the only class is the video wrapper. A false answer means
     * "sanitize before printing", never "print as is".
     */
    public static function looksSanitized(string $html): bool
    {
        $opened = substr_count($html, '<');
        if ($opened === 0) {
            return true;
        }

        if (preg_match_all('~<(/?)([a-z][a-z0-9]*)((?:\s[a-z][a-z-]*="[^"<>]*")*)\s?/?>~', $html, $tags, PREG_SET_ORDER) !== $opened) {
            return false;
        }

        $allowed = self::allowList();
        foreach ($tags as $tag) {
            $name = $tag[2];
            if (! isset($allowed[$name]) || ($tag[1] === '/' && $tag[3] !== '')) {
                return false;
            }
            if ($tag[3] === '') {
                continue;
            }

            preg_match_all('~\s([a-z][a-z-]*)="([^"]*)"~', $tag[3], $attributes, PREG_SET_ORDER);
            foreach ($attributes as [, $attribute, $value]) {
                if (! isset($allowed[$name][$attribute]) || ! self::valueLooksSanitized($attribute, $value)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function valueLooksSanitized(string $attribute, string $value): bool
    {
        // purifier writes only these four entities in an attribute; any other `&` is someone else's encoding
        if (preg_match('~&(?!amp;|quot;|lt;|gt;)~', $value) === 1) {
            return false;
        }

        $decoded = htmlspecialchars_decode($value, ENT_QUOTES);

        return match ($attribute) {
            'href', 'src' => preg_match('~^\s|[\x00-\x1F\x7F]~', $decoded) !== 1
                && (preg_match('~^([a-z][a-z0-9+.-]*):~i', $decoded, $scheme) !== 1 || in_array(strtolower($scheme[1]), self::SCHEMES, true)),
            'style' => preg_match('~url\s*\(|expression|\\\\|/\*|@|<|>~i', $decoded) !== 1,
            'class' => $decoded === 'video-embed',
            default => true,
        };
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
                return '';
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

        // A figure whose picture did not resolve keeps nothing worth showing (at most its caption).
        return (string) preg_replace('~<figure\b[^>]*>(?:\s++|<figcaption\b[^>]*>(?:(?!</?figure\b).)*?</figcaption>)*+</figure>~is', '', $out);
    }

    private static function positiveInt(string $raw): ?int
    {
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($id) ? $id : null;
    }
}
