<?php

namespace App\Modules\Media\Console;

use App\Modules\Media\Actions\PurgeMedia;
use App\Modules\Media\Models\Media;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;

/**
 * Permanently deletes media that has sat in the Trash (soft-deleted) longer than the configured
 * retention (config('media.trash_retention_days'), default 30). Scheduled daily; the manual
 * "empty trash" / "delete permanently" picker actions purge immediately regardless of age.
 *
 * Runs per site inside Tenancy::enter so RLS + the model's global scope resolve each tenant's own
 * trash (a CLI has no site context otherwise), one transaction per page of CHUNK files: each page
 * takes its files off the library's storage totals as its last write and commits, so the totals'
 * rows are held only for that moment and never while a later page deletes its files (an upload of
 * the same website meanwhile counts itself without waiting). Purge goes through PurgeMedia:
 * per-instance so MediaObserver fires and drops each master's attachments + its single R2 object (a
 * mass forceDelete would skip those events and strand the R2 objects).
 */
class PurgeTrashedMedia extends Command
{
    #[\Override]
    protected $signature = 'media:purge-trash';

    #[\Override]
    protected $description = 'Permanently delete media left in the Trash longer than the retention window';

    /** Files loaded at a time per site. */
    public const int CHUNK = 200;

    public function handle(Tenancy $tenancy, PurgeMedia $purge): int
    {
        $configured = config('media.trash_retention_days', 30);
        $days = max(1, is_numeric($configured) ? (int) $configured : 30);
        $cutoff = now()->subDays($days);
        $purged = 0;

        foreach ($tenancy->siteIds() as $siteId) {
            // in pages by id, so a site with a large stale Trash never loads it whole
            $after = 0;
            do {
                [$gone, $after, $full] = $tenancy->enter($siteId, function () use ($cutoff, $purge, $after): array {
                    $stale = Media::onlyTrashed()->where('deleted_at', '<', $cutoff)->where('id', '>', $after)
                        ->orderBy('id')->limit(self::CHUNK)->get();

                    return [$purge->handle($stale), Cast::int($stale->last()?->id, $after), $stale->count() === self::CHUNK];
                });
                $purged += $gone;
            } while ($full);
        }

        $this->info("Purged {$purged} trashed media older than {$days} day(s).");

        return self::SUCCESS;
    }
}
