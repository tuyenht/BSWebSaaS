<?php

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Actions\ApproveEntryForLater;
use App\Modules\Cms\Actions\PublishScheduledEntry;
use App\Modules\Cms\Actions\UnpublishExpiredEntry;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Exceptions\WorkflowGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Shared\Support\ConcurrencyError;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Throwable;

/**
 * Publishes entries whose scheduled slot has arrived (status=scheduled, published_at<=now). Runs
 * per-site inside Tenancy::enter so RLS + the model global scope resolve each tenant's own content
 * (a CLI has no site context otherwise) — the same shape as media:purge-trash. Scheduled every
 * minute so a page goes live within a minute of its slot.
 *
 * A lock conflict (deadlock, serialization failure, or a statement refused because one already
 * aborted the transaction — Shared\Support\ConcurrencyError) ends that site's batch: Postgres has
 * aborted the site's whole transaction by then, so nothing more can run in it. The site is rolled back as
 * one and its entries stay due, so the next minute's run publishes them; the sites after it are
 * swept as usual.
 *
 * A post approved for later under an approval workflow (entries.scheduled_by set) is not published
 * here directly: ApproveEntryForLater::publishDue applies the stage's publishing move through the
 * workflow engine as its approver, rights judged again. One it cannot publish (the approver lost the
 * right, the flow changed) goes back to waiting and is counted as held back; its author and approver
 * are told. So does one that stands at a stage of its flow with no approver on record (the approver's
 * account was deleted and scheduled_by went NULL): nobody approved what would go live. So does a plain
 * scheduled post whose section went under an approval flow after it was scheduled: back at the flow's
 * initial stage as a draft, its author told, counted once. One whose stage is itself a published one
 * (stale, never an approval) is skipped and counted. With the flow switched off since, either goes live
 * at its time like any scheduled post.
 *
 * A post whose publication would write an address other content already uses (its own, or a sub-page's
 * that moves with it: RouteConflictException, raised before anything is written and rolled back in the
 * publication's own savepoint) fails the same way every minute until someone changes an address. So it
 * leaves the due queue (ApproveEntryForLater::holdTakenAddress: a draft again, or back to waiting under
 * an approval flow), its author and whoever scheduled it are told once, and it is counted on its own
 * line. Any other failure is reported and the entry stays due for the next run.
 *
 * At very large tenant counts the all-sites sweep becomes the cost centre; a sites-with-due-content
 * index would replace the full loop then (measure-first, ADR-18).
 */
class PublishDueEntries extends Command
{
    #[\Override]
    protected $signature = 'entries:publish-due';

    #[\Override]
    protected $description = 'Publish scheduled entries whose publish time has arrived';

    public function handle(Tenancy $tenancy, PublishScheduledEntry $publisher, ApproveEntryForLater $approved): int
    {
        $published = 0;
        $skipped = 0;
        $failed = 0;
        $held = 0;
        $taken = 0;
        $expired = 0;
        $deferred = 0;

        foreach ($tenancy->siteIds() as $siteId) {
            try {
                // Counted per site and added only once the site commits: a site rolled back by a
                // lock conflict published nothing.
                [$sitePublished, $siteSkipped, $siteFailed, $siteHeld, $siteTaken, $siteExpired] = $tenancy->enter($siteId, fn (): array => $this->sweepSite($publisher, $approved));
            } catch (Throwable $e) {
                if (! ConcurrencyError::is($e)) {
                    throw $e;
                }

                report($e);
                $deferred++;

                continue;
            }

            $published += $sitePublished;
            $skipped += $siteSkipped;
            $failed += $siteFailed;
            $held += $siteHeld;
            $taken += $siteTaken;
            $expired += $siteExpired;
        }

        $this->info("Published {$published} scheduled entr(y/ies), skipped {$skipped} governed by an approval workflow, {$failed} failed, {$deferred} site(s) left for the next run after a lock conflict. {$held} approved entr(y/ies) held back and waiting for review again.");
        $this->info("{$taken} entr(y/ies) not published because an address they need is already in use: taken off the schedule, their author and scheduler told.");
        $this->info("{$expired} entr(y/ies) past their end time before they went live: archived, never published.");

        return self::SUCCESS;
    }

    /**
     * One site's due entries, inside its tenant transaction.
     *
     * @return array{int, int, int, int, int, int} published, skipped, failed, held back, address taken, past their end time
     */
    private function sweepSite(PublishScheduledEntry $publisher, ApproveEntryForLater $approved): array
    {
        $counts = ['published' => 0, 'skipped' => 0, 'failed' => 0, 'held' => 0, 'taken' => 0, 'expired' => 0];

        $due = Entry::query()
            ->where('status', 'scheduled')
            ->where('published_at', '<=', now())
            ->orderBy('id')
            ->get();

        foreach ($due as $entry) {
            try {
                $outcome = $this->sweepEntry($entry, $publisher, $approved);
                if ($outcome !== 'not_due') {
                    $counts[$outcome]++;
                }
            } catch (Throwable $e) {
                // Postgres has aborted the site's transaction: rethrow so it rolls back as one
                // (caught per site in handle()) instead of running more rows in it.
                if (ConcurrencyError::is($e)) {
                    throw $e;
                }

                // One entry must never take the sweep down with it. An escaping
                // exception would roll back the tenant transaction this loop runs in
                // (Tenancy::enter), un-publishing the entries that already went live,
                // and abort every site after this one. Reported, and left due: the
                // next run tries it again.
                report($e);
                $counts['failed']++;
            }
        }

        return [$counts['published'], $counts['skipped'], $counts['failed'], $counts['held'], $counts['taken'], $counts['expired']];
    }

    /** @return 'published'|'skipped'|'held'|'taken'|'expired'|'not_due' */
    private function sweepEntry(Entry $entry, PublishScheduledEntry $publisher, ApproveEntryForLater $approved): string
    {
        // its end time came before its publish run did: it never goes live, on either road
        if (UnpublishExpiredEntry::isPast($entry)) {
            return app(UnpublishExpiredEntry::class)->archiveScheduled($entry) ? 'expired' : 'not_due';
        }

        try {
            if ($entry->scheduled_by === null && $entry->workflow_state === null) {
                try {
                    // False: an editor trashed, unscheduled or rescheduled it after the read above.
                    return $publisher->handle($entry) ? 'published' : 'not_due';
                } catch (WorkflowGovernedException) {
                    // Scheduled before its section went under an approval flow: nobody approved it,
                    // so it is held back below as an approval with no approver is, never put live.
                }
            }

            // Approved for later under a workflow (or at a stage of one, its approver gone), or governed
            // since it was scheduled: only the engine puts it live, as its approver; with the flow
            // switched off since, as any scheduled post.
            return $approved->publishDue($entry);
        } catch (RouteConflictException $conflict) {
            // An address it needs is taken: permanent until someone edits one, so out of the queue, told once.
            return $approved->holdTakenAddress($entry, $conflict) ? 'taken' : 'not_due';
        }
    }
}
