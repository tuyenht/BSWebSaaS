<?php

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Actions\PublishScheduledEntry;
use App\Modules\Cms\Exceptions\WorkflowGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Console\Command;
use Throwable;

/**
 * Publishes entries whose scheduled slot has arrived (status=scheduled, published_at<=now). Runs
 * per-site inside Tenancy::enter so RLS + the model global scope resolve each tenant's own content
 * (a CLI has no site context otherwise) — the same shape as media:purge-trash. Scheduled every
 * minute so a page goes live within a minute of its slot.
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

    public function handle(Tenancy $tenancy, PublishScheduledEntry $publisher): int
    {
        $published = 0;
        $skipped = 0;
        $failed = 0;

        foreach (Site::query()->get() as $site) {
            $siteId = $site->getKey();
            if (! is_int($siteId)) {
                continue;
            }
            $tenancy->enter($siteId, function () use ($publisher, &$published, &$skipped, &$failed): void {
                $due = Entry::query()
                    ->where('status', 'scheduled')
                    ->where('published_at', '<=', now())
                    ->get();

                foreach ($due as $entry) {
                    try {
                        $publisher->handle($entry);
                        $published++;
                    } catch (WorkflowGovernedException) {
                        // Scheduled before its section went under an approval flow: only a
                        // transition may publish it now, so it stays put (visible in the editor
                        // at the flow's first stage) instead of going live unreviewed.
                        $skipped++;
                    } catch (Throwable $e) {
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
            });
        }

        $this->info("Published {$published} scheduled entr(y/ies), skipped {$skipped} governed by an approval workflow, {$failed} failed.");

        return self::SUCCESS;
    }
}
