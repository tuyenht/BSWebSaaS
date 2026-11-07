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
     * @param  list<string>  $taken  ids already on the page (section anchors, list rows)
     * @return array{html: string, items: list<array{id: string, label: string}>}
     */
    public static function of(string $html, array $taken = []): array
    {
        $none = ['html' => $html, 'items' => []];
        if (stripos($html, '<h2>') === false) {
            return $none;
        }

        // Formatted-text blocks only: their sections open with this exact tag (richtext_html view).
        $pattern = '~(<section class="sf-prose">)(.*?)(</section>)~s';
        $headings = 0;
        preg_replace_callback($pattern, function (array $part) use (&$headings): string {
            $headings += preg_match_all('~<h2>(.*?)</h2>~s', $part[2]);

            return $part[0];
        }, $html);
        if ($headings < self::MIN) {
            return $none;
        }

        $used = array_fill_keys([...StyleProps::LAYOUT_IDS, ...self::LAYOUT_IDS, ...$taken], true);
        $items = [];
        $out = preg_replace_callback($pattern, function (array $part) use (&$used, &$items): string {
            $inner = preg_replace_callback('~<h2>(.*?)</h2>~s', function (array $heading) use (&$used, &$items): string {
                $label = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($heading[1]), ENT_QUOTES | ENT_HTML5)));
                if ($label === '') {
                    return $heading[0];
                }
                $base = self::PREFIX.(rtrim(Str::limit(Str::slug($label), self::MAX_SLUG, ''), '-') ?: 'muc');
                $id = $base;
                for ($n = 2; isset($used[$id]); $n++) {
                    $id = "{$base}-{$n}";
                }
                $used[$id] = true;
                $items[] = ['id' => $id, 'label' => $label];

                return '<h2 id="'.$id.'">'.$heading[1].'</h2>';
            }, $part[2]);

            return $part[1].$inner.$part[3];
        }, $html);

        return count($items) >= self::MIN && is_string($out) ? ['html' => $out, 'items' => $items] : $none;
    }
}
