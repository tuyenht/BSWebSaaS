<?php

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\WorkflowGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Routing\RouteSync;
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
 * carrying entries.publish publishes straight through: entry + translation go
 * `published`, published_at is stamped, and the route is synced (with a 301 on a slug
 * change). Anyone else who may send this entry (EntryPolicy::submit: entries.submit plus the
 * right to edit it) parks a DRAFT as `pending` for a publisher to review. Only published
 * entries with a published translation reach the public (RouteResolver).
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
    ) {}

    /**
     * @return string the entry's lifecycle status afterwards: 'published' or 'pending' when this
     *                call moved it; a submitter's call on anything but a draft returns the status
     *                it left unchanged, so a caller can tell "sent" from "not sent"
     *
     * @throws AuthorizationException the actor may neither publish nor send this entry
     * @throws WorkflowGovernedException a workflow governs this entry — move it via a transition
     */
    public function handle(Entry $entry, string $locale, User $actor): string
    {
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
            // Atomic: a RouteConflictException from sync must roll back the status flip, or the
            // entry is left `published` with no reachable route — a silent 404.
            DB::transaction(function () use ($entry, $translation, $locale, $actor): void {
                $entry->update([
                    'status' => 'published',
                    'published_at' => $entry->published_at ?? now(),
                ]);
                $translation->update(['status' => 'published']);

                $this->routes->sync($entry, $locale, $translation->slug);

                // What just went public is kept, in the same transaction as the flip.
                $this->revisions->forAllLocales($entry, RevisionKind::Publish, $actor);
            });

            EntryStatusAudit::record($entry, $from, 'published', 'publish', $actor);

            if ($from !== 'published') {
                $this->notifier->published($entry, $locale, $actor);   // tells the author, unless they published it
            }

            return 'published';
        }

        if ($gate->allows('submit', $entry)) {
            // Only a draft is sent. Anything else is left exactly as it is: a live page must not
            // be taken offline by a re-send, a scheduled one must not lose the go-live a
            // publisher set, and an entry already waiting is not sent (or announced) twice.
            if ($from !== 'draft') {
                return $from;
            }

            $entry->update(['status' => 'pending']);
            $translation->update(['status' => 'pending']);

            EntryStatusAudit::record($entry, $from, 'pending', 'submit', $actor);

            $this->notifier->awaitingReview($entry, $locale, $actor, 'entries.publish');   // the publishers

            return 'pending';
        }

        throw new AuthorizationException(__('entries.err_publish_forbidden'));
    }
}
