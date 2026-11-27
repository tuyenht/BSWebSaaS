<?php

namespace App\Modules\Media\Console;

use App\Modules\Media\Actions\PurgeMedia;
use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Permanently deletes media that has sat in the Trash (soft-deleted) longer than the configured
 * retention (config('media.trash_retention_days'), default 30). Scheduled daily; the manual
 * "empty trash" / "delete permanently" picker actions purge immediately regardless of age.
 *
 * Runs per-site inside Tenancy::enter so RLS + the model's global scope resolve each tenant's own
 * trash (a CLI has no site context otherwise). Purge goes through PurgeMedia: per-instance so
 * MediaObserver fires and drops each master's attachments + its single R2 object (a mass forceDelete
 * would skip those events and strand the R2 objects), and the library's storage totals go down with it.
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

        $tenancy->eachSite(function () use ($cutoff, $purge, &$purged): void {
            // in pages by id, so a site with a large stale Trash never loads it whole
            Media::onlyTrashed()->where('deleted_at', '<', $cutoff)
                ->chunkById(self::CHUNK, function (Collection $stale) use ($purge, &$purged): void {
                    $purged += $purge->handle($stale);
                });
        });

        $this->info("Purged {$purged} trashed media older than {$days} day(s).");

        return self::SUCCESS;
    }
}
