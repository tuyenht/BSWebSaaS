<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Console;

use App\Modules\Catalog\Support\ProductSiteWords;
use App\Modules\Cms\Support\EntrySearchWords;
use App\Modules\Cms\Support\TaxonomySearchWords;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\SiteSearchWords;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Compare the storefront search words (SiteSearchWords) of posts and pages, products and categories
 * with the words their stored titles, names, codes and short descriptions make today, with the very
 * same splitting, and write again the items that differ. Safe to re-run: an item whose words are right
 * is not written, so a second run corrects 0.
 *
 * - Without --rolling: every item of each site (the Trash too), by id, --chunk at a time (keyset). Run
 *   once after the code switch of the deploy that adds the tables, and whenever the splitting changes.
 * - With --rolling=N: per kind, the next N items of each site by id after where the previous run
 *   stopped (kept in the cache store per site and kind; past the last item it starts again from the
 *   first), so successive nightly runs check every item, also the ones nobody edits.
 *
 * Site by site in each site's tenant context: a chunk is compared without locks, then each item that
 * looks wrong is written again through its writer (EntrySearchWords, ProductSiteWords,
 * TaxonomySearchWords), which takes the save's lock and reads after it, so a save running meanwhile is
 * never lost. Each item is written in a transaction of its own, so the locks it takes (the site's
 * category tree lock among them) are held for one item, never for a whole chunk, and an item that
 * fails is logged and skipped while the rest of the chunk, the next chunks and the rolling cursor go
 * on. Each site with differences logs one warning per kind (how many, up to ten item ids), so the path
 * that missed them can be found. A site whose check fails is logged and skipped; after any failure the
 * command exits non-zero.
 */
final class RebuildSiteSearchWords extends Command
{
    /** @var array<string, array{items: string, translations: string, item: string, columns: string}> kind => where its words come from */
    private const array KINDS = [
        EntrySearchWords::TABLE => ['items' => 'entries', 'translations' => 'entry_translations', 'item' => 'entry_id', 'columns' => 'title, excerpt'],
        ProductSiteWords::TABLE => ['items' => 'products', 'translations' => 'product_translations', 'item' => 'product_id', 'columns' => 'name, summary'],
        TaxonomySearchWords::TABLE => ['items' => 'taxonomies', 'translations' => 'taxonomy_translations', 'item' => 'taxonomy_id', 'columns' => 'name'],
    ];

    #[\Override]
    protected $signature = 'search:words
        {--site= : Only this site (its id); default every site}
        {--rolling= : Only the next N items of each kind of each site after where the last run stopped}
        {--chunk=500 : Items compared per step}';

    #[\Override]
    protected $description = 'Check the storefront search words of posts, pages, products and categories, and write again the ones that differ (safe to re-run)';

    public function handle(Tenancy $tenancy): int
    {
        $chunk = max(1, Cast::int($this->option('chunk'), 500));
        $rolling = $this->option('rolling') !== null ? max(1, Cast::int($this->option('rolling'), 1)) : null;
        $only = $this->option('site');
        $siteIds = $only !== null ? [Cast::int($only)] : $tenancy->siteIds();
        $sites = $checked = $corrected = $failed = $failedItems = 0;

        foreach ($siteIds as $siteId) {
            $sites++;
            foreach (array_keys(self::KINDS) as $table) {
                $drifted = [];
                try {
                    $step = function (int $after, int $limit) use ($tenancy, $siteId, $table, &$checked, &$drifted, &$failedItems): array {
                        [$read, $wrong, $last] = $tenancy->enter($siteId, fn (): array => $this->compare($table, $siteId, $after, $limit));
                        $checked += $read;
                        foreach ($wrong as $id) {
                            try {
                                $tenancy->enter($siteId, function () use ($table, $siteId, $id): void {
                                    match ($table) {
                                        EntrySearchWords::TABLE => EntrySearchWords::sync($siteId, $id),
                                        ProductSiteWords::TABLE => ProductSiteWords::sync($siteId, $id),
                                        default => TaxonomySearchWords::sync($siteId, $id),
                                    };
                                });
                                $drifted[] = $id;
                            } catch (Throwable $e) {
                                $failedItems++;
                                Log::error('Storefront search words of an item could not be written again', ['site_id' => $siteId, 'table' => $table, 'item_id' => $id, 'exception' => $e]);
                                $this->error("Site {$siteId} ({$table}) item {$id}: {$e->getMessage()}");
                            }
                        }

                        return [$read, $last];
                    };

                    if ($rolling === null) {
                        $after = 0;
                        do {
                            [$read, $after] = $step($after, $chunk);
                        } while ($read === $chunk);
                    } else {
                        $this->roll($siteId, $table, $rolling, $chunk, $step);
                    }
                } catch (Throwable $e) {
                    $failed++;
                    Log::error('Storefront search words check failed for a site', ['site_id' => $siteId, 'table' => $table, 'exception' => $e]);
                    $this->error("Site {$siteId} ({$table}): {$e->getMessage()}");
                }

                if ($drifted !== []) {
                    $corrected += count($drifted);
                    Log::warning('Storefront search words differed from the stored content', [
                        'site_id' => $siteId, 'table' => $table, 'items' => count($drifted), 'sample' => array_slice($drifted, 0, 10),
                    ]);
                }
            }
        }

        $this->info("Checked {$checked} item(s) on {$sites} site(s); corrected {$corrected}.");
        if ($failed > 0 || $failedItems > 0) {
            $this->error("Failed on {$failed} site check(s) and {$failedItems} item(s); see the log.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Where the rolling check of one kind of a site stopped: the last item id it read (0: the start). */
    public static function cursorKey(int $siteId, string $table): string
    {
        return "search:words:rolling:{$table}:{$siteId}";
    }

    /**
     * Check the next $count items of $table's kind of $siteId after the stored cursor, $chunk at a time,
     * and move the cursor on; past the last item the cursor goes back to the start for the next run (or
     * at once, when the stored cursor was already past it).
     *
     * @param  callable(int, int): array{0: int, 1: int}  $step  [items read, last id read]
     */
    private function roll(int $siteId, string $table, int $count, int $chunk, callable $step): void
    {
        $key = self::cursorKey($siteId, $table);
        $after = Cast::int(Cache::get($key), 0);
        $readThisRun = 0;
        while ($count > 0) {
            $limit = min($chunk, $count);
            [$read, $last] = $step($after, $limit);
            $count -= $read;
            $readThisRun += $read;
            if ($read < $limit) {
                if ($read === 0 && $readThisRun === 0 && $after > 0) {
                    $after = 0;   // the stored cursor was past the last item: start again now

                    continue;
                }
                $after = 0;

                break;
            }
            $after = $last;
        }
        Cache::forever($key, $after);
    }

    /**
     * Compare the next items of the current site (after id $after), without locks.
     *
     * @return array{0: int, 1: list<int>, 2: int} [items read, ids of those that differ, last id read]
     */
    private function compare(string $table, int $siteId, int $after, int $limit): array
    {
        $kind = self::KINDS[$table];
        $ids = array_values(array_map(fn (mixed $id): int => Cast::int($id), DB::table($kind['items'])
            ->where('site_id', $siteId)->where('id', '>', $after)->orderBy('id')->limit($limit)->pluck('id')->all()));
        if ($ids === []) {
            return [0, [], $after];
        }

        $skus = $table === ProductSiteWords::TABLE
            ? DB::table('products')->where('site_id', $siteId)->whereIn('id', $ids)->pluck('sku', 'id')->all()
            : [];
        $expected = [];
        $rows = DB::table($kind['translations'])->where('site_id', $siteId)->whereIn($kind['item'], $ids)
            ->selectRaw("{$kind['item']} as item, locale, {$kind['columns']}")->get();
        foreach ($rows as $row) {
            $row = (array) $row;
            $item = Cast::int($row['item'] ?? 0);
            $expected[$item][Cast::string($row['locale'] ?? '')] = match ($table) {
                EntrySearchWords::TABLE => EntrySearchWords::of(Cast::stringOrNull($row['title'] ?? null), Cast::stringOrNull($row['excerpt'] ?? null)),
                ProductSiteWords::TABLE => ProductSiteWords::of(Cast::stringOrNull($skus[$item] ?? null), Cast::stringOrNull($row['name'] ?? null), Cast::stringOrNull($row['summary'] ?? null)),
                default => TaxonomySearchWords::of(Cast::stringOrNull($row['name'] ?? null)),
            };
        }
        $stored = SiteSearchWords::stored($table, $siteId, $ids);

        $wrong = [];
        foreach ($ids as $id) {
            // == : the same words with the same weights, whatever order the rows came in
            $want = array_filter($expected[$id] ?? [], fn (array $words): bool => $words !== []);
            if ($want != ($stored[$id] ?? [])) {
                $wrong[] = $id;
            }
        }

        return [count($ids), $wrong, max($ids)];
    }
}
