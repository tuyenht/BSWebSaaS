<?php

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Actions\PublishScheduledEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Console\Command;

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

        foreach (Site::query()->get() as $site) {
            $siteId = $site->getKey();
            if (! is_int($siteId)) {
                continue;
            }
            $tenancy->enter($siteId, function () use ($publisher, &$published): void {
                $due = Entry::query()
                    ->where('status', 'scheduled')
                    ->where('published_at', '<=', now())
                    ->get();

                foreach ($due as $entry) {
                    $publisher->handle($entry);
                    $published++;
                }
            });
        }

        $this->info("Published {$published} scheduled entr(y/ies).");

        return self::SUCCESS;
    }
}
