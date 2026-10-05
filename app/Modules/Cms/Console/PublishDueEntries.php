<?php

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Actions\PublishScheduledEntry;
use App\Modules\Cms\Exceptions\WorkflowGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Throwable;

/**
 * Publishes entries whose scheduled slot has arrived (status=scheduled, published_at<=now). Runs
 * per-site inside Tenancy::enter so RLS + the model global scope resolve each tenant's own content
 * (a CLI has no site context otherwise) — the same shape as media:purge-trash. Scheduled every
 * minute so a page goes live within a minute of its slot.
 *
 * A lock conflict (deadlock, serialization failure) ends that site's batch: Postgres has aborted
 * the site's whole transaction by then, so nothing more can run in it. The site is rolled back as
 * one and its entries stay due, so the next minute's run publishes them; the sites after it are
 * swept as usual.
 *
 * At very large tenant counts the all-sites sweep becomes the cost centre; a sites-with-due-content
 * index would replace the full loop then (measure-first, ADR-18).
 */
class PublishDueEntries extends Command
{
    use DetectsConcurrencyErrors;

    #[\Override]
    protected $signature = 'entries:publish-due';

    #[\Override]
    protected $description = 'Publish scheduled entries whose publish time has arrived';

    public function handle(Tenancy $tenancy, PublishScheduledEntry $publisher): int
    {
        $published = 0;
        $skipped = 0;
        $failed = 0;
        $deferred = 0;

        foreach ($tenancy->siteIds() as $siteId) {
            try {
                // Counted per site and added only once the site commits: a site rolled back by a
                // lock conflict published nothing.
                [$sitePublished, $siteSkipped, $siteFailed] = $tenancy->enter($siteId, fn (): array => $this->sweepSite($publisher));
            } catch (Throwable $e) {
                if (! $this->isLockConflict($e)) {
                    throw $e;
                }

                report($e);
                $deferred++;

                continue;
            }

            $published += $sitePublished;
            $skipped += $siteSkipped;
            $failed += $siteFailed;
        }

        $this->info("Published {$published} scheduled entr(y/ies), skipped {$skipped} governed by an approval workflow, {$failed} failed, {$deferred} site(s) left for the next run after a lock conflict.");

        return self::SUCCESS;
    }

    /**
     * One site's due entries, inside its tenant transaction.
     *
     * @return array{int, int, int} published, skipped, failed
     */
    private function sweepSite(PublishScheduledEntry $publisher): array
    {
        $published = 0;
        $skipped = 0;
        $failed = 0;

        $due = Entry::query()
            ->where('status', 'scheduled')
            ->where('published_at', '<=', now())
            ->orderBy('id')
            ->get();

        foreach ($due as $entry) {
            try {
                // False: an editor trashed, unscheduled or rescheduled it after the read above.
                if ($publisher->handle($entry)) {
                    $published++;
                }
            } catch (WorkflowGovernedException) {
                // Scheduled before its section went under an approval flow: only a
                // transition may publish it now, so it stays put (visible in the editor
                // at the flow's first stage) instead of going live unreviewed.
                $skipped++;
            } catch (Throwable $e) {
                // Postgres has aborted the site's transaction: rethrow so it rolls back as one
                // (caught per site in handle()) instead of running more rows in it.
                if ($this->isLockConflict($e)) {
                    throw $e;
                }

                // One entry must never take the sweep down with it. An escaping
                // exception would roll back the tenant transaction this loop runs in
                // (Tenancy::enter), un-publishing the entries that already went live,
                // and abort every site after this one — every minute, forever, because
                // the offending entry stays due. A slug that now collides with a live
                // page is exactly that: permanent until someone edits it.
                report($e);
                $failed++;
            }
        }

        return [$published, $skipped, $failed];
    }

    /**
     * A deadlock or serialization failure. Laravel rethrows one raised inside a nested transaction
     * as DeadlockException without rolling back to the savepoint, and that wrapper loses the
     * SQLSTATE the detector reads, so it is matched by class as well.
     */
    private function isLockConflict(Throwable $e): bool
    {
        return $e instanceof DeadlockException || $this->causedByConcurrencyError($e);
    }
}
