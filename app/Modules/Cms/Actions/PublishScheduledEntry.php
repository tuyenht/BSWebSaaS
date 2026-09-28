<?php

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\WorkflowGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Support\EntryStatusAudit;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use Illuminate\Support\Facades\DB;

/**
 * Publishes a scheduled entry when its time arrives — the counterpart to PublishEntry's Tầng-1
 * flow, but driven by the `entries:publish-due` scheduler. There is NO actor gate here: the entry
 * was already scheduled by an authorized user, and the scheduler is the authority. The entry and
 * every one of its translations go `published`, and each locale's route is synced (nested paths +
 * 301 history via RouteSync). published_at is left as the scheduled slot. Runs inside a site's
 * tenant context (the command enters it).
 *
 * The scheduler is not an approver: an entry scheduled while tier-1 was in force whose section
 * has SINCE been put under a workflow stays where it is (the editor shows it at the flow's first
 * stage), rather than being pushed live behind the gate's back.
 */
final readonly class PublishScheduledEntry
{
    public function __construct(
        private RouteSync $routes,
        private EntryWorkflowResolver $workflows,
        private RecordEntryRevision $revisions,
    ) {}

    /**
     * @throws WorkflowGovernedException an active workflow governs this entry — only a transition may publish it
     */
    public function handle(Entry $entry): void
    {
        if ($this->workflows->forEntry($entry) !== null) {
            throw new WorkflowGovernedException;
        }

        // Atomic: if any locale's route sync conflicts, roll the whole publish back rather than
        // leave the entry `published` with some locales unrouted.
        $from = $entry->status;
        DB::transaction(function () use ($entry): void {
            $entry->update(['status' => 'published']);

            foreach ($entry->translations as $translation) {
                $translation->update(['status' => 'published']);
                $this->routes->sync($entry, $translation->locale, $translation->slug);
            }

            // The scheduler has no actor: created_by stays null.
            $this->revisions->forAllLocales($entry, RevisionKind::Publish, null);
        });

        EntryStatusAudit::record($entry, $from, 'published', 'scheduler');
    }
}
