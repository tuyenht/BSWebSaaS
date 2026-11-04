<?php

declare(strict_types=1);

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryItemCounts;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-count every category's items (CategoryItemCounts: the live posts and products whose main
 * category it is) and correct `taxonomies.items_count` where it drifted. Scheduled nightly, so a
 * write path that missed the counter heals within a day, and the warning it logs per drift (site,
 * category, old → new) shows which path that was. Run once after the deploy that adds the counter,
 * to fill the existing rows.
 *
 * Site by site, inside each site's tenant context (RLS and the site scope pick the site's own
 * rows), like cms:fill-ulids. Each chunk of categories is its own short transaction, walked by id:
 * its rows are locked first (in id order, as every counter change takes them), then counted with
 * one grouped statement, so a save running meanwhile either lands before the count or adds its
 * change after the corrected number — never lost. Only rows that differ are written, so a second
 * run reports 0.
 */
final class ReconcileCategoryCounts extends Command
{
    #[\Override]
    protected $signature = 'cms:reconcile-category-counts {--chunk=500 : Categories counted per transaction}';

    #[\Override]
    protected $description = 'Re-count the posts and products of every category and correct the stored totals (safe to re-run)';

    public function handle(Tenancy $tenancy, CategoryItemCounts $counts): int
    {
        $chunk = max(1, Cast::int($this->option('chunk'), 500));
        $sites = 0;
        $checked = 0;
        $corrected = 0;

        foreach ($tenancy->siteIds() as $siteId) {
            $sites++;
            $after = 0;

            do {
                [$read, $fixed, $after] = $tenancy->enter($siteId, fn (): array => $this->reconcileChunk($counts, $siteId, $after, $chunk));
                $checked += $read;
                $corrected += $fixed;
            } while ($read === $chunk);
        }

        $this->info("Checked {$checked} categor(ies) on {$sites} site(s); corrected {$corrected}.");

        return self::SUCCESS;
    }

    /**
     * Lock, count and correct the current site's next chunk of categories after id $after.
     *
     * @return array{0: int, 1: int, 2: int} [categories read, corrected, last id read]
     */
    private function reconcileChunk(CategoryItemCounts $counts, int $siteId, int $after, int $chunk): array
    {
        $stored = [];
        foreach (Taxonomy::query()->where('id', '>', $after)->orderBy('id')->limit($chunk)->lockForUpdate()->get(['id', 'items_count']) as $taxonomy) {
            $stored[$taxonomy->id] = $taxonomy->items_count;
        }
        if ($stored === []) {
            return [0, 0, $after];
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

        return [count($stored), count($drift), (int) array_key_last($stored)];
    }
}
