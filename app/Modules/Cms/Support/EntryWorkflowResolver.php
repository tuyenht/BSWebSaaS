<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Services\WorkflowEngine;
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
 */
final readonly class EntryWorkflowResolver
{
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
        if ($entry->workflow_state !== null && $workflow->states()->where('key', $entry->workflow_state)->exists()) {
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
}
