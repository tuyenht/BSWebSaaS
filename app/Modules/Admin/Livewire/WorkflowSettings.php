<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Cms\Models\Entry;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Registry\WorkflowTemplateRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Per-section approval workflow switchboard (design_handoff_workflow — screen 1). A platform
 * operator picks a curated template per content section and switches it on or off; a section
 * with no active workflow keeps tier-1 permission-driven publishing, so this screen defaulting
 * to "everything off" is exactly the pre-engine behavior.
 *
 * Operator-only by design (north-star: super_admin enables approval per site × per type): the
 * gate is `platform.workflows.manage`, which no tenant role template grants — only platform
 * operators pass, via Gate::before. Granting the permission to a role later opens the screen
 * without a code change.
 *
 * Only POST collections can be governed: workflow resolution runs through SectionResolver,
 * which derives the section from a post's primary category — pages carry no taxonomy, so a
 * page can never reach the engine and offering a toggle for it would be a lie.
 */
#[Layout('admin::layouts.app')]
final class WorkflowSettings extends Component
{
    public function boot(): void
    {
        // Defense-in-depth: the livewire/update endpoint bypasses route middleware, so
        // re-check the operator gate on every request (mount + every action).
        abort_unless(Gate::allows('platform.workflows.manage'), 403);
    }

    /**
     * Apply a curated template to a section, optionally switching it on immediately.
     * Re-applying rebuilds template-shaped stages; switching template also resets in-flight
     * entries back to the new flow's first stage so none are stranded on a stage key the
     * rebuilt workflow no longer defines.
     */
    public function apply(string $sectionKey, string $templateKey, bool $activate = false): void
    {
        $section = $this->governableSections()->firstWhere('key', $sectionKey);
        abort_if($section === null, 404);
        abort_unless(array_key_exists($templateKey, WorkflowTemplateRegistry::all()), 404);

        if ($templateKey === 'editorial' && ! app(Entitlement::class)->featureEnabled('workflow.editorial')) {
            $this->addError('template', __('admin.wf.locked_error'));

            return;
        }

        $previous = Workflow::query()->where('applies_to_type', $sectionKey)->first();

        app(InstantiateWorkflow::class)->handle($templateKey, $sectionKey, $activate);

        if ($previous !== null && $previous->template !== $templateKey) {
            $this->restartInFlightEntries($section);
        }

        session()->flash('status', __('admin.wf.applied'));
        $this->dispatch('workflow-settings-saved');
    }

    /** Switch a section's existing workflow on or off (the confirm dialog lives client-side). */
    public function toggle(string $sectionKey): void
    {
        $workflow = Workflow::query()->where('applies_to_type', $sectionKey)->first();
        abort_if($workflow === null, 404);

        $workflow->update(['is_active' => ! $workflow->is_active]);

        session()->flash('status', $workflow->is_active ? __('admin.wf.turned_on') : __('admin.wf.turned_off'));
    }

    public function render(): View
    {
        $sections = $this->governableSections();
        $workflows = Workflow::query()->get()->keyBy('applies_to_type');
        $counts = $this->sectionCounts($sections);

        $rows = $sections->map(function (ContentSection $section) use ($workflows, $counts): array {
            /** @var Workflow|null $workflow */
            $workflow = $workflows->get($section->key);
            $tally = $counts[(int) $section->taxonomy_id] ?? null;

            return [
                'key' => $section->key,
                'label' => $section->localizedLabel(),
                'icon' => $section->icon,
                'total' => is_numeric($tally?->total) ? (int) $tally->total : 0,
                'pending' => is_numeric($tally?->pending) ? (int) $tally->pending : 0,
                'template' => $workflow?->template,
                'workflow_name' => $workflow?->name,
                'active' => $workflow !== null && $workflow->is_active,
            ];
        })->values()->all();

        $view = view('admin::livewire.workflow-settings', [
            'rows' => $rows,
            'templates' => WorkflowTemplateRegistry::all(),
            'editorialLocked' => ! app(Entitlement::class)->featureEnabled('workflow.editorial'),
            'hasAnyWorkflow' => $workflows->isNotEmpty(),
        ]);
        $view->title(__('admin.workflow_settings'));

        return $view;
    }

    /**
     * The sections a workflow can actually govern: post collections bound to a taxonomy
     * (SectionResolver's derivation path). Products live in Catalog, outside the engine's
     * entry-typed subject union, so its legacy section is filtered like the nav does.
     *
     * @return Collection<int, ContentSection>
     */
    private function governableSections(): Collection
    {
        return ContentSection::query()
            ->where('entry_type', 'post')
            ->whereNotNull('taxonomy_id')
            ->where('key', '!=', 'products')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Total + pending entry tallies per section in ONE grouped query (§6 flat, not per-row):
     * a post belongs to the section whose taxonomy is its primary category (pivot position 0),
     * mirroring the list screen's scope so both surfaces always report the same numbers.
     *
     * @param  Collection<int, ContentSection>  $sections
     * @return array<int|string, \stdClass> rows of {taxonomy_id, total, pending} keyed by taxonomy_id
     */
    private function sectionCounts(Collection $sections): array
    {
        $taxonomyIds = $sections->pluck('taxonomy_id')->all();

        if ($taxonomyIds === []) {
            return [];
        }

        // toBase() keeps the Eloquent global scopes (site + soft-delete) but returns plain
        // rows — this is an aggregate, not a hydration.
        return Entry::query()
            ->join('entry_taxonomies', function (JoinClause $join): void {
                $join->on('entry_taxonomies.entry_id', '=', 'entries.id')
                    ->where('entry_taxonomies.position', 0);
            })
            ->whereIn('entry_taxonomies.taxonomy_id', $taxonomyIds)
            ->selectRaw("entry_taxonomies.taxonomy_id, count(*) as total, count(*) filter (where entries.status = 'pending') as pending")
            ->groupBy('entry_taxonomies.taxonomy_id')
            ->toBase()
            ->get()
            ->keyBy('taxonomy_id')
            ->all();
    }

    /**
     * After a template SWAP, entries mid-pipeline may sit on a stage key the new flow does not
     * define — no transition would leave it, stranding them. Clearing the pointer re-enters
     * them at the new flow's first stage (the engine treats null as the initial stage); their
     * public status is untouched, so nothing appears or disappears from the storefront.
     */
    private function restartInFlightEntries(ContentSection $section): void
    {
        Entry::query()
            ->whereNotNull('workflow_state')
            ->whereHas('taxonomies', function ($taxonomy) use ($section): void {
                $taxonomy->where('taxonomies.id', $section->taxonomy_id)
                    ->where('entry_taxonomies.position', 0);
            })
            ->update(['workflow_state' => null]);
    }
}
