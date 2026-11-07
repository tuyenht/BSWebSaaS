<?php

namespace App\Modules\Admin\Console;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\BlockMediaUsage;
use App\Modules\Cms\Support\EntryBlockMediaReconciler;
use App\Modules\Media\Models\Mediable;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Rendering\LayoutSlots;
use Illuminate\Console\Command;

/**
 * Rebuilds the block image usage index (mediables collection='content') per site, for every owner
 * of blocks: each entry, the site's block slots setting and each category with blocks of its own
 * for those slots. Every save reconciles, so this covers content that never passed through a save —
 * seeded pages, a fresh deploy, or self-healing after a drift — and drops the rows of a slot owner
 * that no longer holds slot blocks (a setting or category deleted, or emptied, by a road that
 * skipped the save). Idempotent (it upserts/prunes to match the current content), so it is safe to
 * re-run.
 *
 * Runs per-site inside Tenancy::enter so RLS + the global scope resolve each tenant's own content and
 * write its own mediables (a CLI has no site context otherwise). One transaction per site is fine for
 * a backfill; at very large per-site entry counts, chunk the loop.
 */
class ReconcileContentUsage extends Command
{
    #[\Override]
    protected $signature = 'media:reconcile-content-usage';

    #[\Override]
    protected $description = 'Rebuild block image usage (mediables) for every entry, block slots setting and category, per site';

    public function handle(Tenancy $tenancy, EntryBlockMediaReconciler $reconciler, BlockMediaUsage $usage): int
    {
        $reconciled = 0;
        $slotOwners = 0;

        foreach (Site::query()->get() as $site) {
            $siteId = $site->getKey();
            if (! is_int($siteId)) {
                continue;
            }
            $tenancy->enter($siteId, function () use ($reconciler, $usage, &$reconciled, &$slotOwners): void {
                foreach (Entry::query()->cursor() as $entry) {
                    $reconciler->reconcile($entry);
                    $reconciled++;
                }
                $slotOwners += $this->slotOwners($usage);
            });
        }

        $this->info("Reconciled block image usage for {$reconciled} entries and {$slotOwners} block slot owners.");

        return self::SUCCESS;
    }

    /** The current site's slot owners reconciled, after their stale rows are dropped. */
    private function slotOwners(BlockMediaUsage $usage): int
    {
        $count = 0;

        $setting = SiteSetting::query()
            ->where('group_name', LayoutSlots::SETTING_GROUP)->where('key', LayoutSlots::SETTING_KEY)
            ->first(['id', 'value']);
        Mediable::query()->where('mediable_type', 'site_setting')->where('collection', BlockMediaUsage::COLLECTION)
            ->when($setting !== null, fn ($query) => $query->where('mediable_id', '!=', $setting?->getKey()))
            ->delete();
        if ($setting !== null) {
            $usage->sync('site_setting', Cast::int($setting->getKey()), LayoutSlots::blockLists($setting->value));
            $count++;
        }

        $categories = Taxonomy::query()->whereNotNull('meta->'.LayoutSlots::META_KEY);
        Mediable::query()->where('mediable_type', 'taxonomy')->where('collection', BlockMediaUsage::COLLECTION)
            ->whereNotIn('mediable_id', (clone $categories)->select('id'))
            ->delete();
        foreach ($categories->select(['id', 'meta'])->cursor() as $category) {
            $usage->sync('taxonomy', $category->id, LayoutSlots::blockLists(Cast::arr($category->meta)[LayoutSlots::META_KEY] ?? null));
            $count++;
        }

        return $count;
    }
}
