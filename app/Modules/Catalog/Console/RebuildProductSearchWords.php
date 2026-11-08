<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Console;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Catalog\Support\ProductSearchWords;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Compare the stored search words of products (ProductSearchWords) with the words their names and SKU
 * make today, with the very same splitting, and write again the products that differ. Safe to re-run:
 * a product whose words are right is not written, so a second run corrects 0.
 *
 * - Without --recent: every product of each site (the Trash too), by id in chunks. Run once after the
 *   deploy that adds the table, before anyone searches, and whenever the splitting changes.
 * - With --recent=N: only the N products of each site edited last (the list's own index), which is
 *   where a write path that missed the words would show first. Scheduled nightly.
 *
 * Site by site in each site's tenant context, like cms:reconcile-category-counts: a chunk is compared
 * without locks, then each product that looks wrong is written again through ProductSearchWords::sync,
 * which locks the product row and reads its names after the lock, so a save running meanwhile is never
 * lost. Each site with differences logs one warning (how many, and up to ten product ulids) so the path
 * that missed them can be found. A site that fails is logged and skipped; the command then exits
 * non-zero.
 */
final class RebuildProductSearchWords extends Command
{
    #[\Override]
    protected $signature = 'catalog:search-words
        {--site= : Only this site (its id); default every site}
        {--recent= : Only the N products of each site edited last}
        {--chunk=500 : Products compared per step}';

    #[\Override]
    protected $description = 'Check the search words of products against their names and SKU, and write again the ones that differ (safe to re-run)';

    public function handle(Tenancy $tenancy): int
    {
        $chunk = max(1, Cast::int($this->option('chunk'), 500));
        $recent = $this->option('recent') !== null ? max(1, Cast::int($this->option('recent'), 1)) : null;
        $only = $this->option('site');
        $siteIds = $only !== null ? [Cast::int($only)] : $tenancy->siteIds();
        $sites = 0;
        $checked = 0;
        $corrected = 0;
        $failed = 0;

        foreach ($siteIds as $siteId) {
            $sites++;
            $drifted = [];

            try {
                $after = 0;
                do {
                    [$read, $wrong, $after] = $tenancy->enter($siteId, fn (): array => $this->compare($siteId, $after, $recent ?? $chunk, $recent !== null));
                    $checked += $read;
                    if ($wrong !== []) {
                        $tenancy->enter($siteId, function () use ($siteId, $wrong): void {
                            foreach (array_keys($wrong) as $productId) {
                                ProductSearchWords::sync($siteId, $productId);
                            }
                        });
                        $drifted += $wrong;
                    }
                } while ($recent === null && $read === $chunk);
            } catch (Throwable $e) {
                $failed++;
                Log::error('Product search words check failed for a site', ['site_id' => $siteId, 'exception' => $e]);
                $this->error("Site {$siteId}: {$e->getMessage()}");
            }

            if ($drifted !== []) {
                $corrected += count($drifted);
                Log::warning('Product search words differed from the names and SKU', [
                    'site_id' => $siteId, 'products' => count($drifted), 'sample' => array_slice(array_values($drifted), 0, 10),
                ]);
            }
        }

        $this->info("Checked {$checked} product(s) on {$sites} site(s); corrected {$corrected}.");
        if ($failed > 0) {
            $this->error("Failed on {$failed} site(s); see the log.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Compare the current site's next products (after id $after, or its last edited ones), without locks.
     *
     * @return array{0: int, 1: array<int, string>, 2: int} [products read, product id → ulid of those that differ, last id read]
     */
    private function compare(int $siteId, int $after, int $limit, bool $recent): array
    {
        $query = $recent
            ? Product::query()->orderByRaw('products.updated_at desc nulls last, products.id desc')
            : Product::withTrashed()->where('products.id', '>', $after)->orderBy('products.id');
        $products = $query->limit($limit)->get(['products.id', 'products.ulid', 'products.sku']);
        if ($products->isEmpty()) {
            return [0, [], $after];
        }

        $ids = array_map(intval(...), array_values($products->modelKeys()));
        $names = [];
        foreach (ProductTranslation::query()->whereIn('product_id', $ids)->get(['product_id', 'name']) as $row) {
            $names[$row->product_id][] = $row->name;
        }
        $stored = ProductSearchWords::stored($siteId, $ids);

        $wrong = [];
        foreach ($products as $product) {
            if (ProductSearchWords::of($product->sku, $names[$product->id] ?? []) !== ($stored[$product->id] ?? [])) {
                $wrong[$product->id] = $product->ulid;
            }
        }

        return [$products->count(), $wrong, max($after, ...$ids)];
    }
}
