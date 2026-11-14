<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\SiteSearchWords;
use Illuminate\Support\Facades\DB;

/**
 * The storefront search words of products (`product_site_words`, SiteSearchWords): per language, the
 * words of the name and of the SKU with its endings, made exactly as the admin table makes them
 * (ProductSearchWords::of, weight 1), and of the summary (weight 3). Never the details text or the SEO
 * fields. The admin table `product_search_words` is a separate one and stays as it is.
 *
 * Written by the same model events as the admin words (Product: its SKU; ProductTranslation: its name
 * or summary, a language added or removed), in the transaction of the save; corrected by
 * `search:words`. The product row is locked first (FOR NO KEY UPDATE, as ProductSearchWords::sync
 * does), so it keeps the order every product write already keeps: product row, then its translations.
 * Nothing writes the name, the SKU or the summary past the model events (SiteSearchWordsChokepointTest).
 */
final class ProductSiteWords
{
    public const string TABLE = 'product_site_words';

    /**
     * The words of one language of a product: word => weight.
     *
     * @return array<string, int>
     */
    public static function of(?string $sku, ?string $name, ?string $summary): array
    {
        return SiteSearchWords::weigh([
            SiteSearchWords::TITLE => ProductSearchWords::of($sku, [$name]),
            SiteSearchWords::SUMMARY => SiteSearchWords::words(SiteSearchWords::plain($summary)),
        ]);
    }

    /**
     * Write the words of one product of $siteId again from its stored SKU and translations, in the
     * caller's transaction: of $locale only, or of every language when null. A product or a language
     * that no longer exists keeps no words.
     *
     * Two reads, never one: the product row is locked first (the statement waits for a save of the
     * same product to end), and its translations are read by a statement of their own after the
     * lock, so they are the ones that save committed. Under READ COMMITTED a locking read joined to
     * the translations would return the translations of its own snapshot, from before the wait.
     */
    public static function sync(int $siteId, int $productId, ?string $locale = null): void
    {
        $product = DB::selectOne('select sku from products where site_id = ? and id = ? for no key update', [$siteId, $productId]);
        $byLocale = [];
        if (is_object($product)) {
            $sku = Cast::stringOrNull(((array) $product)['sku'] ?? null);
            $query = 'select locale, name, summary from product_translations where site_id = ? and product_id = ?';
            $bindings = [$siteId, $productId];
            if ($locale !== null) {
                $query .= ' and locale = ?';
                $bindings[] = $locale;
            }
            foreach (DB::select($query.' order by locale', $bindings) as $row) {
                $row = (array) $row;
                $byLocale[Cast::string($row['locale'] ?? '')] = self::of($sku, Cast::stringOrNull($row['name'] ?? null), Cast::stringOrNull($row['summary'] ?? null));
            }
        }

        SiteSearchWords::write(self::TABLE, $siteId, $productId, $locale, $byLocale);
    }
}
