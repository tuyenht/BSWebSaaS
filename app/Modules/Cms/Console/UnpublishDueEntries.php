<?php

declare(strict_types=1);

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Actions\UnpublishExpiredEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Shared\Support\ConcurrencyError;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Throwable;

/**
 * Takes off the website the live posts and pages whose end time («Gỡ khỏi website lúc», expires_at)
 * has come, through UnpublishExpiredEntry. The counterpart of entries:publish-due, in the same shape:
 * per site inside Tenancy::enter (RLS and the model scope pick each site's own rows), every minute, so
 * an item leaves within a minute of its time (its detail page already answers 404 from that moment,
 * RouteResolver). The due rows are found by a partial index on (site_id, expires_at).
 *
 * One entry that fails is reported and stays due for the next run; a lock conflict ends that site's
 * batch (Postgres has aborted its transaction) and the site is swept again the next minute.
 */
final class UnpublishDueEntries extends Command
{
    #[\Override]
    protected $signature = 'entries:unpublish-due';

    #[\Override]
    protected $description = 'Take off the website the posts and pages whose end time has come';

    public function handle(Tenancy $tenancy, UnpublishExpiredEntry $unpublisher): int
    {
        $counts = [UnpublishExpiredEntry::UNPUBLISHED => 0, UnpublishExpiredEntry::FORCED => 0, UnpublishExpiredEntry::HELD => 0, 'failed' => 0];
        $deferred = 0;

        foreach ($tenancy->siteIds() as $siteId) {
            try {
                // added only once the site commits: a site rolled back by a lock conflict took nothing down
                $site = $tenancy->enter($siteId, fn (): array => $this->sweepSite($unpublisher));
            } catch (Throwable $e) {
                if (! ConcurrencyError::is($e)) {
                    throw $e;
                }

                report($e);
                $deferred++;

                continue;
            }

            foreach ($site as $outcome => $n) {
                $counts[$outcome] += $n;
            }
        }

        $this->info("Took {$counts[UnpublishExpiredEntry::UNPUBLISHED]} entr(y/ies) off the website at their end time, {$counts[UnpublishExpiredEntry::FORCED]} more forced off under an approval workflow with no move for it, {$counts[UnpublishExpiredEntry::HELD]} held (the Home page), {$counts['failed']} failed, {$deferred} site(s) left for the next run after a lock conflict.");

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function sweepSite(UnpublishExpiredEntry $unpublisher): array
    {
        $counts = [UnpublishExpiredEntry::UNPUBLISHED => 0, UnpublishExpiredEntry::FORCED => 0, UnpublishExpiredEntry::HELD => 0, 'failed' => 0];

        $due = Entry::query()
            ->where('status', 'published')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->get();

        foreach ($due as $entry) {
            try {
                $outcome = $unpublisher->handle($entry);
                if ($outcome !== UnpublishExpiredEntry::NOT_DUE) {
                    $counts[$outcome]++;
                }
            } catch (Throwable $e) {
                // the site's transaction is aborted: roll it back as one (caught per site above)
                if (ConcurrencyError::is($e)) {
                    throw $e;
                }

                // one entry never takes the sweep down with it, nor undoes the ones already taken down
                report($e);
                $counts['failed']++;
            }
        }

        return $counts;
    }
}
