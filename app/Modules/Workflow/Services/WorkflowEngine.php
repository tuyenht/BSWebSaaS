<?php

namespace App\Modules\Workflow\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Exceptions\InvalidTransitionException;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowLog;
use App\Modules\Workflow\Models\WorkflowState;
use App\Modules\Workflow\Models\WorkflowTransition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tier-2 workflow engine (design.md §15.4): moves a subject between DATA-DEFINED states, so a
 * site can add or reorder stages (gửi → biên tập → phản biện → duyệt) without a deploy.
 *
 * SUBJECT-AGNOSTIC BY CONSTRUCTION. The engine never references Cms — it takes an entry id as a
 * plain int and returns the destination state; the CALLER writes that state onto its own model.
 * That is what keeps the module graph acyclic (Cms → Workflow, never back), the same discipline
 * RouteSync uses to route products without importing Catalog. It is also what makes the engine
 * reusable for a future subject: nothing here knows what an entry is.
 *
 * FAIL-SAFE BY DEFAULT: activeFor() returns null unless a site has explicitly switched a workflow
 * on, and every caller treats null as "tier-1 permission-driven publish". An un-configured site
 * therefore behaves exactly as it did before this engine existed.
 *
 * Runs inside the caller's tenant context (BelongsToSite + RLS scope every query to the site).
 */
final readonly class WorkflowEngine
{
    /** The subject itself moved (an entry through its workflow). */
    public const string TARGET_ENTRY = 'entry';

    /** A pending change to a live subject moved; the subject itself stays where it is. */
    public const string TARGET_CHANGE = 'change';

    /**
     * The ACTIVE workflow governing a content type, or null when the site never opted in (or
     * disabled it) — null is the signal to fall back to tier-1, never an error.
     */
    public function activeFor(string $appliesToType): ?Workflow
    {
        return Workflow::query()
            ->where('applies_to_type', $appliesToType)
            ->where('is_active', true)
            ->first();
    }

    /**
     * The transitions $actor may actually apply from $fromState — the exact set an editor's
     * action bar should render (never show a button the guard would reject).
     *
     * @return Collection<int, WorkflowTransition>
     */
    public function allowedTransitions(Workflow $workflow, string $fromState, User $actor): Collection
    {
        return $workflow->transitions()
            ->where('from_state', $fromState)
            ->get()
            ->filter(fn (WorkflowTransition $transition): bool => $actor->can($transition->required_permission))
            ->values();
    }

    /**
     * Apply a transition: guard → log → return the destination state. The caller persists the new
     * state on its subject, inside its own transaction, so the move and the subject's status can
     * never diverge.
     *
     * $target records WHAT moved, so the trail can tell a live entry leaving the site apart from a
     * pending change to it passing through the same stages. The engine does not interpret it.
     *
     * @param  self::TARGET_*  $target
     *
     * @throws \InvalidArgumentException an unknown $target (a programming error, never user input)
     * @throws InvalidTransitionException unknown edge, wrong origin, or a missing required note
     * @throws AuthorizationException the actor lacks the transition's required_permission
     */
    public function apply(
        Workflow $workflow,
        string $transitionKey,
        string $fromState,
        User $actor,
        int $entryId,
        ?string $locale = null,
        ?string $note = null,
        ?int $assignTo = null,
        string $target = self::TARGET_ENTRY,
    ): WorkflowState {
        if (! in_array($target, [self::TARGET_ENTRY, self::TARGET_CHANGE], true)) {
            throw new \InvalidArgumentException("Unknown workflow target [{$target}].");
        }

        $transition = $workflow->transitions()->where('key', $transitionKey)->first()
            ?? throw InvalidTransitionException::notDefined($transitionKey);

        // Guard the ORIGIN too, not just the edge's existence: without this, a stale tab could
        // replay an approve from a state the content has since left.
        if ($transition->from_state !== $fromState) {
            throw InvalidTransitionException::wrongOrigin($transitionKey, $fromState);
        }

        if (! $actor->can($transition->required_permission)) {
            throw new AuthorizationException(__('workflow.err_transition_forbidden'));
        }

        if ($transition->requires_note && ($note === null || trim($note) === '')) {
            throw InvalidTransitionException::noteRequired($transitionKey);
        }

        // Both endpoints in one lookup: the destination is what the caller persists, and the
        // origin's label is needed for the trail snapshot below.
        $endpoints = $workflow->states()
            ->whereIn('key', [$fromState, $transition->to_state])
            ->get()
            ->keyBy('key');

        $destination = $endpoints->get($transition->to_state)
            ?? throw InvalidTransitionException::notDefined($transitionKey);

        // One insert needs no transaction of its own, and wrapping it here would be a lie about
        // atomicity: the move only means anything once the CALLER persists $destination on its
        // subject, so the caller's transaction is the one that must cover both.
        WorkflowLog::query()->create([
            'workflow_id' => $workflow->id,
            'entry_id' => $entryId,
            'locale' => $locale,
            'target' => $target,
            'transition_key' => $transition->key,
            'from_state' => $fromState,
            'to_state' => $transition->to_state,
            // Snapshot the display text: a later template swap rebuilds states/transitions, so
            // resolving these keys through a live join would eventually render raw keys.
            'labels' => [
                'transition' => $transition->label,
                'from' => $endpoints->get($fromState)?->label,
                'to' => $destination->label,
            ],
            'actor_id' => $actor->id,
            'assigned_to' => $assignTo,
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
        ]);

        return $destination;
    }
}
