<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Search;

use App\Modules\Shared\Support\SiteSearchWords;

/**
 * Which words of a title or short description a search matched, for the results page to print them in
 * bold: the text cut into pieces, each marked matched or not. A word matches when it folds (as the word
 * tables do) into a word searched for, or into a word starting with the last one; matched words with
 * only spaces between them make one piece. The pieces are plain text: the page escapes each one.
 */
final class SearchHighlight
{
    /** @return list<array{0: string, 1: bool}> text piece, matched */
    public static function segments(string $text, SearchText $q): array
    {
        if ($text === '' || $q->words === []) {
            return $text === '' ? [] : [[$text, false]];
        }
        $last = $q->words[array_key_last($q->words)];
        $exact = array_slice($q->words, 0, -1);
        $parts = preg_split('/([^\p{L}\p{M}\p{N}]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$text];

        /** @var list<array{0: string, 1: bool}> $pieces */
        $pieces = [];
        foreach ($parts as $part) {
            $folded = SiteSearchWords::sequence($part);
            $isWord = $folded !== [] && preg_match('/^[\p{L}\p{M}\p{N}]+$/u', $part) === 1;
            $matched = $isWord && (in_array($folded[0], $exact, true) || str_starts_with($folded[0], $last));
            $pieces[] = [$part, $matched];
        }

        // join: a matched word, spaces, a matched word → one matched piece; then neighbours of the same kind
        $out = [];
        $count = count($pieces);
        for ($i = 0; $i < $count; $i++) {
            [$piece, $matched] = $pieces[$i];
            if (! $matched && $i > 0 && $i + 1 < $count && $pieces[$i - 1][1] && $pieces[$i + 1][1] && trim($piece) === '') {
                $matched = true;
            }
            $end = array_key_last($out);
            if ($end !== null && $out[$end][1] === $matched) {
                $out[$end][0] .= $piece;
            } else {
                $out[] = [$piece, $matched];
            }
        }

        return $out;
    }
}
