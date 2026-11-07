<?php

declare(strict_types=1);

namespace App\Modules\Theme\Rendering;

/**
 * The table of contents of a post: the h2 headings of its drawn body that carry an id, in page
 * order, each with its text and the id to jump to. Fewer than two such headings is no table of
 * contents at all. Pure: reads the HTML it is given and nothing else.
 */
final class ArticleToc
{
    /** The fewest headings that make a table of contents worth showing. */
    public const int MIN = 2;

    /**
     * @return list<array{id: string, label: string}>
     */
    public static function of(string $html): array
    {
        if (stripos($html, '<h2') === false) {
            return [];
        }
        preg_match_all('~<h2\b([^>]*)>(.*?)</h2>~is', $html, $matches, PREG_SET_ORDER);

        $items = [];
        $seen = [];
        foreach ($matches as $match) {
            if (preg_match('~(?<![\w-])id\s*=\s*(?:"([^"]+)"|\'([^\']+)\')~i', $match[1], $id) !== 1) {
                continue;
            }
            $anchor = html_entity_decode(implode('', array_slice($id, 1)), ENT_QUOTES | ENT_HTML5);
            $label = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5)));
            if ($anchor === '' || $label === '' || isset($seen[$anchor])) {
                continue;
            }
            $seen[$anchor] = true;
            $items[] = ['id' => $anchor, 'label' => $label];
        }

        return count($items) >= self::MIN ? $items : [];
    }
}
