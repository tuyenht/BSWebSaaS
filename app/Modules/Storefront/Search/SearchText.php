<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Search;

use App\Modules\Shared\Support\SiteSearchWords;

/**
 * What a visitor typed into the site search, read once: the text as the page shows it again (spaces
 * squeezed, at most MAX_LENGTH characters), and the words it searches for, folded and split exactly as
 * the word tables were written (SiteSearchWords), in the order typed, each once, at most MAX_WORDS.
 * Every word must match a stored word whole except the last, which matches the start of one (the
 * visitor may still be typing it). The accented words typed (lower case, accents kept) put the items
 * whose title holds them first.
 */
final readonly class SearchText
{
    /** Shortest text searched for, in characters (shorter shows the page with its box and groups). */
    public const int MIN_LENGTH = 2;

    /** Longest text read, in characters (the field's maxlength; a longer one is cut). */
    public const int MAX_LENGTH = 100;

    /** Most words searched for; words typed past them are left out (measured: each word adds one probe per candidate). */
    public const int MAX_WORDS = 8;

    /**
     * @param  string  $text  the typed text, squeezed and cut
     * @param  list<string>  $words  the folded words, in typed order, each once; the last is a prefix
     * @param  list<string>  $accented  the typed words that carry accents, in lower case
     */
    private function __construct(
        public string $text,
        public array $words,
        public array $accented,
    ) {}

    public static function from(mixed $raw): self
    {
        $text = is_string($raw) ? trim((string) preg_replace('/\s+/u', ' ', $raw)) : '';
        if (preg_match('//u', $text) !== 1) {
            $text = '';   // not valid UTF-8
        }
        $text = mb_substr($text, 0, self::MAX_LENGTH);
        if (mb_strlen($text) < self::MIN_LENGTH) {
            return new self($text, [], []);
        }

        $words = array_slice(SiteSearchWords::sequence($text), 0, self::MAX_WORDS);
        $accented = [];
        foreach (preg_split('/[^\p{L}\p{M}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $typed) {
            $folded = SiteSearchWords::sequence($typed);
            // a word typed with accents, and one of the words searched for (not past MAX_WORDS)
            if ($folded !== [$typed] && $folded !== [] && in_array($folded[0], $words, true)) {
                $accented[$typed] = true;
            }
        }

        return new self($text, $words, array_map(strval(...), array_keys($accented)));
    }

    /** Whether there is something to search for (two characters or more, at least one word). */
    public function searchable(): bool
    {
        return $this->words !== [];
    }
}
