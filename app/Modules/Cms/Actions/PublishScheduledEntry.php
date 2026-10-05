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
 * The sweep reads the due rows before it publishes them one by one, and an editor can still trash
 * a post, set it back to draft or move its slot in between. So the row is locked and re-read first,
 * like PublishEntry and the workflow actions, and only a row that is STILL scheduled, out of the
 * Trash and due goes live; anything else is left exactly as the editor put it.
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
     * @return bool true when this call put the entry live; false when the row was no longer due
     *              (trashed, gone, no longer scheduled, or its slot moved into the future) and
     *              nothing was written
     *
     * @throws WorkflowGovernedException an active workflow governs this entry — only a transition may publish it
     */
    public function handle(Entry $entry): bool
    {
        // Atomic: if any locale's route sync conflicts, roll the whole publish back rather than
        // leave the entry `published` with some locales unrouted.
        if (! DB::transaction(fn (): bool => $this->publishLocked($entry))) {
            return false;
        }

        // Only a scheduled entry is ever published here (checked under the lock).
        EntryStatusAudit::record($entry, 'scheduled', 'published', 'scheduler');

        return true;
    }

    /** False when the entry was no longer due and nothing changed. */
    private function publishLocked(Entry $entry): bool
    {
        // Lock FIRST, then read: every check below judges the row as it is now, not as the sweep
        // read it. refresh() also reloads any relation already loaded (translations, categories),
        // and one not loaded yet is read after this point, so the slugs and section are current.
        $locked = Entry::withTrashed()->whereKey($entry->id)->lockForUpdate()->first();

        if ($locked === null) {
            return false;   // purged from the Trash in the meantime
        }

        $entry->refresh();

        $due = $entry->status === 'scheduled'
            && ! $entry->trashed()
            && $entry->published_at !== null
            && $entry->published_at <= now();

        if (! $due) {
            return false;
        }

        // Asked of the locked row: the post may have been re-filed into a governed section since.
        if ($this->workflows->forEntry($entry) !== null) {
            throw new WorkflowGovernedException;
        }

        $entry->update(['status' => 'published']);

        foreach ($entry->translations as $translation) {
            $translation->update(['status' => 'published']);
            $this->routes->sync($entry, $translation->locale, $translation->slug);
        }

        // The scheduler has no actor: created_by stays null.
        $this->revisions->forAllLocales($entry, RevisionKind::Publish, null);

        return true;
    }
}
