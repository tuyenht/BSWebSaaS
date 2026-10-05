<?php

declare(strict_types=1);

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Shared\Support\ConcurrencyError;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Retention for revision history (config cms.revisions): per (entry, language) the newest `keep`
 * revisions always stay; an older one goes only once it is ALSO older than `days` days. The newest
 * revision is never removed (`keep` is at least 1). Scheduled daily (routes/console.php).
 *
 * Site by site inside each site's tenant context; ranked deletes walking the (site_id, entry_id,
 * locale, created_at DESC, id DESC) index in its own order, --batch rows per statement until none is
 * left. Each batch runs in its own savepoint; a failure is reported and the sweep moves on to the
 * next site (batches already removed on that site stay removed). A concurrency error
 * (Shared\Support\ConcurrencyError) is the exception: it leaves the site's transaction aborted, so
 * its batches would be rolled back at COMMIT while counted as removed — it stops the run instead,
 * and the next daily run picks up where it left off.
 */
final class PruneEntryRevisions extends Command
{
    #[\Override]
    protected $signature = 'cms:prune-revisions
        {--keep= : Newest revisions always kept per entry and language (default: cms.revisions.keep)}
        {--days= : Only revisions older than this many days may go (default: cms.revisions.days)}
        {--batch=5000 : Rows removed per statement}';

    #[\Override]
    protected $description = 'Remove revisions beyond the newest N per entry and language once they are older than the retention period, on every site.';

    public function handle(Tenancy $tenancy): int
    {
        $keep = max(1, $this->setting('keep', 20));
        $days = max(1, $this->setting('days', 90));
        $cutoff = now()->subDays($days);
        $batch = max(1, is_numeric($this->option('batch')) ? (int) $this->option('batch') : 5000);
        $deleted = 0;
        $failed = 0;

        $sites = $tenancy->eachSite(function (int $siteId) use ($keep, $cutoff, $batch, &$deleted, &$failed): void {
            $ranked = DB::table('entry_revisions')
                ->select(['id', 'created_at'])
                ->selectRaw('row_number() over (partition by entry_id, locale order by created_at desc, id desc) as recency')
                ->where('site_id', $siteId);

            $expired = DB::query()
                ->fromSub($ranked, 'ranked')
                ->select('id')
                ->where('recency', '>', $keep)
                ->where('created_at', '<', $cutoff)
                ->limit($batch);

            // Bounded batches until none is left. Deleting rows ranked past `keep` never changes
            // the rank of the newest `keep`, so re-ranking each round keeps the same rule.
            try {
                do {
                    $removed = DB::transaction(fn (): mixed => EntryRevision::query()->whereIn('id', $expired)->delete());
                    $removed = is_int($removed) ? $removed : 0;
                    $deleted += $removed;
                } while ($removed > 0);
            } catch (Throwable $e) {
                if (ConcurrencyError::is($e)) {
                    throw $e;   // the site's transaction is aborted: nothing more can run in it
                }
                report($e);
                $failed++;
            }
        });

        $this->info("Removed {$deleted} revision(s) beyond the newest {$keep} and older than {$days} days on {$sites} site(s), {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** An option when given, else the config value, else the default. */
    private function setting(string $key, int $default): int
    {
        $value = $this->option($key) ?? config("cms.revisions.{$key}");

        return is_numeric($value) ? (int) $value : $default;
    }
}
