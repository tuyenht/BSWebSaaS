<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\PlanFeature;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Registry\WorkflowTemplateRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Per-section approval workflow switchboard (design 18 "Quy trình duyệt"). A platform operator
 * picks a curated template per content section — or none — and switches it on or off; a section
 * with no active workflow keeps tier-1 permission-driven publishing, so this screen defaulting
 * to "everything off" is exactly the pre-engine behavior.
 *
 * Every pick and switch is staged in the browser; nothing reaches the database until the
 * operator saves, and then the whole batch is applied in one transaction (save()).
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
    /** Most sections one save may name: far above any real site, low enough to bound the work. */
    private const int MAX_CHANGES = 200;

    #[Locked]
    public string $toast = '';

    /** Counts the messages shown: a new one — even with the same words — starts its own timer. */
    #[Locked]
    public int $toastSeq = 0;

    public function boot(): void
    {
        // Defense-in-depth: the livewire/update endpoint bypasses route middleware, so
        // re-check the operator gate on every request (mount + every action).
        abort_unless(Gate::allows('platform.workflows.manage'), 403);
    }

    /**
     * Apply the staged changes: a list of {key, template, active}, where template null means
     * "no approval — publish directly". The whole batch is validated before anything is written
     * and applied in one transaction, so one bad entry leaves every section as it was.
     *
     * Per section: no template takes the workflow out (its stages go, it is marked 'none' and off,
     * in-flight posts are released); a different template rebuilds the flow and re-enters
     * in-flight posts at its first stage; the same template only switches it on or off, so a
     * flow is never rebuilt by a mere toggle.
     *
     * @param  array<mixed>  $changes
     * @return bool whether the batch was saved (the page keeps its staged changes when not)
     */
    public function save(array $changes): bool
    {
        $sections = $this->governableSections()->keyBy('key');

        try {
            /** @var array{changes: list<array{key: string, template: string|null, active: bool}>} $valid */
            $valid = Validator::make(['changes' => $changes], [
                'changes' => ['present', 'array', 'list', 'max:'.self::MAX_CHANGES],
                'changes.*' => ['array:key,template,active'],
                'changes.*.key' => ['required', 'string', 'distinct', Rule::in($sections->keys()->all())],
                'changes.*.template' => ['present', 'nullable', 'string', Rule::in(WorkflowTemplateRegistry::keys())],
                'changes.*.active' => ['required', 'boolean'],
            ])->validate();
        } catch (ValidationException) {
            // The page only ever sends well-formed changes for the sections it lists; anything else
            // is a stale page (a section removed meanwhile) or a crafted request.
            $this->addError('changes', __('admin.wf.save_invalid'));

            return false;
        }

        $batch = $valid['changes'];

        // The plan lock is enforced here, whatever the page showed: a crafted request for the
        // editorial flow on a site without the feature is refused like the locked card.
        $wantsEditorial = array_filter($batch, fn (array $change): bool => $change['template'] === 'editorial');
        if ($wantsEditorial !== [] && ! app(Entitlement::class)->featureEnabled('workflow.editorial')) {
            $this->addError('changes', __('admin.wf.locked_error'));

            return false;
        }

        DB::transaction(function () use ($batch, $sections): void {
            $workflows = Workflow::query()
                ->whereIn('applies_to_type', array_column($batch, 'key'))
                ->get()
                ->keyBy('applies_to_type');

            foreach ($batch as $change) {
                /** @var ContentSection $section */
                $section = $sections->get($change['key']);
                $this->applyChange($section, $workflows->get($change['key']), $change['template'], (bool) $change['active']);
            }
        });

        $this->showToast(__('admin.wf.saved'));

        return true;
    }

    /**
     * The note after a pick, which is only staged: what the section will use once saved. Nothing
     * is written; an unknown section or template just shows nothing.
     */
    public function staged(string $sectionKey, ?string $templateKey = null): void
    {
        $section = $this->governableSections()->firstWhere('key', $sectionKey);
        if ($section === null) {
            return;
        }

        if ($templateKey === null) {
            $this->showToast(__('admin.wf.staged_none', ['name' => $section->localizedLabel()]));

            return;
        }

        $template = WorkflowTemplateRegistry::localized(app()->getLocale())[$templateKey] ?? null;
        if ($template !== null) {
            $this->showToast(__('admin.wf.staged_pick', ['name' => $section->localizedLabel(), 'wf' => $template['name']]));
        }
    }

    /** The message $seq hid itself or was closed; a newer one on its way is kept. */
    public function dismissToast(int $seq): void
    {
        if ($seq === $this->toastSeq) {
            $this->toast = '';
        }
    }

    public function render(): View
    {
        $sections = $this->governableSections();
        $workflows = Workflow::query()->get()->keyBy('applies_to_type');
        $counts = $this->sectionCounts($sections);
        $locale = app()->getLocale();
        $templates = WorkflowTemplateRegistry::localized($locale);

        $rows = $sections->map(function (ContentSection $section) use ($workflows, $counts, $templates): array {
            /** @var Workflow|null $workflow */
            $workflow = $workflows->get($section->key);
            $tally = $counts[(int) $section->taxonomy_id] ?? null;
            // 'none' is a workflow taken out of service: kept for its history, never governing.
            $template = $workflow !== null && isset($templates[$workflow->template]) ? $workflow->template : null;

            return [
                'key' => $section->key,
                'label' => $section->localizedLabel(),
                'icon' => $section->icon,
                'total' => is_numeric($tally?->total) ? (int) $tally->total : 0,
                'writers' => is_numeric($tally?->writers) ? (int) $tally->writers : 0,
                'pending' => is_numeric($tally?->pending) ? (int) $tally->pending : 0,
                'template' => $template,
                'workflow_name' => $template !== null ? $workflow->name : null,
                'active' => $template !== null && (bool) $workflow->is_active,
            ];
        })->values()->all();

        $editorialLocked = ! app(Entitlement::class)->featureEnabled('workflow.editorial');
        $site = $this->siteSummary();
        // Only worth the lookup when there is actually something locked to explain.
        $grantingPlans = $editorialLocked ? $this->plansGranting('workflow.editorial') : [];

        $view = view('admin::livewire.workflow-settings', [
            'rows' => $rows,
            'templates' => $this->templateCards($templates, $editorialLocked, $grantingPlans[0] ?? null),
            'editorialLocked' => $editorialLocked,
            'site' => $site,
            // The plan page lives on the platform console, where the operator changes a plan.
            'upgradeUrl' => $editorialLocked && $grantingPlans !== [] && $site['tenant_ulid'] !== null
                ? route('platform.tenants.show', $site['tenant_ulid'])
                : null,
            'hasAnyWorkflow' => collect($rows)->contains(fn (array $row): bool => $row['template'] !== null),
        ]);
        $view->title(__('admin.workflow_settings'));

        return $view;
    }

    /**
     * One staged change onto the database (inside save()'s transaction).
     */
    private function applyChange(ContentSection $section, ?Workflow $previous, ?string $templateKey, bool $active): void
    {
        $previousTemplate = $previous !== null && $previous->template !== 'none' ? $previous->template : null;

        if ($templateKey === null) {
            if ($previousTemplate === null) {
                return;
            }

            // No approval for this section. The workflow row stays, as 'none' and off, so the
            // approval history (workflow_log) keeps pointing at it; its stages go, transitions
            // first (they carry a composite FK onto the states). In-flight posts let go of a
            // stage that no longer exists and publish the ordinary way again.
            $previous->transitions()->delete();
            $previous->states()->delete();
            $previous->update(['template' => 'none', 'is_active' => false]);
            $this->restartInFlightEntries($section);

            return;
        }

        if ($previousTemplate === $templateKey) {
            if ((bool) $previous->is_active !== $active) {
                $previous->update(['is_active' => $active]);
            }

            return;
        }

        app(InstantiateWorkflow::class)->handle($templateKey, $section->key, $active);

        // A SWAP: entries mid-pipeline may sit on a stage key the new flow does not define.
        if ($previousTemplate !== null) {
            $this->restartInFlightEntries($section);
        }
    }

    private function showToast(string $message): void
    {
        $this->toast = $message;
        $this->toastSeq++;
    }

    /**
     * The site being edited, its customer's public key and the plan it is on — one query; every
     * part may be missing (a site with no customer or no plan) and the page says so.
     *
     * @return array{name: string, tenant_ulid: string|null, plan: string|null}
     */
    private function siteSummary(): array
    {
        $row = Site::query()
            ->whereKey(app(CurrentSite::class)->id())
            ->leftJoin('tenants', 'sites.tenant_id', '=', 'tenants.id')
            ->leftJoin('plans', 'tenants.plan_id', '=', 'plans.id')
            ->toBase()
            ->first(['sites.name as site_name', 'tenants.ulid as tenant_ulid', 'plans.name as plan_name']);

        return [
            'name' => is_string($row?->site_name) ? $row->site_name : '',
            'tenant_ulid' => is_string($row?->tenant_ulid) ? $row->tenant_ulid : null,
            'plan' => is_string($row?->plan_name) ? $row->plan_name : null,
        ];
    }

    /**
     * The active plans that actually grant a feature, cheapest first — from real rows: a platform
     * with no plan catalog resolves to none, and the page then offers no plan to look at.
     *
     * @return list<string>
     */
    private function plansGranting(string $featureKey): array
    {
        // jsonb truthiness is decided in PHP: a grant may be stored as true or as a numeric limit,
        // and there are only a handful of plan rows to begin with.
        $grantingPlanIds = PlanFeature::query()
            ->where('feature_key', $featureKey)
            ->get()
            ->filter(fn (PlanFeature $feature): bool => (bool) $feature->value)
            ->pluck('plan_id');

        /** @var list<string> $plans */
        $plans = Plan::query()
            ->whereIn('id', $grantingPlanIds)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('name')
            ->filter(fn (mixed $name): bool => is_string($name))
            ->values()
            ->all();

        return $plans;
    }

    /**
     * What the picker shows per template: its description, its stages, and each move with the
     * display name of the permission it needs, plus whether the site's plan opens it.
     *
     * @param  array<string, array{name: string, description: string, states: list<array{key: string, label: string, is_initial?: bool, is_published?: bool, is_terminal?: bool}>, transitions: list<array{key: string, label: string, from: string, to: string, permission: string, note?: bool}>}>  $templates
     * @return list<array{key: string, name: string, description: string, locked: bool, need: string|null, states: list<array{key: string, label: string, kind: string}>, forward: list<array{label: string, flow: string, permission: string, note: bool}>, back: list<array{label: string, flow: string, permission: string, note: bool}>}>
     */
    private function templateCards(array $templates, bool $editorialLocked, ?string $needPlan): array
    {
        $cards = [];

        foreach ($templates as $key => $template) {
            $labels = array_column($template['states'], 'label', 'key');
            $moves = ['forward' => [], 'back' => []];

            foreach ($template['transitions'] as $transition) {
                $note = $transition['note'] ?? false;
                $permission = 'admin.wf.perm.'.$transition['permission'];
                $moves[$note ? 'back' : 'forward'][] = [
                    'label' => $transition['label'],
                    'flow' => ($labels[$transition['from']] ?? $transition['from']).' → '.($labels[$transition['to']] ?? $transition['to']),
                    'permission' => __($permission) !== $permission ? __($permission) : __('admin.wf.perm_other'),
                    'note' => $note,
                ];
            }

            $locked = $key === 'editorial' && $editorialLocked;
            $cards[] = [
                'key' => $key,
                'name' => $template['name'],
                'description' => $template['description'],
                'locked' => $locked,
                'need' => $locked ? $needPlan : null,
                'states' => array_map(fn (array $state): array => [
                    'key' => $state['key'],
                    'label' => $state['label'],
                    'kind' => match (true) {
                        $state['is_published'] ?? false => 'published',
                        $state['is_initial'] ?? false => 'initial',
                        $state['is_terminal'] ?? false => 'terminal',
                        default => 'middle',
                    },
                ], $template['states']),
                'forward' => $moves['forward'],
                'back' => $moves['back'],
            ];
        }

        return $cards;
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
     * Total, writer and pending tallies per section in ONE grouped query (§6 flat, not per-row):
     * a post belongs to the section whose taxonomy is its primary category (pivot position 0),
     * mirroring the list screen's scope so both surfaces always report the same numbers.
     *
     * @param  Collection<int, ContentSection>  $sections
     * @return array<int|string, \stdClass> rows of {taxonomy_id, total, writers, pending} keyed by taxonomy_id
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
            ->selectRaw("entry_taxonomies.taxonomy_id, count(*) as total, count(distinct entries.author_id) as writers, count(*) filter (where entries.status = 'pending') as pending")
            ->groupBy('entry_taxonomies.taxonomy_id')
            ->toBase()
            ->get()
            ->keyBy('taxonomy_id')
            ->all();
    }

    /**
     * Clearing the stage pointer of a section's in-flight posts: after a template SWAP they re-enter
     * at the new flow's first stage (the engine treats null as the initial stage); after "no
     * approval" they simply hold no stage. Their public status is untouched, so nothing appears or
     * disappears from the storefront.
     */
    private function restartInFlightEntries(ContentSection $section): void
    {
        // Membership follows SectionResolver, which walks a category's ANCESTORS — a post filed
        // under a sub-category belongs to this section just as much as one filed at the root.
        // Matching only the bound taxonomy left those posts holding a stage the rebuilt flow no
        // longer defines: invisible in every review inbox, and stuck for their author.
        $taxonomyIds = $this->sectionTaxonomyIds($section);
        if ($taxonomyIds === []) {
            return;
        }

        Entry::query()
            ->whereNotNull('workflow_state')
            ->whereHas('taxonomies', function ($taxonomy) use ($taxonomyIds): void {
                $taxonomy->whereIn('taxonomies.id', $taxonomyIds)
                    ->where('entry_taxonomies.position', 0);
            })
            ->update(['workflow_state' => null]);
    }

    /**
     * The section's own category plus every descendant of it.
     *
     * @return list<int>
     */
    private function sectionTaxonomyIds(ContentSection $section): array
    {
        if ($section->taxonomy_id === null) {
            return [];
        }

        $ids = [(int) $section->taxonomy_id];
        $frontier = $ids;

        // Breadth-first over parent_id. Category trees are shallow (ltree, a handful of levels),
        // so this is a few small queries rather than a recursive CTE.
        while ($frontier !== []) {
            $children = Taxonomy::query()->whereIn('parent_id', $frontier)->get()->map(fn (Taxonomy $term): int => (int) $term->id)->all();
            $frontier = array_values(array_diff($children, $ids));
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }
}
