<?php

declare(strict_types=1);

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryItemCounts;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-count every category's items (CategoryItemCounts: the live posts and products whose main
 * category it is) and correct `taxonomies.items_count` where it drifted. Scheduled nightly, so a
 * write path that missed the counter heals within a day, and the warning it logs per drift (site,
 * category, old → new) shows which path that was. Run once after the deploy that adds the counter,
 * to fill the existing rows.
 *
 * Site by site, inside each site's tenant context (RLS and the site scope pick the site's own
 * rows), like cms:fill-ulids, walking each site's categories by id in chunks:
 *
 * - A chunk is counted WITHOUT locks (one grouped statement), so the saves running meanwhile never
 *   wait for it.
 * - Only the categories that look drifted are then corrected, in a short transaction of their own:
 *   their rows are locked FOR NO KEY UPDATE in id order — the lock the counter's own UPDATE takes,
 *   so a save that is filing an item under one of them (its pivot insert holds FOR KEY SHARE on
 *   the category) neither deadlocks with it nor waits for more than this one step — then counted
 *   again and written. A save that lands before the lock is in the new count; one after it adds
 *   its change to the corrected number. Never lost. Only rows that differ are written, so a
 *   second run reports 0.
 * - A site that fails is logged (Log::error) and skipped; the other sites are still reconciled,
 *   and the command then exits non-zero so the scheduler's failure hooks see it.
 */
final class ReconcileCategoryCounts extends Command
{
    #[\Override]
    protected $signature = 'cms:reconcile-category-counts {--chunk=500 : Categories counted per step}';

    #[\Override]
    protected $description = 'Re-count the posts and products of every category and correct the stored totals (safe to re-run)';

    public function handle(Tenancy $tenancy, CategoryItemCounts $counts): int
    {
        $chunk = max(1, Cast::int($this->option('chunk'), 500));
        $sites = 0;
        $checked = 0;
        $corrected = 0;
        $failed = 0;

        foreach ($tenancy->siteIds() as $siteId) {
            $sites++;
            $after = 0;

            try {
                do {
                    [$read, $drifted, $after] = $tenancy->enter($siteId, fn (): array => $this->countChunk($counts, $after, $chunk));
                    $checked += $read;
                    if ($drifted !== []) {
                        $corrected += $tenancy->enter($siteId, fn (): int => $this->correct($counts, $siteId, $drifted));
                    }
                } while ($read === $chunk);
            } catch (Throwable $e) {
                $failed++;
                Log::error('Category item count reconcile failed for a site', ['site_id' => $siteId, 'exception' => $e]);
                $this->error("Site {$siteId}: {$e->getMessage()}");
            }
        }

        $this->info("Checked {$checked} categor(ies) on {$sites} site(s); corrected {$corrected}.");
        if ($failed > 0) {
            $this->error("Failed on {$failed} site(s); see the log.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Count the current site's next chunk of categories after id $after, without locks.
     *
     * @return array{0: int, 1: list<int>, 2: int} [categories read, ids that look drifted, last id read]
     */
    private function countChunk(CategoryItemCounts $counts, int $after, int $chunk): array
    {
        $stored = [];
        foreach (Taxonomy::query()->where('id', '>', $after)->orderBy('id')->limit($chunk)->get(['id', 'items_count']) as $taxonomy) {
            $stored[$taxonomy->id] = $taxonomy->items_count;
        }
        if ($stored === []) {
            return [0, [], $after];
        }

        $actual = $counts->actual(array_keys($stored));
        $drifted = [];
        foreach ($stored as $id => $count) {
            if (($actual[$id] ?? 0) !== $count) {
                $drifted[] = $id;
            }
        }

        return [count($stored), $drifted, (int) array_key_last($stored)];
    }

    /**
     * Lock these categories of the current site, count them again and write the ones that still
     * differ, logging each. Returns how many were corrected.
     *
     * @param  list<int>  $ids
     */
    private function correct(CategoryItemCounts $counts, int $siteId, array $ids): int
    {
        $stored = [];
        foreach (Taxonomy::query()->whereKey($ids)->orderBy('id')->lock('for no key update')->get(['id', 'items_count']) as $taxonomy) {
            $stored[$taxonomy->id] = $taxonomy->items_count;
        }
        if ($stored === []) {
            return 0;   // deleted since the count
        }

        $actual = $counts->actual(array_keys($stored));
        $drift = [];
        foreach ($stored as $id => $count) {
            $true = $actual[$id] ?? 0;
            if ($true !== $count) {
                $drift[$id] = $true;
                Log::warning('Category item count drifted', ['site_id' => $siteId, 'taxonomy_id' => $id, 'from' => $count, 'to' => $true]);
            }
        }
        $counts->correct($drift);

        return count($drift);
    }
}
