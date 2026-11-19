<?php

declare(strict_types=1);

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryPublicCounts;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-count every category's public totals per language (CategoryPublicCounts: the items a visitor
 * sees on the category's page, by its default and its card layout) and correct
 * `taxonomy_translations.public_own_count` / `public_tree_count` where they drifted. Scheduled
 * nightly, so a write path that missed the counters heals within a day, and the warning it logs per
 * drift (site, category, language, old → new) shows which path that was.
 *
 * Run once with `--fill` right after the deploy that adds the counters, to fill the existing rows:
 * every row starts at 0, so nearly every row differs. That run takes smaller steps (FILL_CHUNK
 * categories, so each correcting transaction locks fewer rows, for less time, while saves go on)
 * and logs one summary line per site instead of a warning per row.
 *
 * Site by site, inside each site's tenant context, walking each site's categories by id in chunks
 * (the same predicate as the writer, CategoryPublicCounts::actual()):
 *
 * - A chunk is counted WITHOUT locks, so the saves running meanwhile never wait for it.
 * - Only the categories that look drifted are then corrected, in a short transaction of their own:
 *   their rows are locked FOR NO KEY UPDATE in (category, language) order — the order the writer
 *   updates them in — then counted again and written. A save that lands before the lock is in the
 *   new count; one after it adds its change to the corrected number. Only rows that differ are
 *   written, so a second run reports 0.
 * - A site that fails is logged (Log::error) and skipped; the other sites are still reconciled,
 *   and the command then exits non-zero so the scheduler's failure hooks see it.
 * - Its run time grows with a site's content (a top category's card total counts its whole branch);
 *   the summary line gives it.
 */
final class ReconcileCategoryPublicCounts extends Command
{
    #[\Override]
    protected $signature = 'cms:reconcile-category-public-counts
        {--chunk= : Categories counted per step (default 200; 20 with --fill)}
        {--fill : The first fill after the counters were added: small steps, one summary line per site, no warning per row}';

    private const int CHUNK = 200;

    private const int FILL_CHUNK = 20;

    #[\Override]
    protected $description = 'Re-count the items visitors see on every category page, per language, and correct the stored totals (safe to re-run)';

    public function handle(Tenancy $tenancy, CategoryPublicCounts $counts): int
    {
        $fill = (bool) $this->option('fill');
        $given = $this->option('chunk');   // not given, empty or not a number: the mode's default
        $chunk = max(1, Cast::int($given, $fill ? self::FILL_CHUNK : self::CHUNK));
        $started = microtime(true);
        $sites = 0;
        $checked = 0;
        $corrected = 0;
        $failed = 0;

        foreach ($tenancy->siteIds() as $siteId) {
            $sites++;
            $after = 0;
            $filled = 0;

            try {
                do {
                    [$read, $drifted, $after] = $tenancy->enter($siteId, fn (): array => $this->countChunk($counts, $siteId, $after, $chunk));
                    $checked += $read;
                    if ($drifted !== []) {
                        $written = $tenancy->enter($siteId, fn (): int => $this->correct($counts, $siteId, $drifted, $fill));
                        $corrected += $written;
                        $filled += $written;
                    }
                } while ($read === $chunk);
                if ($fill) {
                    Log::info('Category public counts filled', ['site_id' => $siteId, 'rows' => $filled]);
                    $this->line("Site {$siteId}: filled {$filled} row(s).");
                }
            } catch (Throwable $e) {
                $failed++;
                Log::error('Category public count reconcile failed for a site', ['site_id' => $siteId, 'exception' => $e]);
                $this->error("Site {$siteId}: {$e->getMessage()}");
            }
        }

        $seconds = round(microtime(true) - $started, 1);
        $this->info("Checked {$checked} categor(ies) on {$sites} site(s); corrected {$corrected} row(s) in {$seconds}s.");
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
    private function countChunk(CategoryPublicCounts $counts, int $siteId, int $after, int $chunk): array
    {
        $ids = array_values(array_map(static fn (mixed $id): int => Cast::int($id), Taxonomy::query()->where('id', '>', $after)->orderBy('id')->limit($chunk)->pluck('id')->all()));
        if ($ids === []) {
            return [0, [], $after];
        }

        $stored = [];
        foreach (DB::table('taxonomy_translations')->where('site_id', $siteId)->whereIn('taxonomy_id', $ids)->get(['taxonomy_id', 'locale', 'public_own_count', 'public_tree_count']) as $row) {
            $stored[Cast::int($row->taxonomy_id).'|'.Cast::string($row->locale)] = [Cast::int($row->public_own_count), Cast::int($row->public_tree_count)];
        }
        $actual = $counts->actual($ids);

        $drifted = [];
        foreach ($stored as $key => $count) {
            if (($actual[$key] ?? [0, 0]) !== $count) {
                $drifted[(int) explode('|', $key, 2)[0]] = true;
            }
        }

        return [count($ids), array_keys($drifted), $ids[array_key_last($ids)]];
    }

    /**
     * Lock these categories' rows of the current site, count them again and write the ones that
     * still differ, logging each (not on the first fill). Returns how many rows were corrected.
     *
     * @param  list<int>  $ids
     */
    private function correct(CategoryPublicCounts $counts, int $siteId, array $ids, bool $fill): int
    {
        $written = $counts->recount($ids);
        if ($fill) {
            return count($written);
        }
        foreach ($written as $key => [$from, $to]) {
            [$taxonomyId, $locale] = explode('|', $key, 2);
            Log::warning('Category public count drifted', [
                'site_id' => $siteId, 'taxonomy_id' => (int) $taxonomyId, 'locale' => $locale,
                'own' => [$from[0], $to[0]], 'tree' => [$from[1], $to[1]],
            ]);
        }

        return count($written);
    }
}
