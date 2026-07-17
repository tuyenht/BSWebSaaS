<?php

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Services\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Workflow Tầng-2 (design.md §15.4) — Cms is the engine's first consumer. The engine owns the
 * rules (edge exists, origin matches, permission, note) and returns the destination state; this
 * action owns what that means for an Entry: its workflow_state, its public lifecycle status, and
 * its route.
 *
 * The two writes live in ONE transaction: a log row saying "approved" while the entry stayed
 * unpublished (or a published entry with no route) is exactly the divergence the engine refuses
 * to own by writing the subject itself.
 *
 * Visibility is decided by the STATE FLAG, never by a state's name: only `is_published` puts
 * content live, so a site may call its final stage anything. Rejecting live content back down
 * therefore takes it offline again, which is the point of an approval gate.
 */
final readonly class ApplyEntryWorkflowTransition
{
    public function __construct(
        private SectionResolver $sections,
        private WorkflowEngine $engine,
        private RouteSync $routes,
    ) {}

    /**
     * Move an entry along its workflow; returns the destination state key.
     *
     * @throws RuntimeException no workflow governs this entry (the caller should use tier-1)
     */
    public function handle(
        Entry $entry,
        string $locale,
        User $actor,
        string $transitionKey,
        ?string $note = null,
        ?int $assignTo = null,
    ): string {
        $translation = $entry->translationFor($locale)
            ?? throw new RuntimeException("Entry {$entry->id} has no '{$locale}' translation to move.");

        $section = $this->sections->forEntry($entry);
        $workflow = $section !== null ? $this->engine->activeFor($section['key']) : null;

        if ($workflow === null) {
            throw new RuntimeException("Entry {$entry->id} is not governed by an active workflow.");
        }

        // An entry created before the workflow was switched on carries no state yet — it starts at
        // the initial stage rather than being stranded outside the machine.
        $fromState = $entry->workflow_state;
        if ($fromState === null) {
            $initial = $workflow->initialState()
                ?? throw new RuntimeException("Workflow {$workflow->id} defines no initial state.");
            $fromState = $initial->key;
        }

        return DB::transaction(function () use ($entry, $translation, $locale, $actor, $workflow, $transitionKey, $fromState, $note, $assignTo): string {
            $destination = $this->engine->apply(
                $workflow, $transitionKey, $fromState, $actor, $entry->id, $locale, $note, $assignTo,
            );

            // Map the workflow stage onto the public lifecycle: live only when the stage says so;
            // back at the start = a draft again; anywhere in between = in the pipeline, not public.
            $status = match (true) {
                $destination->is_published => 'published',
                $destination->is_initial => 'draft',
                default => 'pending',
            };

            $entry->update([
                'workflow_state' => $destination->key,
                'status' => $status,
                'published_at' => $destination->is_published ? ($entry->published_at ?? now()) : $entry->published_at,
                'assigned_to' => $assignTo ?? $entry->assigned_to,
            ]);
            $translation->update(['status' => $status]);

            if ($destination->is_published) {
                $this->routes->sync($entry, $locale, $translation->slug);
            }

            return $destination->key;
        });
    }
}
