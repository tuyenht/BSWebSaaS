<?php

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Support\Settings\BranchList;
use App\Modules\Admin\Support\Settings\FloatingContactInput;
use App\Modules\Admin\Support\Settings\HeadingDecorInput;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\BlockMediaUsage;
use App\Modules\Cms\Support\ContentBlockMediaReconciler;
use App\Modules\Cms\Support\EntryBlockMediaReconciler;
use App\Modules\Cms\Support\EntryCoverUsage;
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
 * for those slots, and each shared block (its body in every language); and the site's branch and
 * factory pictures (collection 'branch', owner the `storefront.footer` setting row, BranchList) and the floating contact's own icon pictures
 * (collection 'contact_icon', owner the `storefront.floating_contact` row) and the heading mask
 * picture (collection 'heading_mask', owner the `theme.heading_decor` row), and each entry's
 * featured picture (collection 'cover', EntryCoverUsage::reconcileSite, set-based, entries in the
 * Trash included), dropping the cover rows of entries that no longer exist. Every save reconciles, so this covers content that never passed through a save —
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
    protected $description = 'Rebuild image usage (mediables) for every entry (blocks and featured picture), block slots setting, category and shared block, per site';

    public function handle(Tenancy $tenancy, EntryBlockMediaReconciler $reconciler, BlockMediaUsage $usage, ContentBlockMediaReconciler $blocks): int
    {
        $reconciled = 0;
        $slotOwners = 0;
        $sharedBlocks = 0;

        foreach (Site::query()->get() as $site) {
            $siteId = $site->getKey();
            if (! is_int($siteId)) {
                continue;
            }
            $tenancy->enter($siteId, function () use ($siteId, $reconciler, $usage, $blocks, &$reconciled, &$slotOwners, &$sharedBlocks): void {
                // the Trash included: a trashed post still reserves its pictures until it is purged
                foreach (Entry::withTrashed()->cursor() as $entry) {
                    $reconciler->reconcile($entry);
                    $reconciled++;
                }
                EntryCoverUsage::reconcileSite($siteId);
                $slotOwners += $this->slotOwners($usage);
                $sharedBlocks += $this->sharedBlocks($blocks);
            });
        }

        $this->info("Reconciled block image usage for {$reconciled} entries, {$slotOwners} block slot owners and {$sharedBlocks} shared blocks.");

        return self::SUCCESS;
    }

    /** The current site's shared blocks reconciled, after the rows of blocks that are gone are dropped. */
    private function sharedBlocks(ContentBlockMediaReconciler $reconciler): int
    {
        Mediable::query()->where('mediable_type', ContentBlockMediaReconciler::OWNER)->where('collection', BlockMediaUsage::COLLECTION)
            ->whereNotIn('mediable_id', ContentBlock::query()->select('id'))
            ->delete();

        $count = 0;
        foreach (ContentBlock::query()->select(['id', 'site_id'])->cursor() as $block) {
            $reconciler->reconcile($block);
            $count++;
        }

        return $count;
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

        $footer = SiteSetting::query()->where('group_name', 'storefront')->where('key', 'footer')->first(['id', 'value']);
        Mediable::query()->where('mediable_type', 'site_setting')->where('collection', BranchList::COLLECTION)
            ->when($footer !== null, fn ($query) => $query->where('mediable_id', '!=', $footer?->getKey()))
            ->delete();
        if ($footer !== null) {
            BranchList::syncUsage(Cast::int($footer->getKey()), Cast::arr($footer->value)['factories'] ?? null);
            $count++;
        }

        $floating = SiteSetting::query()->where('group_name', 'storefront')->where('key', 'floating_contact')->first(['id', 'value']);
        Mediable::query()->where('mediable_type', 'site_setting')->where('collection', FloatingContactInput::ICON_COLLECTION)
            ->when($floating !== null, fn ($query) => $query->where('mediable_id', '!=', $floating?->getKey()))
            ->delete();
        if ($floating !== null) {
            FloatingContactInput::syncIconUsage(Cast::int($floating->getKey()), $floating->value);
            $count++;
        }

        $decor = SiteSetting::query()->where('group_name', 'theme')->where('key', 'heading_decor')->first(['id', 'value']);
        Mediable::query()->where('mediable_type', 'site_setting')->where('collection', HeadingDecorInput::MASK_COLLECTION)
            ->when($decor !== null, fn ($query) => $query->where('mediable_id', '!=', $decor?->getKey()))
            ->delete();
        if ($decor !== null) {
            HeadingDecorInput::syncMaskUsage(Cast::int($decor->getKey()), $decor->value);
            $count++;
        }

        return $count;
    }
}
