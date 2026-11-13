<?php

declare(strict_types=1);

namespace App\Modules\Theme\Seo;

/**
 * The last step of a page's description: the page's own words, used only when the page has no
 * Google description, no excerpt, and the site has neither a default description nor a brand
 * description (a site just created). Every page then still has a description, and each one is its
 * own text, never one sentence shared by many pages.
 *
 *  - a page or a product: the first paragraph it draws that reads as a sentence (20 characters
 *    or more; a form's, a menu's or a button's words never count), else its title (a product's
 *    summary comes first);
 *  - a list: its name followed by the titles it lists first.
 *
 * At most 160 characters, cut at a word. Pure: it reads only the HTML the page already drew.
 */
final class DescriptionFallback
{
    private const int MAX = 160;

    private const int SENTENCE = 20;

    public static function fromHtml(string $html, string $title): string
    {
        // Scripts, styles, drawings, forms (their hints and labels), menus (the breadcrumb) and
        // buttons are not what the page says about itself.
        $html = (string) preg_replace('~<(script|style|svg|noscript|template|form|nav|button|select|textarea|aside)\b[^>]*>.*?</\1>~is', ' ', $html);
        if (preg_match_all('~<p\b[^>]*>(.*?)</p>~is', $html, $paragraphs) > 0) {
            foreach ($paragraphs[1] as $paragraph) {
                $text = self::text($paragraph);
                if (mb_strlen($text) >= self::SENTENCE) {
                    return self::cut($text);
                }
            }
        }

        return self::cut(trim($title));
    }

    /**
     * @param  list<string>  $titles  the titles the list shows first
     */
    public static function forList(string $name, array $titles): string
    {
        $titles = array_values(array_filter(array_map(self::text(...), array_slice($titles, 0, 5)), static fn (string $t): bool => $t !== ''));
        $name = trim($name);

        return self::cut($titles === [] ? $name : $name.': '.implode(', ', $titles));
    }

    public static function cut(string $text): string
    {
        if (mb_strlen($text) <= self::MAX) {
            return $text;
        }
        $head = mb_substr($text, 0, self::MAX);
        if (preg_match('/\s/u', mb_substr($text, self::MAX, 1)) !== 1) {
            $space = mb_strrpos($head, ' ');
            if ($space !== false && $space > 0) {
                $head = mb_substr($head, 0, $space);
            }
        }

        return rtrim($head, " \t\n\r,;:.-");
    }

    /** Plain text of an HTML fragment: tags dropped, entities decoded, white space squeezed. */
    public static function text(string $html): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('~<[^>]+>~', ' $0 ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
