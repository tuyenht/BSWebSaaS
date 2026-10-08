<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\EntryDraftRejectedException;
use App\Modules\Cms\Exceptions\EntryNotGovernedException;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Support\EntryDraftApplier;
use App\Modules\Cms\Support\EntryDraftAudit;
use App\Modules\Cms\Support\EntryNotifier;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Exceptions\InvalidTransitionException;
use App\Modules\Workflow\Models\WorkflowState;
use App\Modules\Workflow\Services\WorkflowEngine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Moves a live entry's pending change through the entry's own approval workflow (design.md §8,
 * §15.4): the same stages and the same permissions as the entry, with the trail row marked as a
 * change so it never reads as the post leaving the website. The engine decides whether the move
 * is legal; this action decides what it means for the change.
 *
 * The entry stays live and at its stage throughout. Only a move onto a stage that publishes
 * applies the change: its content is written onto the entry through the editor's checks (a new
 * address moves the route and leaves a 301), a `publish` revision is recorded in the approver's
 * name, the change is deleted and an audit line says so — all in the transaction that holds the
 * trail row, so an address clash rolls everything back and the change stays as it was.
 *
 * Every move carries the change's version as the screen showed it: a change returned, edited and
 * sent again lands on the same stage, and a reviewer's older tab must not approve content they
 * never read.
 */
final readonly class ApplyEntryDraftTransition
{
    public function __construct(
        private EntryWorkflowResolver $workflows,
        private WorkflowEngine $engine,
        private EntryDraftApplier $applier,
        private RecordEntryRevision $revisions,
        private EntryNotifier $notifier,
    ) {}

    /**
     * Move the change; returns the destination stage key.
     *
     * @param  string  $locale  the language the move was made from (trail row and notices)
     * @param  string  $seenVersion  EntryDraft::version() of the change the actor was shown
     *
     * @throws EntryNotGovernedException no workflow governs this entry (apply with ApplyEntryDraft)
     * @throws EntryDraftRejectedException not_live · change_stale · content_invalid · category_governed · slug_taken
     * @throws InvalidTransitionException unknown move, wrong origin, or a missing required note
     * @throws AuthorizationException the actor lacks the move's permission, sends a change to an entry they may not edit, or the entry or the change's category lies outside their category limits
     */
    public function handle(
        Entry $entry,
        string $locale,
        User $actor,
        string $transitionKey,
        string $seenVersion,
        ?string $note = null,
        ?int $assignTo = null,
    ): string {
        try {
            /** @var array{0: WorkflowState, 1: EntryDraft} $moved */
            $moved = DB::transaction(function () use ($entry, $locale, $actor, $transitionKey, $seenVersion, $note, $assignTo): array {
                // Lock the entry FIRST, then read the change: two reviewers acting on the same
                // change queue here, and the second one reads the first one's result.
                Entry::withTrashed()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
                $entry->refresh();

                // Read under the lock, like the change: the workflow is the one governing now.
                $workflow = $this->workflows->forEntry($entry)
                    ?? throw EntryNotGovernedException::for($entry->id);

                if ($entry->status !== 'published' || $entry->trashed()) {
                    throw new EntryDraftRejectedException('not_live');
                }

                $change = EntryDraft::query()->where('entry_id', $entry->id)->first();
                if ($change === null || ! hash_equals($change->version(), $seenVersion)) {
                    throw new EntryDraftRejectedException('change_stale');
                }

                // Sending a change on is working on its entry: the same per-entry rule as the entry's own moves.
                $this->workflows->authorizeMove($workflow, $transitionKey, $entry, $actor);
                // A change may re-file the post: whoever moves it on must be able to write there too.
                $this->workflows->authorizeChangeTarget($change, $entry, $actor);

                $destination = $this->engine->apply(
                    $workflow, $transitionKey, $this->workflows->changeOriginFor($workflow, $change), $actor,
                    $entry->id, $locale, $note, $assignTo, WorkflowEngine::TARGET_CHANGE,
                );

                if ($destination->is_published) {
                    $this->applyLive($entry, $change, $actor, $destination);
                } else {
                    $change->update([
                        'workflow_state' => $destination->key,
                        'assigned_to' => $assignTo ?? $change->assigned_to,
                    ]);
                }

                return [$destination, $change];
            });
        } catch (RouteConflictException) {
            throw new EntryDraftRejectedException('slug_taken');
        }

        [$destination, $change] = $moved;
        $this->notify($entry, $locale, $actor, $destination, $change, $note, $assignTo);

        return $destination->key;
    }

    /** Write the change onto the website, keep what went live, and close the change. */
    private function applyLive(Entry $entry, EntryDraft $change, User $actor, WorkflowState $destination): void
    {
        $applied = $this->applier->apply($entry, $change, actor: $actor);

        // What visitors now see is kept in the approver's name: every language when a shared
        // field changed (it is part of every language's page), otherwise the languages changed.
        if ($applied['all_locales']) {
            $this->revisions->forAllLocales($entry, RevisionKind::Publish, $actor);
        } else {
            foreach ($applied['translations'] as $translation) {
                $this->revisions->forLocale($entry, $translation, RevisionKind::Publish, $actor);
            }
        }

        $change->delete();

        EntryDraftAudit::record(EntryDraftAudit::APPLIED, $entry, $actor, $applied['locales'], $destination->key, [
            'via' => 'workflow',
            'dropped_media' => $applied['dropped_media'],
        ]);
    }

    /**
     * The same people hear of a change as of the entry: an assignee that the work is theirs; the
     * change's author that it went live or came back with the note; otherwise the reviewers.
     */
    private function notify(Entry $entry, string $locale, User $actor, WorkflowState $destination, EntryDraft $change, ?string $note, ?int $assignTo): void
    {
        if ($assignTo !== null) {
            $this->notifier->assigned($entry, $locale, $actor, $assignTo, $change);
        }

        if ($destination->is_published) {
            $this->notifier->published($entry, $locale, $actor, $change);
        } elseif ($destination->is_initial) {
            $this->notifier->returned($entry, $locale, $actor, $note, $change);
        } elseif ($assignTo === null) {
            $this->notifier->awaitingReview($entry, $locale, $actor, 'entries.review', $change);
        }
    }
}
