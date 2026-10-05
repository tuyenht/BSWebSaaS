<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowTransition;
use App\Modules\Workflow\Services\WorkflowEngine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Answers two questions for the Cms consumers of the workflow engine.
 *
 * WHICH workflow governs this content? Derived, not stored — an entry's section comes from its
 * primary category (SectionResolver), and the engine only reports a workflow the site has
 * switched on. Null means tier-1 permission-driven publishing, which is every site's default.
 * Every road to `published` (publish, schedule, the scheduler) asks here, so the gate cannot be
 * bypassed by one path forgetting the check. A page never resolves: it carries no taxonomy, so
 * pages stay tier-1 by construction (design.md §15.4).
 *
 * WHERE in that workflow does the entry stand? Its stored `workflow_state` when it has one;
 * otherwise the stage its PUBLIC status implies — content that was already live when the flow
 * was switched on stands at the published stage (a "submit" from there does not exist, so
 * switching a workflow on can never hide what visitors already see), and everything else starts
 * at the initial stage. The action and the editor both read this one definition, so the buttons
 * a user sees are exactly the moves the engine will accept.
 *
 * MAY this person make this move on THIS entry? The engine checks only the move's permission,
 * because it knows nothing of entries. Sending for review is also working on the entry, so a
 * move guarded by entries.submit additionally needs EntryPolicy::submit (the right to edit this
 * entry). The actions enforce it, and the button lists and the hand-off list filter by it, from
 * this one definition.
 */
final readonly class EntryWorkflowResolver
{
    /** The permission that means "send for review", wherever a site's workflow uses it. */
    private const string SUBMIT_PERMISSION = 'entries.submit';

    public function __construct(
        private SectionResolver $sections,
        private WorkflowEngine $engine,
    ) {}

    public function forEntry(Entry $entry): ?Workflow
    {
        $section = $this->sections->forEntry($entry);

        return $section !== null ? $this->engine->activeFor($section['key']) : null;
    }

    /** For content that does not exist yet: the workflow its chosen primary category would put it under. */
    public function forCategory(Taxonomy $primary): ?Workflow
    {
        $section = $this->sections->forCategory($primary);

        return $section !== null ? $this->engine->activeFor($section['key']) : null;
    }

    /**
     * The stage key the engine must treat as the entry's origin (see class doc).
     *
     * @throws RuntimeException the workflow defines no initial stage (a broken flow, never a user error)
     */
    public function originFor(Workflow $workflow, Entry $entry): string
    {
        // Trust the stored pointer only while this workflow still defines it. A post re-filed
        // into another section, or one whose template was swapped without its pointer being
        // cleared, would otherwise be handed a stage the engine does not know: no transition
        // starts there, so the editor would show a stepper with no buttons at all — a dead end
        // with no way out. Falling through re-derives the stage from what is actually true.
        if ($entry->workflow_state !== null && $workflow->definesState($entry->workflow_state)) {
            return $entry->workflow_state;
        }

        if ($entry->status === 'published') {
            $published = $workflow->publishedState();
            if ($published !== null) {
                return $published->key;
            }
        }

        $initial = $workflow->initialState()
            ?? throw new RuntimeException("Workflow {$workflow->id} defines no initial state.");

        return $initial->key;
    }

    /**
     * The stage a pending change to a live entry stands at: its stored stage while the workflow
     * still defines it, otherwise the initial one (a change never sits at the published stage —
     * reaching it applies the change and deletes it).
     *
     * @throws RuntimeException the workflow defines no initial state
     */
    public function changeOriginFor(Workflow $workflow, EntryDraft $change): string
    {
        if ($change->workflow_state !== null && $workflow->definesState($change->workflow_state)) {
            return $change->workflow_state;
        }

        $initial = $workflow->initialState()
            ?? throw new RuntimeException("Workflow {$workflow->id} defines no initial state.");

        return $initial->key;
    }

    /**
     * Whether a change may still be edited or thrown away by its authors: always when no workflow
     * governs the entry (a flow switched off mid-review leaves the change with its author and the
     * publishers), otherwise only at the workflow's first stage — once sent, it is what the
     * reviewer reads, and it comes back to the author only by being returned.
     */
    public function changeIsOpen(?Workflow $workflow, EntryDraft $change): bool
    {
        if ($workflow === null || $change->workflow_state === null) {
            return true;
        }

        // A stage the workflow no longer defines counts as the initial one, as in changeOriginFor().
        $initial = $workflow->initialState();

        return $initial === null
            || $change->workflow_state === $initial->key
            || ! $workflow->definesState($change->workflow_state);
    }

    /**
     * Whether $actor may make $transition on $entry, on top of the permission the engine checks
     * itself (see class doc). Answered in memory: the permission cache and the loaded entry.
     */
    public function mayApply(WorkflowTransition $transition, Entry $entry, User $actor): bool
    {
        return $transition->required_permission !== self::SUBMIT_PERMISSION
            || Gate::forUser($actor)->allows('submit', $entry);
    }

    /**
     * The members of the current site who may make at least one of $transitions on $entry: the
     * engine's permission check plus mayApply(), as one query for lists of people (a hand-off
     * picker) that must not run the gate member by member. The policy's edit rule is restated
     * here in SQL: entries.edit.any, or entries.edit.own while being the entry's author (an entry
     * with no author: edit.any only). Spatie's permission scope honours the team (site) on both
     * the role and the direct grant. A platform super_admin passes the gate through
     * Gate::before without holding the grants, so is listed only as far as their grants reach.
     *
     * @param  iterable<WorkflowTransition>  $transitions
     * @return Builder<User>|null null when no transition is given (nobody can make a move that does not exist)
     */
    public function membersWhoMayApply(iterable $transitions, Entry $entry): ?Builder
    {
        $permissions = [];
        foreach ($transitions as $transition) {
            $permissions[$transition->required_permission] = true;
        }
        if ($permissions === []) {
            return null;
        }

        $sends = isset($permissions[self::SUBMIT_PERMISSION]);
        unset($permissions[self::SUBMIT_PERMISSION]);
        $others = array_keys($permissions);
        $authorId = $entry->author_id;

        return User::query()->where(function (Builder $members) use ($others, $sends, $authorId): void {
            if ($others !== []) {
                $members->orWhere(fn (Builder $holder) => $holder->permission($others));
            }
            if ($sends) {
                $members->orWhere(fn (Builder $sender) => $sender
                    ->permission(self::SUBMIT_PERMISSION)
                    ->where(fn (Builder $editor) => $editor
                        ->permission('entries.edit.any')
                        ->when($authorId !== null, fn (Builder $any) => $any->orWhere(fn (Builder $own) => $own
                            ->whereKey($authorId)
                            ->permission('entries.edit.own')))));
            }
        });
    }

    /**
     * Refuse the move named $transitionKey when mayApply() says no. A key the workflow does not
     * define passes through, so the engine reports it as the invalid move it is. Call it inside
     * the subject's row lock, right before WorkflowEngine::apply().
     *
     * @throws AuthorizationException
     */
    public function authorizeMove(Workflow $workflow, string $transitionKey, Entry $entry, User $actor): void
    {
        $transition = $workflow->transitions()->where('key', $transitionKey)->first();

        if ($transition !== null && ! $this->mayApply($transition, $entry, $actor)) {
            throw new AuthorizationException(__('workflow.err_transition_forbidden'));
        }
    }
}
