<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Exceptions\EntryNotGovernedException;
use App\Modules\Cms\Exceptions\SchedulePastException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Support\EntryNotifier;
use App\Modules\Cms\Support\EntryStatusAudit;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Workflow\Exceptions\InvalidTransitionException;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowState;
use App\Modules\Workflow\Models\WorkflowTransition;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * «Khi nào đăng?» → «Hẹn giờ đăng» when a post under an approval workflow is approved (review inbox):
 * the decision is taken now, the post goes live later — and only the workflow engine ever puts it live.
 *
 * Approving for later does NOT move the post's stage and writes no workflow_log row: the post stays at
 * the stage it was approved from, with status `scheduled`, its time in published_at and the approver in
 * scheduled_by. When the time comes, entries:publish-due calls publishDue(): the stage's publishing move
 * is applied through ApplyEntryWorkflowTransition AS the approver — the engine's own checks, the
 * approver's rights and category limits judged again at that moment. If the approver may no longer
 * publish it, or the flow changed so that no such move exists from where it stands, the post is not
 * published: it goes back to waiting at its stage (status `pending`, back in the review inbox), and its
 * author and approver are told why. «Hủy hẹn giờ» (cancel) does the same on request, without moving
 * the stage either.
 *
 * Every write takes the entry row lock first and judges the row as it is then, like every other entry
 * action. Runs inside the caller's tenant context.
 */
final readonly class ApproveEntryForLater
{
    public const string HELD_RIGHT = 'right';

    public const string HELD_FLOW = 'flow';

    public function __construct(
        private EntryWorkflowResolver $workflows,
        private ApplyEntryWorkflowTransition $transitions,
        private EntryNotifier $notifier,
    ) {}

    /**
     * Approve $entry now, to go live at $publishAt, through the publishing move $transitionKey from the
     * stage it stands at. Refused exactly where the engine would refuse that move now.
     *
     * @throws SchedulePastException the time is missing or not after now (judged on the locked row)
     * @throws EntryNotGovernedException no workflow governs the post (the caller should use tier-1)
     * @throws InvalidTransitionException not a publishing move from where the post stands, or it is already live or scheduled
     * @throws AuthorizationException the actor may not make that move on this post, or it is in the Trash
     */
    public function approve(Entry $entry, string $locale, User $actor, string $transitionKey, DateTimeInterface $publishAt): void
    {
        DB::transaction(function () use ($entry, $locale, $actor, $transitionKey, $publishAt): void {
            Entry::withTrashed()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $entry->refresh();

            if ($entry->trashed()) {
                throw new AuthorizationException(__('entries.err_publish_forbidden'));
            }
            if ($publishAt <= now()) {
                throw new SchedulePastException;
            }
            if (! $entry->translationFor($locale) instanceof EntryTranslation) {
                throw InvalidTransitionException::notDefined($transitionKey);
            }

            $workflow = $this->workflows->forEntry($entry) ?? throw EntryNotGovernedException::for($entry->id);
            // Only a first publication is scheduled (board 17: a live post, or one already approved for later, is not).
            if (in_array($entry->status, [EntryStatus::Published->value, EntryStatus::Scheduled->value], true)) {
                throw InvalidTransitionException::wrongOrigin($transitionKey, $entry->status);
            }

            $origin = $this->workflows->originFor($workflow, $entry);
            $transition = $workflow->transitions()->where('key', $transitionKey)->first();
            if (! $transition instanceof WorkflowTransition) {
                throw InvalidTransitionException::notDefined($transitionKey);
            }
            if ($transition->from_state !== $origin) {
                throw InvalidTransitionException::wrongOrigin($transitionKey, $origin);
            }
            if (! $this->isPublishing($workflow, $transition)) {
                throw InvalidTransitionException::notDefined($transitionKey);
            }
            // a move that asks for a reason is never taken without one, now or later
            if ($transition->requires_note) {
                throw InvalidTransitionException::noteRequired($transitionKey);
            }
            // The engine's permission check and the resolver's (category limits), as the move itself would make them.
            if (! $actor->can($transition->required_permission)) {
                throw new AuthorizationException(__('workflow.err_transition_forbidden'));
            }
            $this->workflows->authorizeMove($workflow, $transitionKey, $entry, $actor);

            $was = $entry->status;
            $entry->update([
                'status' => EntryStatus::Scheduled->value,
                'published_at' => $publishAt,
                'scheduled_by' => $actor->id,
                // pinned: the stage the approval was given at, even for a post that joined the flow without one
                'workflow_state' => $origin,
            ]);
            EntryStatusAudit::record($entry, $was, EntryStatus::Scheduled->value, 'workflow', $actor, [
                'transition' => $transitionKey,
                'publish_at' => $publishAt->format(DATE_ATOM),
            ]);
        });

        $local = app(SiteTime::class)->forDisplay($publishAt);
        $this->notifier->scheduled($entry, $locale, $actor, $local !== null ? $local->format('d/m/Y · H:i') : '');
    }

    /**
     * «Hủy hẹn giờ» on a post approved for later: it goes back to waiting for review at the stage it was
     * approved from (no stage move, nothing published), with its time and approver cleared. Asked of
     * someone who could approve it again from there; with the flow switched off since, of someone who may
     * publish it (the tier-1 rule), and it goes back to a draft. A row that is not (or no longer) approved
     * for later is left as it is.
     *
     * @return bool whether the post was moved (false: it was no longer approved for later)
     *
     * @throws AuthorizationException the actor may not cancel it
     */
    public function cancel(Entry $entry, User $actor): bool
    {
        $step = null;
        $moved = DB::transaction(function () use ($entry, $actor, &$step): bool {
            Entry::withTrashed()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $entry->refresh();
            if ($entry->status !== EntryStatus::Scheduled->value || $entry->scheduled_by === null) {
                return false;
            }

            $workflow = $this->workflows->forEntry($entry);
            if ($workflow instanceof Workflow) {
                if ($this->publishingMove($workflow, $entry, $actor) === null) {
                    throw new AuthorizationException(__('workflow.err_transition_forbidden'));
                }
                $entry->update(['status' => EntryStatus::Pending->value, 'published_at' => null, 'scheduled_by' => null]);
                $origin = EntryWorkflowResolver::originIn($workflow, $entry);
                $state = $workflow->states->firstWhere('key', $origin);
                $step = $state instanceof WorkflowState ? $state->label : $origin;
            } else {
                if (! Gate::forUser($actor)->allows('publish', $entry)) {
                    throw new AuthorizationException(__('entries.err_status_forbidden'));
                }
                $entry->update(['status' => EntryStatus::Draft->value, 'published_at' => null, 'scheduled_by' => null, 'workflow_state' => null]);
            }
            EntryStatusAudit::record($entry, EntryStatus::Scheduled->value, $entry->status, 'workflow', $actor, ['unscheduled' => true]);

            return true;
        });

        if ($moved) {
            $locale = $this->localeOf($entry);
            if ($locale !== null) {
                $this->notifier->unscheduled($entry, $locale, $actor, $step);
            }
        }

        return $moved;
    }

    /**
     * Whether $actor may «Hủy hẹn giờ» on this post approved for later: the rule cancel() applies, read
     * without a lock (a screen deciding whether to offer the button; cancel() judges it again).
     */
    public function mayCancel(Entry $entry, User $actor, ?Workflow $workflow = null): bool
    {
        if ($entry->status !== EntryStatus::Scheduled->value || $entry->scheduled_by === null || $entry->trashed()) {
            return false;
        }
        $workflow ??= $this->workflows->forEntry($entry);

        return $workflow instanceof Workflow
            ? $this->publishingMove($workflow, $entry, $actor) instanceof WorkflowTransition
            : Gate::forUser($actor)->allows('publish', $entry);
    }

    /**
     * Where «Hủy hẹn giờ» puts the post, as its name for the messages around it: the stage it waits at
     * under an approval workflow, else «Nháp».
     */
    public function stepAfterCancel(Entry $entry, ?Workflow $workflow = null): string
    {
        $workflow ??= $this->workflows->forEntry($entry);
        if (! $workflow instanceof Workflow) {
            return (string) __('entries.status_draft');
        }
        $workflow->loadMissing('states');
        $origin = EntryWorkflowResolver::originIn($workflow, $entry);
        $state = $workflow->states->firstWhere('key', $origin);

        return $state instanceof WorkflowState ? $state->label : $origin;
    }

    /**
     * entries:publish-due for a post approved for later whose time has come: the publishing move, through
     * the engine, as its approver. Returns 'published'; 'held' when it was not published and went back to
     * waiting (the approver lost the right, the flow changed, or the approver's account is gone); or
     * 'not_due' when the row was no longer due (trashed, cancelled, rescheduled or gone) and nothing was
     * written. Any other failure (a route conflict) propagates, leaving the row scheduled, for the sweep's
     * own handling.
     *
     * @return 'published'|'held'|'not_due'
     */
    public function publishDue(Entry $entry): string
    {
        /** @var 'published'|'held'|'not_due' $result */
        $result = DB::transaction(function () use ($entry): string {
            $locked = Entry::withTrashed()->whereKey($entry->id)->lockForUpdate()->first();
            if ($locked === null) {
                return 'not_due';
            }
            $entry->refresh();

            $due = $entry->status === EntryStatus::Scheduled->value
                && ! $entry->trashed()
                && $entry->scheduled_by !== null
                && $entry->published_at !== null
                && $entry->published_at <= now();
            if (! $due) {
                return 'not_due';
            }

            $approverId = $entry->scheduled_by;
            $approver = User::query()->find($approverId);
            $workflow = $this->workflows->forEntry($entry);
            $locale = $this->localeOf($entry);
            if (! $workflow instanceof Workflow || $locale === null) {
                return $this->hold($entry, $locale, $approverId, self::HELD_FLOW);
            }
            if (! $approver instanceof User) {
                return $this->hold($entry, $locale, null, self::HELD_RIGHT);
            }

            $move = $this->publishingMove($workflow, $entry, $approver);
            if ($move === null) {
                return $this->hold($entry, $locale, $approverId, $this->anyPublishingMove($workflow, $entry) ? self::HELD_RIGHT : self::HELD_FLOW);
            }

            try {
                $this->transitions->handle($entry, $locale, $approver, $move->key);
            } catch (AuthorizationException) {
                $entry->refresh();

                return $this->hold($entry, $locale, $approverId, self::HELD_RIGHT);
            } catch (InvalidTransitionException|EntryNotGovernedException) {
                $entry->refresh();

                return $this->hold($entry, $locale, $approverId, self::HELD_FLOW);
            }

            return 'published';
        });

        return $result;
    }

    /** Back to waiting at its stage, time and approver cleared, and the author and approver told why. */
    private function hold(Entry $entry, ?string $locale, ?int $approverId, string $why): string
    {
        $entry->update(['status' => EntryStatus::Pending->value, 'published_at' => null, 'scheduled_by' => null]);
        EntryStatusAudit::record($entry, EntryStatus::Scheduled->value, EntryStatus::Pending->value, 'scheduler', null, ['held' => $why], actorLabel: 'system');
        if ($locale !== null) {
            $this->notifier->scheduleFailed($entry, $locale, $approverId, $why);
        }

        return 'held';
    }

    /**
     * The first move $actor may make on $entry from where it stands that puts it live and asks no
     * reason (the same pick as «Duyệt đăng N bài»), or null.
     */
    private function publishingMove(Workflow $workflow, Entry $entry, User $actor): ?WorkflowTransition
    {
        $workflow->loadMissing(['states', 'transitions']);
        foreach ($this->workflows->movesFor($workflow, $entry, $actor) as $transition) {
            if (! $transition->requires_note && $this->isPublishing($workflow, $transition)) {
                return $transition;
            }
        }

        return null;
    }

    /** Whether the flow still has, for anyone, a move from the post's stage that puts it live. */
    private function anyPublishingMove(Workflow $workflow, Entry $entry): bool
    {
        $from = EntryWorkflowResolver::originIn($workflow, $entry);

        return $workflow->transitions->contains(fn (WorkflowTransition $t): bool => $t->from_state === $from
            && ! $t->requires_note && $this->isPublishing($workflow, $t));
    }

    private function isPublishing(Workflow $workflow, WorkflowTransition $transition): bool
    {
        $destination = $workflow->relationLoaded('states')
            ? $workflow->states->firstWhere('key', $transition->to_state)
            : $workflow->states()->where('key', $transition->to_state)->first();

        return $destination instanceof WorkflowState && $destination->is_published;
    }

    /** The language the move acts on: the site default when the post has it, otherwise its first translation (as the review inbox). */
    private function localeOf(Entry $entry): ?string
    {
        $default = app(SiteLocale::class)->default();
        if ($entry->translationFor($default) instanceof EntryTranslation) {
            return $default;
        }

        $first = $entry->translations()->orderBy('id')->first();

        return $first instanceof EntryTranslation ? $first->locale : null;
    }
}
