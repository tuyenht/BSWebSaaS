<?php

namespace App\Modules\Admin\Console;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\EntryBlockMediaReconciler;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Console\Command;

/**
 * Rebuilds the block image usage index (mediables collection='content') for EVERY entry, per site.
 * The editor reconciles on save, so this covers content that never passed through a save — seeded
 * pages, a fresh deploy, or self-healing after a drift. Idempotent (the reconciler upserts/prunes to
 * match the current bodies), so it is safe to re-run.
 *
 * Runs per-site inside Tenancy::enter so RLS + the global scope resolve each tenant's own entries and
 * write its own mediables (a CLI has no site context otherwise). One transaction per site is fine for
 * a backfill; at very large per-site entry counts, chunk the loop.
 */
class ReconcileContentUsage extends Command
{
    #[\Override]
    protected $signature = 'media:reconcile-content-usage';

    #[\Override]
    protected $description = 'Rebuild block image usage (mediables) for every entry, per site';

    public function handle(Tenancy $tenancy, EntryBlockMediaReconciler $reconciler): int
    {
        $reconciled = 0;

        foreach (Site::query()->get() as $site) {
            $siteId = $site->getKey();
            if (! is_int($siteId)) {
                continue;
            }
            $tenancy->enter($siteId, function () use ($reconciler, &$reconciled): void {
                foreach (Entry::query()->cursor() as $entry) {
                    $reconciler->reconcile($entry);
                    $reconciled++;
                }
            });
        }

        $this->info("Reconciled block image usage for {$reconciled} entries.");

        return self::SUCCESS;
    }
}
