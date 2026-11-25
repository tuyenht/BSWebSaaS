<?php

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\ExpiredContentException;
use App\Modules\Cms\Exceptions\WorkflowGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Support\CategoryPublicCounts;
use App\Modules\Cms\Support\EntryNotifier;
use App\Modules\Cms\Support\EntryStatusAudit;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Workflow Tầng-1 (design.md §15.4 — permission-driven, no configuration). An actor
 * carrying entries.publish who may also edit this entry (EntryPolicy::publish) publishes
 * straight through: entry + translation go `published`, published_at is stamped, and the
 * route is synced (with a 301 on a slug change). Anyone else who may send this entry
 * (EntryPolicy::submit: entries.submit plus the right to edit it) parks a DRAFT as `pending`
 * for a publisher to review. Only published entries with a published translation reach the
 * public (RouteResolver).
 *
 * Tầng-1 remains the behaviour for every site that has not switched a workflow on — which is
 * all of them by default. Where one IS active, this straight-through path would be a hole
 * around the approval gate it exists to enforce, so it refuses and sends the caller to
 * ApplyEntryWorkflowTransition instead.
 */
final readonly class PublishEntry
{
    public function __construct(
        private RouteSync $routes,
        private EntryWorkflowResolver $workflows,
        private EntryNotifier $notifier,
        private RecordEntryRevision $revisions,
        private CategoryPublicCounts $publicCounts,
    ) {}

    /**
     * @return string the entry's lifecycle status afterwards: 'published' after a publish (also
     *                when it was live already — the publish runs again), 'pending' when a draft
     *                was sent, and for a submitter's call on anything but a draft the status it
     *                left unchanged. The status alone cannot say whether THIS call changed
     *                anything (someone else may have published the entry meanwhile) — a caller
     *                that counts changes uses attempt()
     *
     * @throws AuthorizationException the actor may neither publish nor send this entry
     * @throws WorkflowGovernedException a workflow governs this entry — move it via a transition
     */
    public function handle(Entry $entry, string $locale, User $actor): string
    {
        return $this->attempt($entry, $locale, $actor)['status'];
    }

    /**
     * handle(), plus whether this call moved the entry: `moved` is true only when the entry's
     * status or the locale's translation status was different before, judged on the row as it is
     * under the lock — so a row that someone else published a moment ago is not counted as this
     * person's publish.
     *
     * @return array{status: string, moved: bool}
     *
     * @throws AuthorizationException the actor may neither publish nor send this entry
     * @throws WorkflowGovernedException a workflow governs this entry — move it via a transition
     */
    public function attempt(Entry $entry, string $locale, User $actor): array
    {
        return DB::transaction(fn (): array => $this->handleLocked($entry, $locale, $actor));
    }

    /** @return array{status: string, moved: bool} */
    private function handleLocked(Entry $entry, string $locale, User $actor): array
    {
        // Take the row lock FIRST, then read, like the workflow actions: the status and Trash
        // checks below must judge the row as it is now, not as the screen loaded it. Otherwise
        // a send racing a publish could take the live page back to pending, and a publish
        // racing a delete could put a trashed entry on the website.
        Entry::withTrashed()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
        $entry->refresh();

        $translation = $entry->translationFor($locale)
            ?? throw new RuntimeException("Entry {$entry->id} has no '{$locale}' translation to publish.");

        // Trashed content stays off the website until it is restored: no caller (row menu, bulk
        // bar, editor) may publish it or send it for review from the Trash.
        if ($entry->trashed()) {
            throw new AuthorizationException(__('entries.err_publish_forbidden'));
        }

        // Tier-2 wins where it is active: publishing must not skip the stages a site configured.
        if ($this->workflows->forEntry($entry) !== null) {
            throw new WorkflowGovernedException;
        }

        $gate = Gate::forUser($actor);
        $from = $entry->status;

        if ($gate->allows('publish', $entry)) {
            // past its end time it would be taken down again at once: the end time is moved or cleared first
            ExpiredContentException::guard($entry->expires_at);
            $moved = $from !== 'published' || $translation->status !== 'published';

            // Atomic: a RouteConflictException from sync must roll back the status flip, or the
            // entry is left `published` with no reachable route — a silent 404. A savepoint, so
            // the caller can catch that and carry on (the bulk bar does).
            // The categories' public totals follow the flip of the entry and its translation, as one
            // change (CategoryPublicCounts), in the same savepoint.
            DB::transaction(fn () => $this->publicCounts->track('entry_taxonomies', [$entry->id], function () use ($entry, $translation, $locale, $actor, $from): void {
                $languageWasLive = $translation->status === 'published';
                $entry->update([
                    'status' => 'published',
                    'published_at' => $entry->published_at ?? now(),
                    // a post approved for later under a flow since switched off: it is live now, and the
                    // stage it was approved at goes too, so a flow switched back on reads it at its
                    // published stage, not as waiting for review
                    'scheduled_by' => null,
                    'workflow_state' => null,
                ]);
                $translation->update(['status' => 'published']);

                $this->routes->sync($entry, $locale, $translation->slug);
                // a page's sub-pages follow the address it went live at (left behind while it was offline);
                // a page already live: only a language going live now
                if ($from !== 'published') {
                    $this->routes->syncSubPagesOf($entry);
                } elseif (! $languageWasLive) {
                    $this->routes->syncSubPagesOf($entry, $locale);
                }

                // What just went public is kept, in the same transaction as the flip.
                $this->revisions->forAllLocales($entry, RevisionKind::Publish, $actor);
            }));

            EntryStatusAudit::record($entry, $from, 'published', 'publish', $actor);

            if ($from !== 'published') {
                $this->notifier->published($entry, $locale, $actor);   // tells the author, unless they published it
            }

            return ['status' => 'published', 'moved' => $moved];
        }

        if ($gate->allows('submit', $entry)) {
            // Only a draft is sent. Anything else is left exactly as it is: a live page must not
            // be taken offline by a re-send, a scheduled one must not lose the go-live a
            // publisher set, and an entry already waiting is not sent (or announced) twice.
            if ($from !== 'draft') {
                return ['status' => $from, 'moved' => false];
            }

            $this->publicCounts->track('entry_taxonomies', [$entry->id], function () use ($entry, $translation): void {
                $entry->update(['status' => 'pending']);
                $translation->update(['status' => 'pending']);
            });

            EntryStatusAudit::record($entry, $from, 'pending', 'submit', $actor);

            $this->notifier->awaitingPublisher($entry, $locale, $actor);   // the publishers who may edit it

            return ['status' => 'pending', 'moved' => true];
        }

        throw new AuthorizationException(__('entries.err_publish_forbidden'));
    }
}
