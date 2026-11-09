<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Console;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Catalog\Support\ProductSearchWords;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Compare the stored search words of products (ProductSearchWords) with the words their names and SKU
 * make today, with the very same splitting, and write again the products that differ. Safe to re-run:
 * a product whose words are right is not written, so a second run corrects 0.
 *
 * - Without --recent or --rolling: every product of each site (the Trash too), by id in chunks. Run
 *   once right after the code switch of the deploy that adds the table, before the reload (docs/
 *   runbooks/prod-deploy.md), and whenever the splitting changes.
 * - With --recent=N: the N products of each site edited last (the list's own index), which is where a
 *   write path that missed the words would show first.
 * - With --rolling=N: the next N products of each site by id after where the previous run stopped
 *   (kept in the cache store per site; it starts again from the first product after the last one), so
 *   successive runs check every product, also the ones nobody edits: a write that skips the models
 *   usually leaves the edit time alone. Scheduled nightly together with --recent.
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
        {--rolling= : Also the next N products of each site after where the last run stopped}
        {--chunk=500 : Products compared per step}';

    #[\Override]
    protected $description = 'Check the search words of products against their names and SKU, and write again the ones that differ (safe to re-run)';

    public function handle(Tenancy $tenancy): int
    {
        $chunk = max(1, Cast::int($this->option('chunk'), 500));
        $recent = $this->option('recent') !== null ? max(1, Cast::int($this->option('recent'), 1)) : null;
        $rolling = $this->option('rolling') !== null ? max(1, Cast::int($this->option('rolling'), 1)) : null;
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
                $step = function (int $after, int $limit, bool $byEdit) use ($tenancy, $siteId, &$checked, &$drifted): array {
                    [$read, $wrong, $last] = $tenancy->enter($siteId, fn (): array => $this->compare($siteId, $after, $limit, $byEdit));
                    $checked += $read;
                    if ($wrong !== []) {
                        $tenancy->enter($siteId, function () use ($siteId, $wrong): void {
                            foreach (array_keys($wrong) as $productId) {
                                ProductSearchWords::sync($siteId, $productId);
                            }
                        });
                        $drifted += $wrong;
                    }

                    return [$read, $last];
                };

                if ($recent === null && $rolling === null) {
                    $after = 0;
                    do {
                        [$read, $after] = $step($after, $chunk, false);
                    } while ($read === $chunk);
                }
                if ($recent !== null) {
                    $step(0, $recent, true);
                }
                if ($rolling !== null) {
                    $this->roll($siteId, $rolling, $chunk, $step);
                }
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
     * Check the next $count products of $siteId after the stored cursor, $chunk at a time, and move the
     * cursor on; at the end of the products the cursor goes back to the start for the next run. It
     * starts again from the first product in this same run only when the stored cursor was already past
     * the last one, so a run never checks a product twice.
     *
     * @param  callable(int, int, bool): array{0: int, 1: int}  $step  [products read, last id read]
     */
    private function roll(int $siteId, int $count, int $chunk, callable $step): void
    {
        $key = self::cursorKey($siteId);
        $after = Cast::int(Cache::get($key), 0);
        $readThisRun = 0;
        while ($count > 0) {
            $limit = min($chunk, $count);
            [$read, $last] = $step($after, $limit, false);
            $count -= $read;
            $readThisRun += $read;
            if ($read < $limit) {
                if ($read === 0 && $readThisRun === 0 && $after > 0) {
                    $after = 0;   // the stored cursor was past the last product: start again now

                    continue;
                }
                $after = 0;   // past the last product: the next run starts from the first one

                break;
            }
            $after = $last;
        }
        Cache::forever($key, $after);
    }

    /** Where the rolling check of a site stopped: the last product id it read (0: the start). */
    public static function cursorKey(int $siteId): string
    {
        return "catalog:search-words:rolling:{$siteId}";
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
