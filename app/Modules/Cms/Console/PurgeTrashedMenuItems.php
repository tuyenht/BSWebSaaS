<?php

declare(strict_types=1);

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Navigation\MenuRules;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;

/**
 * Delete for good the menu items that have sat in the trash longer than MenuRules::TRASH_DAYS
 * days, each with the whole branch under it (the trashed items below it), so no sub-item is left
 * behind at the top of the trash. Scheduled daily.
 *
 * Runs site by site inside Tenancy::enter, like media:purge-trash, so RLS and the model's scope
 * pick each site's own trash (a CLI has no site context otherwise). A site's purge is one
 * transaction; the label rows go with their items (foreign key cascade). Live items are never
 * touched: one under a purged item (which no delete leaves behind) would move to the top level
 * through the parent foreign key, not disappear.
 */
final class PurgeTrashedMenuItems extends Command
{
    private const int CHUNK = 1000;

    #[\Override]
    protected $signature = 'menus:purge-trash';

    #[\Override]
    protected $description = 'Delete for good the menu items left in the trash longer than the retention window';

    public function handle(Tenancy $tenancy): int
    {
        $cutoff = now()->subDays(MenuRules::TRASH_DAYS);
        $purged = 0;

        $tenancy->eachSite(function (int $siteId) use ($cutoff, &$purged): void {
            $stale = MenuItem::onlyTrashed()->where('deleted_at', '<', $cutoff)->pluck('menu_id', 'id')->all();
            if ($stale === []) {
                return;
            }

            $menuIds = array_values(array_unique(array_map(static fn (mixed $id): int => Cast::int($id), $stale)));
            $ids = $this->withTrashedBranches(array_map(static fn (mixed $id): int => Cast::int($id), array_keys($stale)), $menuIds);
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                // forceDelete() skips the model's scopes, hence the explicit site and trash conditions.
                $purged += Cast::int(MenuItem::query()->where('site_id', $siteId)->whereNotNull('deleted_at')->whereKey($chunk)->forceDelete());
            }
        });

        $this->info("Purged {$purged} menu item(s) left in the trash over ".MenuRules::TRASH_DAYS.' day(s).');

        return self::SUCCESS;
    }

    /**
     * The stale items and every trashed item below them, in the given menus.
     *
     * @param  list<int>  $stale
     * @param  list<int>  $menuIds
     * @return list<int>
     */
    private function withTrashedBranches(array $stale, array $menuIds): array
    {
        /** @var array<int, int|null> $parents */
        $parents = MenuItem::onlyTrashed()->whereIn('menu_id', $menuIds)->pluck('parent_id', 'id')->all();

        $found = array_fill_keys($stale, true);
        $frontier = $stale;
        while ($frontier !== []) {
            $lookup = array_fill_keys($frontier, true);
            $frontier = [];
            foreach ($parents as $id => $parent) {
                if ($parent !== null && isset($lookup[$parent]) && ! isset($found[$id])) {
                    $found[$id] = true;
                    $frontier[] = $id;
                }
            }
        }

        return array_keys($found);
    }
}
