<?php

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Exceptions\EntryNotGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Support\EntryStatusAudit;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Services\WorkflowEngine;
use DateTimeInterface;
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
        private EntryWorkflowResolver $workflows,
        private WorkflowEngine $engine,
        private RouteSync $routes,
    ) {}

    /**
     * Move an entry along its workflow; returns the destination state key.
     *
     * @throws EntryNotGovernedException no workflow governs this entry (the caller should use tier-1)
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

        $workflow = $this->workflows->forEntry($entry)
            ?? throw EntryNotGovernedException::for($entry->id);

        $fromStatus = $entry->status;

        $destinationKey = DB::transaction(function () use ($entry, $translation, $locale, $actor, $workflow, $transitionKey, $note, $assignTo): string {
            // Take the row lock FIRST, then read. A shared review inbox makes two people acting
            // on the same post routine; if the stage were read before the lock, both would read
            // the same value, then queue on the lock and both pass the engine's origin guard —
            // two trail rows, two audit rows, a last-writer-wins status, and one reviewer told
            // their decision stuck when it did not. Reading after the lock makes the second
            // caller see the first one's result and be refused, which is the point.
            Entry::query()->whereKey($entry->id)->lockForUpdate()->first();
            $entry->refresh();

            // An entry created before the workflow was switched on carries no state yet: live
            // content stands at the published stage, anything else at the initial one — never
            // stranded outside the machine, never hidden by joining it.
            $fromState = $this->workflows->originFor($workflow, $entry);

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
                // Going live keeps the original publication date when there is one in the PAST
                // (a post taken down and approved again keeps its date), but a slot still in the
                // future — an entry that was scheduled before the flow was switched on — becomes
                // now: the decision to publish is being taken today, and post lists order by
                // this column, so a future date would park it at the top of the news page.
                'published_at' => $destination->is_published ? $this->publicationDate($entry) : $entry->published_at,
                'assigned_to' => $assignTo ?? $entry->assigned_to,
            ]);
            $translation->update(['status' => $status]);

            if ($destination->is_published) {
                $this->routes->sync($entry, $locale, $translation->slug);
            }

            return $destination->key;
        });

        // The workflow_log row is the transition's own record; this line is what lets ONE
        // query answer "who changed this entry's status, by any road" across tier-1 and tier-2.
        EntryStatusAudit::record($entry, $fromStatus, $entry->status, 'workflow', $actor, ['transition' => $transitionKey]);

        return $destinationKey;
    }

    /** The date to stamp when content goes live — see the call site for why a future slot is dropped. */
    private function publicationDate(Entry $entry): DateTimeInterface
    {
        $existing = $entry->published_at;

        return $existing instanceof DateTimeInterface && $existing <= now() ? $existing : now();
    }
}
