<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\SiteSearchWords;
use Illuminate\Support\Facades\DB;

/**
 * The storefront search words of categories and tags (`taxonomy_search_words`, SiteSearchWords): per
 * language, the words of the name. A rename rewrites one category's few rows, never the words of its
 * items: the search matches the category by its own words and reads its items through the pivots.
 *
 * Written by the model events of TaxonomyTranslation (a name saved, a language added, moved or
 * removed), in the transaction of the save; corrected by `search:words`. Inside a save, the save holds
 * the translation row's lock (put() writes the name just saved, putSaved() reads the saved row back).
 * sync(), for `search:words`, takes the locks every category edit takes first: the site's category
 * tree lock (TaxonomyTree::lock, before the translations as in every category action and the content
 * pack load), then the category row (FOR NO KEY UPDATE), and only then reads the names, by a
 * statement of their own. Nothing writes the name past the model events
 * (SiteSearchWordsChokepointTest).
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
     * Write the words of one language of one item of $siteId from the translation row a save just
     * wrote, read back by its id (the save holds its lock), in the caller's transaction: for a model
     * that does not hold the name (loaded with a few columns).
     */
    public static function putSaved(int $siteId, int $translationId): void
    {
        $row = DB::selectOne('select taxonomy_id, locale, name from taxonomy_translations where site_id = ? and id = ?', [$siteId, $translationId]);
        if (is_object($row)) {
            $row = (array) $row;
            self::put($siteId, Cast::int($row['taxonomy_id'] ?? 0), Cast::string($row['locale'] ?? ''), Cast::stringOrNull($row['name'] ?? null));
        }
    }

    /**
     * Write the words of one taxonomy of $siteId again from its stored names, in the caller's
     * transaction: of $locale only, or of every language when null. A language or a category that no
     * longer exists keeps no words.
     *
     * The category tree lock of the site, then the category row, then the names read after both: a
     * rename of the same category ends before the names are read, in the order it takes its own locks
     * (tree, translations, category row), so the two wait for each other instead of deadlocking.
     */
    public static function sync(int $siteId, int $taxonomyId, ?string $locale = null): void
    {
        TaxonomyTree::lock($siteId);
        $byLocale = [];
        if (DB::selectOne('select 1 from taxonomies where site_id = ? and id = ? for no key update', [$siteId, $taxonomyId]) !== null) {
            $query = 'select locale, name from taxonomy_translations where site_id = ? and taxonomy_id = ?';
            $bindings = [$siteId, $taxonomyId];
            if ($locale !== null) {
                $query .= ' and locale = ?';
                $bindings[] = $locale;
            }
            foreach (DB::select($query.' order by locale', $bindings) as $row) {
                $row = (array) $row;
                $byLocale[Cast::string($row['locale'] ?? '')] = self::of(Cast::stringOrNull($row['name'] ?? null));
            }
        }

        SiteSearchWords::write(self::TABLE, $siteId, $taxonomyId, $locale, $byLocale);
    }
}
