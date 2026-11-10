<?php

declare(strict_types=1);

namespace App\Modules\Theme\Rendering;

use App\Modules\Theme\Components\StyleProps;
use Illuminate\Support\Str;

/**
 * The table of contents of a post, built from the h2 headings of its formatted-text blocks and the
 * headings of the gallery, video and FAQ blocks placed in its body, in page order. The
 * sanitizer keeps no `id` on a heading (an author never chooses one), so the anchors are made here,
 * at render time, from the heading text: a Vietnamese-safe slug under a fixed prefix, made unique
 * within the page and never equal to an id the layout or a section already uses. The ids are
 * written only when the contents are drawn (two or more headings); with fewer, the body comes back
 * byte for byte as it went in. Pure: reads the HTML it is given and nothing else.
 */
final class ArticleToc
{
    /** The fewest headings that make a table of contents worth showing. */
    public const int MIN = 2;

    /** The prefix of every anchor made here. */
    public const string PREFIX = 'muc-';

    /** Ids the post layout itself prints. */
    private const array LAYOUT_IDS = ['bai-viet-lien-quan', 'du-an-khac'];

    /**
     * The opening of a gallery, video or FAQ block up to its heading (groups 4 and 5), as an
     * alternative to a formatted-text section: the views print exactly this shape (sf-band.blade.php,
     * sf-section-head.blade.php). A heading that already has an id is not matched.
     */
    private const string BLOCK_HEAD = '|(<section class="sf-band sf-band--[a-z]+ (?:sf-gallery|sf-video-block|sf-faq)">\\s*<div class="sf-container">\\s*<div class="sf-section-head">\\s*)<h2 class="sf-section-head__title">(.*?)</h2>';

    /** The longest slug kept (a long heading must not make a long address). */
    private const int MAX_SLUG = 60;

    /**
     * A heading that already carries its number: "1. …", "2) …", "1.Foo", "1.1 …", "Điều 1 …", "I. …",
     * "A. …". A figure written the Vietnamese way ("2.000 khách hàng", "1.000+ dự án") is not a
     * number, since a level is one or two digits; "1.5 triệu" still reads as a level (ambiguous).
     * Roman and letter numbers are upper case only, so "a) …" or a word ending in a dot are not.
     */
    private const string OWN_NUMBER = '~^(?:(?:điều|mục|chương|phần)\\s+\\d{1,3}\\b|\\d{1,3}[.)](?!\\d)|\\d{1,2}(?:\\.\\d{1,2})+[.)]?(?=\\s)|(?-i:(?:[IVX]{1,5}|[A-Z])[.)])\\s)~iu';

    /**
     * @param  list<string>  $taken  ids already on the page (section anchors, list rows)
     * @return array{html: string, items: list<array{id: string, label: string}>}
     */
    public static function of(string $html, array $taken = []): array
    {
        // Formatted-text blocks (their sections open with this exact tag, richtext_html view), then
        // the head of a gallery, video or FAQ block (sf-band + sf-section-head, no id of its own).
        $toc = self::build($html, $taken, '~(<section class="sf-prose">)(.*?)(</section>)'.self::BLOCK_HEAD.'~s', false);

        return ['html' => $toc['html'], 'items' => $toc['items']];
    }

    /**
     * The parts of a product's description (its formatted text and the site's shared parts after
     * it, each drawn as a formatted-text or block section): one per heading of the highest level the
     * text uses, h2, or h3 when it has no h2 at all; two or more make the section bar.
     *
     * @param  list<string>  $taken  ids already on the page
     * @return array{html: string, items: list<array{id: string, label: string}>}
     */
    public static function parts(string $html, array $taken = []): array
    {
        $level = stripos($html, '<h2') === false ? 'h3' : 'h2';
        $toc = self::build($html, $taken, '~(<section class="sf-prose">)(.*?)(</section>)'.self::BLOCK_HEAD.'~s', false, $level);

        return ['html' => $toc['html'], 'items' => $toc['items']];
    }

    /**
     * The contents of a page on the layout with a table of contents: the h2 headings of its plain and
     * formatted text blocks, each numbered in page order ("1. …"). One rule for the whole page: when
     * any heading already carries the author's own number, none is added and the list shows the
     * headings as written (`own`), so the text and the list never disagree.
     *
     * @param  list<string>  $taken  ids already on the page (section anchors, list rows)
     * @return array{html: string, items: list<array{id: string, label: string}>, own: bool}
     */
    public static function numbered(string $html, array $taken = []): array
    {
        // The plain text block (richtext view) and the formatted one (richtext_html view).
        return self::build($html, $taken, '~(<section class="(?:sf-prose|richtext)">)(.*?)(</section>)~s', true);
    }

    /**
     * @param  list<string>  $taken
     * @return array{html: string, items: list<array{id: string, label: string}>, own: bool}
     */
    private static function build(string $html, array $taken, string $pattern, bool $number, string $level = 'h2'): array
    {
        $none = ['html' => $html, 'items' => [], 'own' => false];
        $heading = '~<'.$level.'>(.*?)</'.$level.'>~s';
        if (stripos($html, '<'.$level) === false) {
            return $none;
        }

        // Groups 1–3: a text section, its inner HTML and its close; groups 4–5 (when the pattern has
        // them): a block's opening and its heading's inner HTML.
        $isBlock = static fn (array $part): bool => ($part[4] ?? '') !== '';
        $headings = 0;
        $own = false;
        preg_replace_callback($pattern, function (array $part) use (&$headings, &$own, $number, $isBlock, $heading): string {
            $found = [1 => []];
            if ($isBlock($part)) {
                $found[1][] = $part[5];
            } else {
                preg_match_all($heading, $part[2], $found);
            }
            $headings += count($found[1]);
            foreach ($found[1] as $inner) {
                $own = $own || ($number && preg_match(self::OWN_NUMBER, self::label($inner)) === 1);
            }

            return $part[0];
        }, $html);
        $number = $number && ! $own;
        if ($headings < self::MIN) {
            return $none;
        }

        $used = array_fill_keys([...StyleProps::LAYOUT_IDS, ...self::LAYOUT_IDS, ...$taken], true);
        $items = [];
        // The anchor of one heading (its inner HTML): null for a heading with no text, which stays as it is.
        $anchor = function (string $inner) use (&$used, &$items, $number): ?array {
            $label = self::label($inner);
            if ($label === '') {
                return null;
            }
            $base = self::PREFIX.(rtrim(Str::limit(Str::slug($label), self::MAX_SLUG, ''), '-') ?: 'muc');
            $id = $base;
            for ($n = 2; isset($used[$id]); $n++) {
                $id = "{$base}-{$n}";
            }
            $used[$id] = true;
            $text = $number ? (count($items) + 1).'. '.ltrim($inner) : $inner;
            $items[] = ['id' => $id, 'label' => $label];

            return ['id' => $id, 'text' => $text];
        };
        $out = preg_replace_callback($pattern, function (array $part) use ($anchor, $isBlock, $heading, $level): string {
            if ($isBlock($part)) {
                $made = $anchor($part[5]);

                return $made === null ? $part[0] : $part[4].'<h2 class="sf-section-head__title" id="'.$made['id'].'">'.$made['text'].'</h2>';
            }
            $inner = preg_replace_callback($heading, function (array $found) use ($anchor, $level): string {
                $made = $anchor($found[1]);

                return $made === null ? $found[0] : '<'.$level.' id="'.$made['id'].'">'.$made['text'].'</'.$level.'>';
            }, $part[2]);

            return $part[1].$inner.$part[3];
        }, $html);

        return count($items) >= self::MIN && is_string($out) ? ['html' => $out, 'items' => $items, 'own' => $own] : $none;
    }

    /** The plain text of a heading's inner HTML, spaces collapsed. */
    private static function label(string $inner): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5)));
    }
}
