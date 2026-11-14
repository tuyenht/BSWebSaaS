<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\SiteSearchWords;
use Illuminate\Support\Facades\DB;

/**
 * The storefront search words of posts and pages (`entry_search_words`, SiteSearchWords): per language,
 * the words of the title (weight 1) and of the excerpt (weight 3). Never the body or the SEO fields.
 *
 * Written by the model events of EntryTranslation (a title or an excerpt saved, a language added or
 * removed), in the transaction of the save, with the site named explicitly; corrected by
 * `search:words`. The save already holds the translation row's lock, so two saves of one language
 * write their words one after the other (put() takes the values just saved; sync() reads the row FOR
 * NO KEY UPDATE), and no lock on the entry row is taken (the order every entry action keeps, entry
 * row first, is left as it is). Nothing writes the title or the excerpt past the model events
 * (SiteSearchWordsChokepointTest). Every entry is stored, the Trash, drafts and scheduled ones
 * included: the search decides what is public.
 */
final class EntrySearchWords
{
    public const string TABLE = 'entry_search_words';

    /**
     * The words of one language of an entry: word => weight.
     *
     * @return array<string, int>
     */
    public static function of(?string $title, ?string $excerpt): array
    {
        return SiteSearchWords::weigh([
            SiteSearchWords::TITLE => SiteSearchWords::words($title),
            SiteSearchWords::SUMMARY => SiteSearchWords::words($excerpt),
        ]);
    }

    /**
     * Write the words of one language of one item of $siteId from the values its save just wrote
     * (the save holds that translation row's lock), in the caller's transaction: two statements.
     */
    public static function put(int $siteId, int $entryId, string $locale, ?string $title, ?string $excerpt): void
    {
        $words = self::of($title, $excerpt);
        SiteSearchWords::write(self::TABLE, $siteId, $entryId, $locale, $words === [] ? [] : [$locale => $words]);
    }

    /**
     * Write the words of one entry of $siteId again from its stored translations, in the caller's
     * transaction: of $locale only, or of every language when null. A language that no longer exists
     * keeps no words.
     */
    public static function sync(int $siteId, int $entryId, ?string $locale = null): void
    {
        $query = 'select locale, title, excerpt from entry_translations where site_id = ? and entry_id = ?';
        $bindings = [$siteId, $entryId];
        if ($locale !== null) {
            $query .= ' and locale = ?';
            $bindings[] = $locale;
        }
        $byLocale = [];
        foreach (DB::select($query.' order by locale for no key update', $bindings) as $row) {
            $row = (array) $row;
            $byLocale[Cast::string($row['locale'] ?? '')] = self::of(Cast::stringOrNull($row['title'] ?? null), Cast::stringOrNull($row['excerpt'] ?? null));
        }

        SiteSearchWords::write(self::TABLE, $siteId, $entryId, $locale, $byLocale);
    }
}
