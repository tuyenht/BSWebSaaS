<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\VietnameseFold;
use Illuminate\Support\Facades\DB;

/**
 * The per-website word table of the admin products search (`product_search_words`): every word of a
 * product's names (all languages) and of its SKU, folded like VietnameseFold::text() (no accents, lower
 * case). The list finds a product when each word typed is the start of one of its words, in any order.
 *
 * Why a table: LIKE, trigram and full-text operators are not leakproof, so under RLS no index can serve
 * them and a search read every product of the site (5.8–6.9 s on 200,000 products). A range on a text
 * column of collation "C" (`word >= ? and word < ?`) is leakproof and walks the primary key
 * (site_id, word, product_id) directly.
 *
 * - Words are split on anything that is not a letter (with its marks) or a digit and cut to MAX_LENGTH characters (a
 *   needle is cut the same way, so a cut word still starts with it).
 * - A SKU adds, besides its own words, every ending of at least two characters of its letters and
 *   digits run together, so typing a piece from the middle of a code finds it ("270e" finds
 *   "AB-270E5", and so does "ab270").
 * - The table is written only here, in the transaction of the save that changed a name or a SKU
 *   (the model events of Product and ProductTranslation), with the site named explicitly, and
 *   corrected by `catalog:search-words`. Nothing else writes the two columns past the model events
 *   (ProductSearchWordsChokepointTest).
 */
final class ProductSearchWords
{
    /** Longest word kept, in characters: a longer one is cut (and still found by its start). */
    public const int MAX_LENGTH = 64;

    /** Shortest ending of a SKU that is kept as a word of its own. */
    public const int MIN_SKU_ENDING = 2;

    /**
     * The words of a product: its names in every language and its SKU, each once.
     *
     * @param  iterable<string|null>  $names
     * @return list<string>
     */
    public static function of(?string $sku, iterable $names): array
    {
        $words = [];
        foreach ($names as $name) {
            foreach (self::split((string) $name) as $word) {
                $words[$word] = true;
            }
        }
        if ($sku !== null && $sku !== '') {
            foreach (self::split($sku) as $word) {
                $words[$word] = true;
            }
            $compact = mb_substr(implode('', self::split($sku, cut: false)), -self::MAX_LENGTH);
            for ($i = 0, $n = mb_strlen($compact); $i <= $n - self::MIN_SKU_ENDING; $i++) {
                $words[mb_substr($compact, $i)] = true;
            }
        }

        $list = array_map(strval(...), array_keys($words));
        sort($list, SORT_STRING);

        return $list;
    }

    /**
     * The words a search is made of, each once; a word that starts another typed word is left out (the
     * longer one already asks for it). No word at all ("%", spaces) means the search finds nothing.
     *
     * @return list<string>
     */
    public static function needles(string $search): array
    {
        $words = array_values(array_unique(self::split($search)));
        usort($words, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a) ?: strcmp($a, $b));
        $kept = [];
        foreach ($words as $word) {
            foreach ($kept as $longer) {
                if (str_starts_with($longer, $word)) {
                    continue 2;
                }
            }
            $kept[] = $word;
        }

        return $kept;
    }

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
    public static function range(string $column, string $needle): array
    {
        $upper = self::upperBound($needle);

        return $upper === null
            ? ["{$column} >= ?", [$needle]]
            : ["{$column} >= ? and {$column} < ?", [$needle, $upper]];
    }

    /**
     * Lock one product row of $siteId in the caller's transaction (FOR NO KEY UPDATE, the lock its own
     * updates take); false when it does not exist. Every path that writes a product's name or SKU takes
     * it before it writes a translation, so they all lock the product first and its translations after:
     * two saves of one product wait for each other instead of deadlocking.
     */
    public static function lock(int $siteId, int $productId): bool
    {
        return self::lockedRow($siteId, $productId) !== null;
    }

    /**
     * Write the words of one product of $siteId again from its stored SKU and names, in the caller's
     * transaction: the product row is locked first (lock()), so two saves of the same product write one
     * after the other, and the names are read after the lock, so the later save sees the earlier one's
     * name. Three statements; a product that no longer exists keeps no words.
     */
    public static function sync(int $siteId, int $productId): void
    {
        $product = self::lockedRow($siteId, $productId);
        if ($product === null) {
            DB::delete('delete from product_search_words where site_id = ? and product_id = ?', [$siteId, $productId]);

            return;
        }
        $names = DB::table('product_translations')->where('site_id', $siteId)->where('product_id', $productId)->pluck('name');

        self::write($siteId, $productId, self::of(Cast::stringOrNull(((array) $product)['sku'] ?? null), Cast::strings($names->all())));
    }

    /**
     * Make the stored words of one product exactly $words, in one statement: the words no longer there
     * are removed and the new ones added; a word kept is not written again.
     *
     * @param  list<string>  $words
     */
    public static function write(int $siteId, int $productId, array $words): void
    {
        $array = self::pgArray($words);
        DB::statement(<<<'SQL'
            with gone as (
                delete from product_search_words
                where site_id = ? and product_id = ? and word <> all (?::text[])
            )
            insert into product_search_words (site_id, product_id, word)
            select ?, ?, w from unnest(?::text[]) as w
            on conflict do nothing
            SQL, [$siteId, $productId, $array, $siteId, $productId, $array]);
    }

    /**
     * The stored words of these products of $siteId.
     *
     * @param  list<int>  $productIds
     * @return array<int, list<string>> product id → its words, sorted
     */
    public static function stored(int $siteId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $out = [];
        $rows = DB::select('select product_id, word from product_search_words where site_id = ? and product_id = any (?::bigint[]) order by product_id, word',
            [$siteId, '{'.implode(',', $productIds).'}']);
        foreach ($rows as $row) {
            $row = (array) $row;
            $out[Cast::int($row['product_id'] ?? 0)][] = Cast::string($row['word'] ?? '');
        }

        return $out;
    }

    /**
     * Folded words of $text: split on anything but letters (with their marks) and digits, each cut to MAX_LENGTH.
     *
     * @return list<string>
     */
    private static function split(string $text, bool $cut = true): array
    {
        $parts = preg_split('/[^\p{L}\p{M}\p{N}]+/u', VietnameseFold::text($text), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [];   // not valid UTF-8: nothing to index or to look for
        }

        return $cut ? array_map(fn (string $word): string => mb_substr($word, 0, self::MAX_LENGTH), $parts) : $parts;
    }

    /** The locked product row (its SKU), or null when it does not exist. */
    private static function lockedRow(int $siteId, int $productId): ?object
    {
        $row = DB::selectOne('select sku from products where site_id = ? and id = ? for no key update', [$siteId, $productId]);

        return is_object($row) ? $row : null;
    }

    /** @param  list<string>  $values */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(fn (string $v): string => '"'.addcslashes($v, '"\\').'"', $values)).'}';
    }
}
