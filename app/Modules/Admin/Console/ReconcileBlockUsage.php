<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console;

use App\Modules\Cms\Models\BlockUsage;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\BlockUsageIndex;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Components\BlockReference;
use App\Modules\Theme\Rendering\LayoutSlots;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rebuild `block_usages` (where the website's shared blocks are placed) from what the places hold
 * now: every page or post whose body places a shared block in some language, the website's default
 * slots and each category with slots of its own, and every menu item whose large panel shows one,
 * the Trash included (a place in the Trash keeps its rows until it is gone for good); and every
 * owner that has rows but places nothing any more loses them, as does a row whose owner the site's
 * own read does not find (another website's, a settings row that is not the block slots). Each save
 * keeps the index (BlockUsageIndex), so this covers what never passed through a save (a seed, a hand
 * fix, a drift).
 * Idempotent: a second run writes the same rows.
 *
 * Lives in Admin, beside content:reconcile-usage, because it reads the website's settings rows,
 * which Cms may not touch (module boundaries).
 *
 * Site by site in each site's tenant context (Tenancy::enter: RLS and the site scope pick that
 * site's rows and nothing else), one transaction per site, the owners read --chunk at a time. A site
 * that fails is logged and skipped, and the command exits non-zero.
 */
final class ReconcileBlockUsage extends Command
{
    /** A body places a shared block: one of its top-level blocks is a reference. */
    private const string PLACES_A_BLOCK = '$.blocks[*] ? (@.type == "'.BlockReference::TYPE.'")';

    #[\Override]
    protected $signature = 'blocks:reconcile-usage
        {--site= : Only this site (its id); default every site}
        {--chunk=500 : Owners read per step}';

    #[\Override]
    protected $description = 'Rebuild where the shared blocks are placed (block_usages) from the pages, slots and menus, per site (safe to re-run)';

    public function handle(Tenancy $tenancy, BlockUsageIndex $index): int
    {
        $chunk = max(1, Cast::int($this->option('chunk'), 500));
        $only = $this->option('site');
        $siteIds = $tenancy->siteIds();
        if ($only !== null) {
            $raw = (string) $only;
            $id = ctype_digit($raw) ? (int) $raw : 0;
            if ($id === 0 || ! DB::table('sites')->where('id', $id)->exists()) {
                $this->error("No site with the id {$raw}.");

                return self::INVALID;
            }
            $siteIds = [$id];
        }

        $sites = $owners = $failed = 0;
        foreach ($siteIds as $siteId) {
            $sites++;
            try {
                $owners += Cast::int($tenancy->enter($siteId, fn (): int => $this->site($index, $chunk)));
            } catch (Throwable $e) {
                $failed++;
                Log::error('Shared block usage rebuild failed for a site', ['site_id' => $siteId, 'exception' => $e]);
                $this->error("Site {$siteId}: {$e->getMessage()}");
            }
        }

        $this->info("Rebuilt where shared blocks are placed for {$owners} place(s) on {$sites} site(s).");
        if ($failed > 0) {
            $this->error("Failed on {$failed} site(s); see the log.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The current site's places rewritten; returns how many owners were.
     *
     * @param  int<1, max>  $chunk
     */
    private function site(BlockUsageIndex $index, int $chunk): int
    {
        $count = 0;

        $entries = $this->owners('entry_id', EntryTranslation::query()
            ->whereRaw('jsonb_path_exists(body, ?::jsonpath)', [self::PLACES_A_BLOCK])
            ->distinct()->pluck('entry_id')->all());
        foreach (array_chunk($entries, $chunk) as $ids) {
            $read = Entry::withTrashed()->whereIn('id', $ids)->with('translations:id,site_id,entry_id,locale,body')->get();
            foreach ($read as $entry) {
                $bodies = [];
                foreach ($entry->translations as $translation) {
                    $bodies[$translation->locale] = Cast::arr($translation->body['blocks'] ?? null);
                }
                $index->syncEntry($entry, $bodies);
                $count++;
            }
            $this->dropUnread('entry_id', $ids, $read->modelKeys());
        }

        $categories = $this->owners('taxonomy_id', Taxonomy::query()->whereNotNull('meta->'.LayoutSlots::META_KEY)->pluck('id')->all());
        foreach (array_chunk($categories, $chunk) as $ids) {
            $read = Taxonomy::query()->whereIn('id', $ids)->get(['id', 'site_id', 'meta']);
            foreach ($read as $category) {
                $index->syncTaxonomy($category, Cast::arr($category->meta)[LayoutSlots::META_KEY] ?? null);
                $count++;
            }
            $this->dropUnread('taxonomy_id', $ids, $read->modelKeys());
        }

        $settings = $this->owners('site_setting_id', SiteSetting::query()
            ->where('group_name', LayoutSlots::SETTING_GROUP)->where('key', LayoutSlots::SETTING_KEY)->pluck('id')->all());
        $read = SiteSetting::query()->whereIn('id', $settings)
            ->where('group_name', LayoutSlots::SETTING_GROUP)->where('key', LayoutSlots::SETTING_KEY)
            ->get(['id', 'site_id', 'group_name', 'key', 'value']);
        foreach ($read as $setting) {
            $index->syncSiteSetting($setting, $setting->value);
            $count++;
        }
        $this->dropUnread('site_setting_id', $settings, $read->modelKeys());

        $items = $this->owners('menu_item_id', MenuItem::withTrashed()->where('meta->mega->type', 'block')->pluck('id')->all());
        foreach (array_chunk($items, $chunk) as $ids) {
            $read = MenuItem::withTrashed()->whereIn('id', $ids)->get(['id', 'site_id', 'meta']);
            $index->syncMenuItems($read);
            $count += $read->count();
            $this->dropUnread('menu_item_id', $ids, $read->modelKeys());
        }

        return $count;
    }

    /**
     * Rows of this site whose owner the site-scoped read did not return (an owner of another website,
     * one that is gone, a settings row that holds no block slots) place nothing here: they go.
     *
     * @param  'entry_id'|'taxonomy_id'|'site_setting_id'|'menu_item_id'  $column
     * @param  list<int>  $asked
     * @param  array<array-key, mixed>  $read
     */
    private function dropUnread(string $column, array $asked, array $read): void
    {
        $unread = array_values(array_diff($asked, array_map(Cast::int(...), $read)));
        if ($unread !== []) {
            BlockUsage::query()->whereIn($column, $unread)->delete();
        }
    }

    /**
     * The owners to rewrite: those that place a shared block now, and those with rows (which may
     * place nothing any more), each once, in id order.
     *
     * @param  'entry_id'|'taxonomy_id'|'site_setting_id'|'menu_item_id'  $column
     * @param  array<array-key, mixed>  $placing
     * @return list<int>
     */
    private function owners(string $column, array $placing): array
    {
        $ids = array_map(Cast::int(...), $placing);
        foreach (BlockUsage::query()->whereNotNull($column)->distinct()->pluck($column) as $id) {
            $ids[] = Cast::int($id);
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
