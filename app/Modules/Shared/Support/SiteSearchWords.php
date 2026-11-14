<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The word tables of the storefront search, per website and per language: `entry_search_words` (posts
 * and pages), `product_site_words` (products) and `taxonomy_search_words` (category and tag names).
 * Words are folded like VietnameseFold::text() (no accents, lower case) and split and cut exactly as
 * the admin products search splits them (ProductSearchWords), so a visitor typing "khuon mau" finds
 * "Khuôn Mẫu".
 *
 * Each row of the first two carries a weight: TITLE (1) when the word is in the title, the product
 * name or its code, SUMMARY (3) when it is only in the short description. A word in both is stored
 * once, with the lower weight. The long text (article body, product details), SEO fields and alt text
 * are never stored.
 *
 * This class only splits and writes; which item and language to write, and under which lock, belongs
 * to the writer of each kind (EntrySearchWords, ProductSiteWords, TaxonomySearchWords), called from the
 * model events of the translations in the transaction of the save (SiteSearchWordsChokepointTest).
 */
final class SiteSearchWords
{
    /** Longest word kept, in characters: a longer one is cut (and still found by its start). */
    public const int MAX_LENGTH = 64;

    /** Weight of a word of the title, the product name or the product code. */
    public const int TITLE = 1;

    /** Weight of a word found only in the short description. */
    public const int SUMMARY = 3;

    /** The tables and the column of the item each row belongs to. */
    private const array TABLES = [
        'entry_search_words' => 'entry_id',
        'product_site_words' => 'product_id',
        'taxonomy_search_words' => 'taxonomy_id',
    ];

    /**
     * Folded words of $text, each once, sorted: split on anything that is not a letter (with its marks)
     * or a digit, each cut to MAX_LENGTH characters.
     *
     * @return list<string>
     */
    public static function words(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }
        $parts = preg_split('/[^\p{L}\p{M}\p{N}]+/u', VietnameseFold::text($text), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [];   // not valid UTF-8: nothing to index
        }
        $words = array_values(array_unique(array_map(fn (string $word): string => mb_substr($word, 0, self::MAX_LENGTH), $parts)));
        sort($words, SORT_STRING);

        return $words;
    }

    /** The text of stored rich HTML (a product summary): each tag becomes a space, entities are decoded. */
    public static function plain(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        return html_entity_decode((string) preg_replace('/<[^>]*>/', ' ', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Each word with the lowest weight among the groups it is in, sorted by word.
     *
     * @param  array<int, list<string>>  $groups  weight => words
     * @return array<string, int> word => weight
     */
    public static function weigh(array $groups): array
    {
        $out = [];
        foreach ($groups as $weight => $words) {
            foreach ($words as $word) {
                $out[$word] = min($out[$word] ?? $weight, $weight);
            }
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * Make the stored words of one item of $siteId exactly $byLocale, in the caller's transaction: the
     * rows of $locale (or of every language when null) are deleted, then the new ones inserted. A word
     * met twice keeps the lower weight. The caller holds the lock that keeps two saves of the same item
     * and language from writing at once.
     *
     * @param  array<string, array<string, int>>  $byLocale  locale => word => weight (the weight is not stored for categories)
     */
    public static function write(string $table, int $siteId, int $itemId, ?string $locale, array $byLocale): void
    {
        $column = self::column($table);
        if ($locale === null) {
            DB::delete("delete from {$table} where site_id = ? and {$column} = ?", [$siteId, $itemId]);
        } else {
            DB::delete("delete from {$table} where site_id = ? and {$column} = ? and locale = ?", [$siteId, $itemId, $locale]);
        }

        $locales = $words = $weights = [];
        foreach ($byLocale as $wordLocale => $rows) {
            foreach ($rows as $word => $weight) {
                $locales[] = (string) $wordLocale;
                $words[] = (string) $word;
                $weights[] = (string) $weight;
            }
        }
        if ($words === []) {
            return;
        }

        if ($table === 'taxonomy_search_words') {
            DB::insert(<<<SQL
                insert into {$table} (site_id, locale, word, {$column})
                select ?, l, w, ? from unnest(?::text[], ?::text[]) as u(l, w)
                on conflict do nothing
                SQL, [$siteId, $itemId, self::pgArray($locales), self::pgArray($words)]);

            return;
        }
        DB::insert(<<<SQL
            insert into {$table} (site_id, locale, word, {$column}, weight)
            select ?, l, w, ?, g from unnest(?::text[], ?::text[], ?::smallint[]) as u(l, w, g)
            on conflict (site_id, locale, word, {$column}) do update set weight = least(excluded.weight, {$table}.weight)
            SQL, [$siteId, $itemId, self::pgArray($locales), self::pgArray($words), '{'.implode(',', $weights).'}']);
    }

    /**
     * The stored words of these items of $siteId.
     *
     * @param  list<int>  $itemIds
     * @return array<int, array<string, array<string, int>>> item id => locale => word => weight (sorted by locale, then word)
     */
    public static function stored(string $table, int $siteId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }
        $column = self::column($table);
        $weight = $table === 'taxonomy_search_words' ? self::TITLE.' as weight' : 'weight';
        $rows = DB::select("select {$column} as item, locale, word, {$weight} from {$table} where site_id = ? and {$column} = any (?::bigint[]) order by {$column}, locale, word",
            [$siteId, '{'.implode(',', $itemIds).'}']);
        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $out[Cast::int($row['item'] ?? 0)][Cast::string($row['locale'] ?? '')][Cast::string($row['word'] ?? '')] = Cast::int($row['weight'] ?? 0);
        }

        return $out;
    }

    private static function column(string $table): string
    {
        return self::TABLES[$table] ?? throw new InvalidArgumentException("Not a search word table: {$table}");
    }

    /** @param  list<string>  $values */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(fn (string $v): string => '"'.addcslashes($v, '"\\').'"', $values)).'}';
    }
}
