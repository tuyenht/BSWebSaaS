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
 * Written by the model events of EntryTranslation (a title or an excerpt saved, a language added,
 * moved or removed), in the transaction of the save, with the site named explicitly; corrected by
 * `search:words`. Inside a save, the save already holds the translation row's lock and, before it, the
 * entry row's (every path that writes the title or the excerpt of an existing entry locks the entry
 * row first: FOR UPDATE in the editor, the list's inline rename and the entry actions, FOR NO KEY
 * UPDATE in a content-pack load; a new entry's row is held by its insert), so put() writes the values
 * just saved and putSaved() reads the saved row back. sync(), for the check and corrections of `search:words`, keeps the same order:
 * the entry row first (FOR NO KEY UPDATE), then a read of the translations of its own. Nothing writes
 * the title or the excerpt past the model events
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
     * Write the words of one language of one item of $siteId from the translation row a save just
     * wrote, read back by its id (the save holds its lock), in the caller's transaction: for a model
     * that does not hold both the title and the excerpt (loaded with a few columns).
     */
    public static function putSaved(int $siteId, int $translationId): void
    {
        $row = DB::selectOne('select entry_id, locale, title, excerpt from entry_translations where site_id = ? and id = ?', [$siteId, $translationId]);
        if (is_object($row)) {
            $row = (array) $row;
            self::put($siteId, Cast::int($row['entry_id'] ?? 0), Cast::string($row['locale'] ?? ''), Cast::stringOrNull($row['title'] ?? null), Cast::stringOrNull($row['excerpt'] ?? null));
        }
    }

    /**
     * Write the words of one entry of $siteId again from its stored translations, in the caller's
     * transaction: of $locale only, or of every language when null. A language or an entry that no
     * longer exists keeps no words.
     *
     * The entry row is locked first (FOR NO KEY UPDATE; every path that writes the title or the
     * excerpt of an existing entry takes the row lock before it writes the translation), so a save of
     * the same entry ends before the translations are read, and they are read by a statement of their
     * own after the lock: a language that save added is seen and keeps its words.
     */
    public static function sync(int $siteId, int $entryId, ?string $locale = null): void
    {
        $byLocale = [];
        if (DB::selectOne('select 1 from entries where site_id = ? and id = ? for no key update', [$siteId, $entryId]) !== null) {
            $query = 'select locale, title, excerpt from entry_translations where site_id = ? and entry_id = ?';
            $bindings = [$siteId, $entryId];
            if ($locale !== null) {
                $query .= ' and locale = ?';
                $bindings[] = $locale;
            }
            foreach (DB::select($query.' order by locale', $bindings) as $row) {
                $row = (array) $row;
                $byLocale[Cast::string($row['locale'] ?? '')] = self::of(Cast::stringOrNull($row['title'] ?? null), Cast::stringOrNull($row['excerpt'] ?? null));
            }
        }

        SiteSearchWords::write(self::TABLE, $siteId, $entryId, $locale, $byLocale);
    }
}
