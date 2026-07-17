<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Actions;

use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Registry\WorkflowTemplateRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Materializes a curated template (WorkflowTemplateRegistry) into workflows/states/transitions
 * rows for the current site, governing one content type. Runs inside the caller's tenant context
 * (BelongsToSite stamps site_id + RLS scopes every write).
 *
 * Idempotent by (site, applies_to_type): re-running rebuilds the states + transitions from the
 * template, so re-seeding a site is safe. Because it REBUILDS, it is a provisioning tool, not an
 * editor — once a site has hand-tuned its stages, drive changes through that editor, not this.
 */
final readonly class InstantiateWorkflow
{
    /**
     * @param  bool  $active  switch it on now, or stage it disabled (default) for an operator to enable
     */
    public function handle(string $templateKey, string $appliesToType, bool $active = false): Workflow
    {
        $template = WorkflowTemplateRegistry::all()[$templateKey]
            ?? throw new InvalidArgumentException("Unknown workflow template '{$templateKey}'.");

        return DB::transaction(function () use ($templateKey, $template, $appliesToType, $active): Workflow {
            $workflow = Workflow::query()->updateOrCreate(
                ['applies_to_type' => $appliesToType],
                ['key' => $templateKey, 'name' => $template['name'], 'template' => $templateKey, 'is_active' => $active],
            );

            // Rebuild children from the template. Transitions carry a composite FK onto states, so
            // they must go first; recreating from scratch keeps a re-seed exactly template-shaped.
            $workflow->transitions()->delete();
            $workflow->states()->delete();

            foreach ($template['states'] as $order => $state) {
                $workflow->states()->create([
                    'key' => $state['key'],
                    'label' => $state['label'],
                    'sort_order' => $order,   // declaration order is the display order
                    'is_initial' => $state['is_initial'] ?? false,
                    'is_published' => $state['is_published'] ?? false,
                    'is_terminal' => $state['is_terminal'] ?? false,
                ]);
            }

            foreach ($template['transitions'] as $transition) {
                $workflow->transitions()->create([
                    'key' => $transition['key'],
                    'label' => $transition['label'],
                    'from_state' => $transition['from'],
                    'to_state' => $transition['to'],
                    'required_permission' => $transition['permission'],
                    'requires_note' => $transition['note'] ?? false,
                ]);
            }

            return $workflow;
        });
    }
}
