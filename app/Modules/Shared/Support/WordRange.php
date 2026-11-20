<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

/**
 * "The words that start with these letters" as a range on a word column of collation "C": under RLS
 * only a leakproof condition can be an index condition, and LIKE 'abc%', starts_with() in another
 * collation, trigram and full-text operators are not (or are not turned into one), while `>=` and `<`
 * on text are. The word tables of the searches (product_search_words, the storefront word tables,
 * lead_search_terms) keep their words in collation "C", so the range walks their primary key.
 */
final class WordRange
{
    /**
     * The first string after every string that starts with $prefix, in the byte order of collation "C"
     * (code point order in UTF-8): the prefix with its last character moved one code point on, always a
     * valid character (surrogates skipped). Null when no such string exists (the prefix is made of the
     * last code point only): the range then has no upper end.
     */
    public static function upperBound(string $prefix): ?string
    {
        $chars = mb_str_split($prefix);
        while ($chars !== []) {
            $last = mb_ord((string) array_pop($chars));
            if ($last < 0x10FFFF) {
                $next = $last + 1 === 0xD800 ? 0xE000 : $last + 1;

                return implode('', $chars).mb_chr($next);
            }
        }

        return null;
    }

    /**
     * The SQL range of the words that start with $needle, on the word column $column, with its bindings.
     *
     * @param  literal-string  $column
     * @return array{0: literal-string, 1: list<string>}
     */
    public static function of(string $column, string $needle): array
    {
        $upper = self::upperBound($needle);

        return $upper === null
            ? ["{$column} >= ?", [$needle]]
            : ["{$column} >= ? and {$column} < ?", [$needle, $upper]];
    }
}
