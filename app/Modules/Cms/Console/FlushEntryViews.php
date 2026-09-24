<?php

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Support\EntryViewCounter;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Drains each site's Valkey-buffered page views (EntryViewCounter) into entries.view_count.
 * Per-site inside Tenancy::enter so the UPDATE is RLS-scoped to that site. view_count is bumped
 * with a raw `view_count + n` so updated_at is NOT touched (a view must not look like an edit).
 * Scheduled every few minutes — the buffer smooths the write rate off the storefront hot path.
 */
class FlushEntryViews extends Command
{
    #[\Override]
    protected $signature = 'entries:flush-views';

    #[\Override]
    protected $description = 'Flush Valkey-buffered page views into entries.view_count';

    public function handle(Tenancy $tenancy, EntryViewCounter $counter): int
    {
        $flushed = 0;

        // Draining needs no tenant context, and most sites have no buffered views — only the ones
        // that do pay for a transaction.
        foreach ($tenancy->siteIds() as $siteId) {
            $counts = $counter->drain($siteId);
            if ($counts === []) {
                continue;
            }

            $tenancy->enter($siteId, function () use ($counts, &$flushed): void {
                foreach ($counts as $entryId => $increment) {
                    // Raw bump so updated_at stays put (a view must not look like an edit); RLS
                    // still scopes the UPDATE to this site's own row.
                    DB::update('update entries set view_count = view_count + ? where id = ?', [$increment, $entryId]);
                    $flushed += $increment;
                }
            });
        }

        $this->info("Flushed {$flushed} view(s).");

        return self::SUCCESS;
    }
}
