<?php

declare(strict_types=1);

namespace App\Modules\Forms\Support;

use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\SiteSearchWords;
use App\Modules\Shared\Support\WordRange;
use Illuminate\Support\Facades\DB;

/**
 * The per-website word table of the search box of «Khách liên hệ» (`lead_search_terms`): the words a
 * member types to find a lead, so the search is a range of the table's key (leakproof, an index
 * condition under RLS) instead of a LIKE read row by row.
 *
 * - The name's and the email's words, split and folded exactly as the other word tables split them
 *   (SiteSearchWords::words(): no accents, lower case, anything that is not a letter or a digit
 *   separates, each word cut to MAX_LENGTH). "Nguyễn Thị Hoa" gives hoa, nguyen, thi; an email gives
 *   its pieces ("hoa.nguyen@example.test": example, hoa, nguyen, test).
 * - The phone's digits run together, and each of their endings of MIN_PHONE_ENDING digits or more,
 *   so typing a piece of the number from anywhere finds it once three digits are typed, as before:
 *   "0912 345 678" gives 0912345678, 912345678, …, 678, and "345 678", "0912345" or "678" find it.
 *   A typed number shorter than three digits must be a whole word (range()), so "45" or "09" alone
 *   never matches the start of a phone, as before; it still finds a name or an email with that word.
 * - Written only from FormSubmission's model events, in the transaction of the save that stored or
 *   changed the name, email or phone, with the lead's site named explicitly; corrected by
 *   `forms:search-terms`. A lead's words go with it (ON DELETE CASCADE).
 */
final class LeadSearchTerms
{
    public const int MAX_LENGTH = 64;

    public const int MIN_PHONE_ENDING = 3;

    /**
     * The words of a lead, each once, sorted.
     *
     * @return list<string>
     */
    public static function of(?string $name, ?string $email, ?string $phone): array
    {
        $words = [];
        foreach ([...SiteSearchWords::words($name), ...SiteSearchWords::words($email)] as $word) {
            $words[mb_substr($word, 0, self::MAX_LENGTH)] = true;
        }
        $digits = substr((string) preg_replace('/\D+/', '', (string) $phone), -self::MAX_LENGTH);
        for ($i = 0, $n = strlen($digits); $i <= $n - self::MIN_PHONE_ENDING; $i++) {
            $words[substr($digits, $i)] = true;
        }
        $list = array_map(strval(...), array_keys($words));
        sort($list, SORT_STRING);

        return $list;
    }

    /**
     * The SQL condition of the words that $needle finds, on the word column $column, with its bindings:
     * the words that start with it (a range of the key), or, for a number shorter than
     * MIN_PHONE_ENDING digits, that word itself, so one or two digits never match every phone that
     * starts with them.
     *
     * @param  literal-string  $column
     * @return array{0: literal-string, 1: list<string>}
     */
    public static function range(string $column, string $needle): array
    {
        return self::isShortNumber($needle) ? ["{$column} = ?", [$needle]] : WordRange::of($column, $needle);
    }

    private static function isShortNumber(string $word): bool
    {
        return strlen($word) < self::MIN_PHONE_ENDING && ctype_digit($word);
    }

    /**
     * The words a search is made of, each once, the longest first; a word that starts another typed
     * word is left out (the longer one already asks for it), except a number under MIN_PHONE_ENDING
     * digits, which must match a whole word (range()). No word at all ("%", spaces) means the search
     * finds nothing.
     *
     * @return list<string>
     */
    public static function needles(string $search): array
    {
        $words = array_values(array_unique(array_map(
            fn (string $word): string => mb_substr($word, 0, self::MAX_LENGTH),
            SiteSearchWords::sequence($search),
        )));
        usort($words, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a) ?: strcmp($a, $b));
        $kept = [];
        foreach ($words as $word) {
            foreach ($kept as $longer) {
                if (! self::isShortNumber($word) && str_starts_with($longer, $word)) {
                    continue 2;
                }
            }
            $kept[] = $word;
        }

        return $kept;
    }

    /**
     * Make the stored words of one lead of $siteId exactly $words, in one statement, in the caller's
     * transaction: the words no longer there are removed and the new ones added; a word kept is not
     * written again.
     *
     * @param  list<string>  $words
     */
    public static function write(int $siteId, int $leadId, array $words): void
    {
        $array = self::pgArray($words);
        DB::statement(<<<'SQL'
            with gone as (
                delete from lead_search_terms
                where site_id = ? and lead_id = ? and term <> all (?::text[])
            )
            insert into lead_search_terms (site_id, lead_id, term)
            select ?, ?, w from unnest(?::text[]) as w
            on conflict do nothing
            SQL, [$siteId, $leadId, $array, $siteId, $leadId, $array]);
    }

    /**
     * The stored words of these leads of $siteId.
     *
     * @param  list<int>  $leadIds
     * @return array<int, list<string>> lead id → its words, sorted
     */
    public static function stored(int $siteId, array $leadIds): array
    {
        if ($leadIds === []) {
            return [];
        }
        $out = [];
        $rows = DB::select('select lead_id, term from lead_search_terms where site_id = ? and lead_id = any (?::bigint[]) order by lead_id, term',
            [$siteId, '{'.implode(',', $leadIds).'}']);
        foreach ($rows as $row) {
            $row = (array) $row;
            $out[Cast::int($row['lead_id'] ?? 0)][] = Cast::string($row['term'] ?? '');
        }

        return $out;
    }

    /** @param  list<string>  $values */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(fn (string $v): string => '"'.addcslashes($v, '"\\').'"', $values)).'}';
    }
}
