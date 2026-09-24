<?php

namespace App\Modules\Media\Console;

use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;

/**
 * Permanently deletes media that has sat in the Trash (soft-deleted) longer than the configured
 * retention (config('media.trash_retention_days'), default 15). Scheduled daily; the manual
 * "empty trash" / "delete permanently" picker actions purge immediately regardless of age.
 *
 * Runs per-site inside Tenancy::enter so RLS + the model's global scope resolve each tenant's own
 * trash (a CLI has no site context otherwise). Purge is per-instance so MediaObserver fires and
 * drops each master's attachments + its single R2 object — a mass forceDelete would skip those
 * events and strand the R2 objects.
 */
class PurgeTrashedMedia extends Command
{
    #[\Override]
    protected $signature = 'media:purge-trash';

    #[\Override]
    protected $description = 'Permanently delete media left in the Trash longer than the retention window';

    public function handle(Tenancy $tenancy): int
    {
        $configured = config('media.trash_retention_days', 15);
        $days = max(1, is_numeric($configured) ? (int) $configured : 15);
        $cutoff = now()->subDays($days);
        $purged = 0;

        $tenancy->eachSite(function () use ($cutoff, &$purged): void {
            $stale = Media::onlyTrashed()->where('deleted_at', '<', $cutoff)->get();
            $stale->each->forceDelete(); // per-instance → MediaObserver drops attachments + R2 object
            $purged += $stale->count();
        });

        $this->info("Purged {$purged} trashed media older than {$days} day(s).");

        return self::SUCCESS;
    }
}
