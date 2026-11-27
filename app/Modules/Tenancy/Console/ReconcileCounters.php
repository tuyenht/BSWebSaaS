<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Shared\Contracts\CounterReconciler;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Services\SiteCounters;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recount every website's running totals (site_counters) from the rows they count and correct the
 * ones that drifted. Scheduled nightly, so a write path that missed its counter heals within a day,
 * and the warning logged per drift (site, metric, old → new) shows which total it was. Run once
 * after the deploy that adds a metric, to fill the totals of the websites that already have rows.
 *
 * Each module recounts its own metrics (a Shared\Contracts\CounterReconciler tagged
 * CounterReconciler::TAG), so this command depends on no module. Website by website, inside its
 * tenant context (RLS and an explicit site_id pick its own rows):
 *
 * - First a recount WITHOUT locks, in chunks of --chunk rows, compared with the stored totals, so
 *   the uploads running meanwhile never wait for it.
 * - Only a website whose totals look drifted is then corrected, in a transaction of its own and
 *   still without holding its counter rows: one statement reads the true totals and the stored ones
 *   from one snapshot (SiteCounters::drift()), and the difference is added (one upsert, like any
 *   counted change). A change committed before that statement is in both figures, one committed
 *   after it adds itself on top of the correction: never lost, and an upload is never kept waiting
 *   by the recount. Only totals that differ are written, so a second run reports 0.
 * - A website that fails is logged (Log::error) and skipped; the others are still reconciled, and
 *   the command then exits non-zero so the scheduler's failure hooks see it.
 */
final class ReconcileCounters extends Command
{
    #[\Override]
    protected $signature = 'counters:reconcile {--chunk=5000 : Rows counted per step}';

    #[\Override]
    protected $description = "Recount every website's running totals (media storage…) and correct the stored ones (safe to re-run)";

    public function handle(Tenancy $tenancy): int
    {
        $chunk = max(1, Cast::int($this->option('chunk'), 5000));
        /** @var list<CounterReconciler> $reconcilers */
        $reconcilers = iterator_to_array(app()->tagged(CounterReconciler::TAG), false);
        $sites = 0;
        $corrected = 0;
        $failed = 0;

        foreach ($tenancy->siteIds() as $siteId) {
            $sites++;
            try {
                foreach ($reconcilers as $reconciler) {
                    $looks = $tenancy->enter($siteId, fn (): bool => $this->drifted($reconciler, $chunk));
                    if ($looks) {
                        $corrected += $tenancy->enter($siteId, fn (): int => $this->correct($reconciler, $siteId));
                    }
                }
            } catch (Throwable $e) {
                $failed++;
                Log::error('Site counter reconcile failed for a site', ['site_id' => $siteId, 'exception' => $e]);
                $this->error("Site {$siteId}: {$e->getMessage()}");
            }
        }

        $this->info('Checked '.count($reconcilers)." counter group(s) on {$sites} site(s); corrected {$corrected}.");
        if ($failed > 0) {
            $this->error("Failed on {$failed} site(s); see the log.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Whether the current website's stored totals differ from a lock-free recount. */
    private function drifted(CounterReconciler $reconciler, int $chunk): bool
    {
        $actual = $reconciler->count($chunk);
        $stored = app(SiteCounters::class)->read($reconciler->metrics())['values'];
        foreach ($reconciler->metrics() as $metric) {
            if (($actual[$metric] ?? 0) !== ($stored[$metric] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    /** Recount the current website's totals against the stored ones in one snapshot and add each difference, logging it. */
    private function correct(CounterReconciler $reconciler, int $siteId): int
    {
        $counters = app(SiteCounters::class);
        $fixed = 0;
        foreach ($counters->drift($reconciler->metrics(), $reconciler->recount()) as $metric => $seen) {
            if ($seen['true'] !== $seen['stored']) {
                Log::warning('Site counter drifted', ['site_id' => $siteId, 'metric' => $metric, 'from' => $seen['stored'], 'to' => $seen['true']]);
                $counters->add($metric, $seen['true'] - $seen['stored']);
                $fixed++;
            }
        }

        return $fixed;
    }
}
