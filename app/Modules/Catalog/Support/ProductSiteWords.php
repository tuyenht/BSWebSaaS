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
     */
    public static function sync(int $siteId, int $productId, ?string $locale = null): void
    {
        // one statement: lock the product row, then read its SKU and the translations after the lock
        $query = <<<'SQL'
            select p.sku, t.locale, t.name, t.summary
            from products p
            left join product_translations t on t.product_id = p.id and t.site_id = p.site_id
            SQL;
        $bindings = [];
        if ($locale !== null) {
            $query .= ' and t.locale = ?';
            $bindings[] = $locale;
        }
        array_push($bindings, $siteId, $productId);
        $byLocale = [];
        foreach (DB::select($query.' where p.site_id = ? and p.id = ? order by t.locale for no key update of p', $bindings) as $row) {
            $row = (array) $row;
            $rowLocale = Cast::stringOrNull($row['locale'] ?? null);
            if ($rowLocale !== null) {
                $byLocale[$rowLocale] = self::of(Cast::stringOrNull($row['sku'] ?? null), Cast::stringOrNull($row['name'] ?? null), Cast::stringOrNull($row['summary'] ?? null));
            }
        }

        SiteSearchWords::write(self::TABLE, $siteId, $productId, $locale, $byLocale);
    }
}
