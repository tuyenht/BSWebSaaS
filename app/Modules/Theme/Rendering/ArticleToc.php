<?php

declare(strict_types=1);

namespace App\Modules\Theme\Rendering;

use App\Modules\Theme\Components\StyleProps;
use Illuminate\Support\Str;

/**
 * The table of contents of a post, built from the h2 headings of its formatted-text blocks. The
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
        // Formatted-text blocks only: their sections open with this exact tag (richtext_html view).
        $toc = self::build($html, $taken, '~(<section class="sf-prose">)(.*?)(</section>)~s', false);

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
    private static function build(string $html, array $taken, string $pattern, bool $number): array
    {
        $none = ['html' => $html, 'items' => [], 'own' => false];
        if (stripos($html, '<h2>') === false) {
            return $none;
        }

        $headings = 0;
        $own = false;
        preg_replace_callback($pattern, function (array $part) use (&$headings, &$own, $number): string {
            $headings += preg_match_all('~<h2>(.*?)</h2>~s', $part[2], $found);
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
        $out = preg_replace_callback($pattern, function (array $part) use (&$used, &$items, $number): string {
            $inner = preg_replace_callback('~<h2>(.*?)</h2>~s', function (array $heading) use (&$used, &$items, $number): string {
                $label = self::label($heading[1]);
                if ($label === '') {
                    return $heading[0];
                }
                $base = self::PREFIX.(rtrim(Str::limit(Str::slug($label), self::MAX_SLUG, ''), '-') ?: 'muc');
                $id = $base;
                for ($n = 2; isset($used[$id]); $n++) {
                    $id = "{$base}-{$n}";
                }
                $used[$id] = true;
                $text = $number ? (count($items) + 1).'. '.ltrim($heading[1]) : $heading[1];
                $items[] = ['id' => $id, 'label' => $label];

                return '<h2 id="'.$id.'">'.$text.'</h2>';
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
