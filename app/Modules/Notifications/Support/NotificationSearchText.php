<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Shared\Support\VietnameseFold;

/**
 * What the Notifications page's search box finds a notice by: its facts, folded once when the notice
 * is written (site_notifications.search_text, migration 2063_01_01_000000), never the fixed wording of
 * its sentence (built when shown, in the reader's language, so "đang chờ bạn duyệt" finds nothing; the
 * tabs cover the kinds).
 *
 * The facts, in this order: who acted (actor), the post's title, the lead's name (or the phone or
 * email it falls back to), the domains, the support person, the member they signed in as and why,
 * then the return note, so that the 512-character cut eats the end of a long note first. A search
 * matches when every typed word is found somewhere in the line, in any order, accents and case
 * ignored ("ay" finds "phay"; "nam nguyen" finds "Nguyễn Văn Nam").
 *
 * Derived only: for() rebuilds it from `data` at any time, so whatever rewrites or clears a notice's
 * data (a customer's personal data deleted or exported) has the same keys to work with here.
 */
final class NotificationSearchText
{
    /** The facts that are searched (data keys), in the order they are written. */
    public const array FACTS = ['actor', 'title', 'name', 'domains', 'staff', 'member', 'reason', 'note'];

    /** The stored line is cut here. */
    public const int MAX = 512;

    /** What the box takes: longer input is cut, and words past the eighth are ignored. */
    public const int QUERY_MAX = 100;

    public const int WORDS_MAX = 8;

    /**
     * The folded line for a notice's facts ('' when it has none to search).
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function for(array $data): string
    {
        $parts = [];
        foreach (self::FACTS as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) || is_int($value)) {
                $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        return mb_substr(VietnameseFold::text(implode(' ', $parts)), 0, self::MAX);
    }

    /**
     * The words a typed query searches for, folded like the stored line: none for a blank query.
     *
     * @return list<string>
     */
    public static function words(string $query): array
    {
        $folded = VietnameseFold::text(mb_substr($query, 0, self::QUERY_MAX));
        $words = preg_split('/\s+/u', trim($folded), -1, PREG_SPLIT_NO_EMPTY);

        return array_slice(array_values(array_unique($words === false ? [] : $words)), 0, self::WORDS_MAX);
    }
}
