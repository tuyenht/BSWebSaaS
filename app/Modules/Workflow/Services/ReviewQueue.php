<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowState;

/**
 * Which content is waiting for THIS person to act (design_handoff_workflow screen 3).
 *
 * The question "is this entry in my queue?" is answered by a set of STAGE KEYS, not by walking
 * entries: for each active workflow, take the transitions this user's permissions allow and
 * collect the stages they start from. The caller then filters its own subject table with one
 * `whereIn` — which is what keeps the inbox a flat query as a site's content grows, and keeps
 * this service free of any knowledge of what an entry is (the engine's module boundary).
 *
 * Two stages never belong in a review queue, whatever the permissions say:
 *   - the INITIAL stage — an unsent draft is the author's work in progress, not a submission;
 *   - a PUBLISHED stage — live content is not waiting for anyone (retract/archive still exist
 *     from there, but they are corrections, not a queue of work).
 *
 * Runs inside the caller's tenant context (BelongsToSite + RLS scope every query to the site).
 */
final readonly class ReviewQueue
{
    /**
     * The stages this actor can move content out of, per governed content type.
     *
     * @return array<string, list<string>> applies_to_type => stage keys
     */
    public function actionableStages(User $actor): array
    {
        $stages = [];

        // Ordered so the caller's type filter and any listing built on this keep a stable
        // order between requests.
        $active = Workflow::query()->where('is_active', true)->orderBy('applies_to_type')->with(['states', 'transitions'])->get();

        foreach ($active as $workflow) {
            $keys = $this->stagesFor($workflow, $actor);
            if ($keys !== []) {
                $stages[$workflow->applies_to_type] = $keys;
            }
        }

        return $stages;
    }

    /**
     * Every stage key of every active workflow this actor can act on — the flat union, for a
     * single `whereIn` over the subject table.
     *
     * @return list<string>
     */
    public function actionableStageKeys(User $actor): array
    {
        $perType = array_values($this->actionableStages($actor));

        return array_values(array_unique($perType === [] ? [] : array_merge(...$perType)));
    }

    /**
     * @param  Workflow  $workflow  with `states` and `transitions` already loaded
     * @return list<string>
     */
    private function stagesFor(Workflow $workflow, User $actor): array
    {
        $excluded = $workflow->states
            ->filter(fn (WorkflowState $state): bool => $state->is_initial || $state->is_published)
            ->pluck('key')
            ->all();

        $stages = [];
        foreach ($workflow->transitions as $transition) {
            $stage = $transition->from_state;
            if (in_array($stage, $excluded, true) || in_array($stage, $stages, true)) {
                continue;
            }
            if ($actor->can($transition->required_permission)) {
                $stages[] = $stage;
            }
        }

        return $stages;
    }
}
