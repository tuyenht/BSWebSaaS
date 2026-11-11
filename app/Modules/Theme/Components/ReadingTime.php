<?php

declare(strict_types=1);

namespace App\Modules\Theme\Components;

/**
 * Small text helpers of a post's byline: how long the drawn text takes to read, and the two initials
 * of the author's name. Pure functions over what the page already holds; no query, no state.
 */
final class ReadingTime
{
    /** Characters a reader takes in a minute of text written without spaces between words (Chinese, Japanese). */
    public const int CJK_CHARS_PER_MINUTE = 500;

    /** Words a reader takes in a minute (the byline rounds up, never below one minute). */
    public const int WORDS_PER_MINUTE = 200;

    /**
     * Whole minutes to read the visible text of $html, at least 1; 0 when there is no text at all.
     */
    public static function minutes(string $html): int
    {
        $html = (string) preg_replace('~<(script|style|template)\b[^>]*>.*?</\1>~is', ' ', $html);
        $text = html_entity_decode(strip_tags((string) preg_replace('~</?(?:p|h[1-6]|li|ul|ol|div|section|article|tr|td|th|br|blockquote|figcaption|figure|summary|details|dt|dd)\b[^>]*>~i', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Han, Hiragana, Katakana and Hangul run together: each character counts, the rest is split on spaces.
        $cjk = '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u';
        $chars = (int) preg_match_all($cjk, $text);
        $words = preg_split('/\s+/u', trim((string) preg_replace($cjk, ' ', $text)), -1, PREG_SPLIT_NO_EMPTY);
        $count = $words === false ? 0 : count($words);

        if ($count === 0 && $chars === 0) {
            return 0;
        }

        return max(1, (int) ceil($count / self::WORDS_PER_MINUTE + $chars / self::CJK_CHARS_PER_MINUTE));
    }

    /**
     * The upper-case first letters of the last two words of a name ("An Vinh" gives "AV").
     */
    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return '';
        }

        $letters = '';
        foreach (array_slice($words, -2) as $word) {
            $letters .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $letters;
    }
}
