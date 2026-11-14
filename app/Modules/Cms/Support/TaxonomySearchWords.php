<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\SiteSearchWords;
use Illuminate\Support\Facades\DB;

/**
 * The storefront search words of categories and tags (`taxonomy_search_words`, SiteSearchWords): per
 * language, the words of the name. A rename rewrites one category's few rows, never the words of its
 * items: the search matches the category by its own words and reads its items through the pivots.
 *
 * Written by the model events of TaxonomyTranslation (a name saved, a language added or removed), in
 * the transaction of the save; corrected by `search:words`. Like EntrySearchWords, the save already
 * holds the translation row's lock (sync() reads it FOR NO KEY UPDATE) and the taxonomy row is not
 * locked. Nothing writes the name past the model events (SiteSearchWordsChokepointTest).
 */
final class TaxonomySearchWords
{
    public const string TABLE = 'taxonomy_search_words';

    /**
     * The words of one language of a category: word => weight (always TITLE; the table keeps no weight).
     *
     * @return array<string, int>
     */
    public static function of(?string $name): array
    {
        return SiteSearchWords::weigh([SiteSearchWords::TITLE => SiteSearchWords::words($name)]);
    }

    /**
     * Write the words of one language of one item of $siteId from the values its save just wrote
     * (the save holds that translation row's lock), in the caller's transaction: two statements.
     */
    public static function put(int $siteId, int $taxonomyId, string $locale, ?string $name): void
    {
        $words = self::of($name);
        SiteSearchWords::write(self::TABLE, $siteId, $taxonomyId, $locale, $words === [] ? [] : [$locale => $words]);
    }

    /**
     * Write the words of one taxonomy of $siteId again from its stored names, in the caller's
     * transaction: of $locale only, or of every language when null.
     */
    public static function sync(int $siteId, int $taxonomyId, ?string $locale = null): void
    {
        $query = 'select locale, name from taxonomy_translations where site_id = ? and taxonomy_id = ?';
        $bindings = [$siteId, $taxonomyId];
        if ($locale !== null) {
            $query .= ' and locale = ?';
            $bindings[] = $locale;
        }
        $byLocale = [];
        foreach (DB::select($query.' order by locale for no key update', $bindings) as $row) {
            $row = (array) $row;
            $byLocale[Cast::string($row['locale'] ?? '')] = self::of(Cast::stringOrNull($row['name'] ?? null));
        }

        SiteSearchWords::write(self::TABLE, $siteId, $taxonomyId, $locale, $byLocale);
    }
}
