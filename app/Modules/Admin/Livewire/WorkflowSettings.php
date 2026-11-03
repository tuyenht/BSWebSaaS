<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\WorkflowChangedMeanwhile;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Shared\Support\Cast;
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

    /**
     * Why the last save was refused. Held across requests (a validation bag is gone after the next
     * round trip) until a save goes through or the member closes it.
     */
    #[Locked]
    public string $saveError = '';

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
     * Apply the staged changes: a list of {key, template, active, from}, where template null means
     * "no approval — publish directly" and `from` is the saved {template, active} the page showed
     * for that section. The whole batch is validated before anything is written and applied in one
     * transaction, so one bad entry leaves every section as it was.
     *
     * Saves of one site queue on one lock, and each section's rows are locked and compared with
     * `from` before its change is applied: a change made on a page someone else has saved over
     * since (including a first save that raced another first save) refuses the whole batch
     * instead of overwriting the newer flow.
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
        $templateKeys = WorkflowTemplateRegistry::keys();

        try {
            /** @var array{changes: list<array{key: string, template: string|null, active: bool, from: array{template: string|null, active: bool}}>} $valid */
            $valid = Validator::make(['changes' => $changes], [
                'changes' => ['present', 'array', 'list', 'max:'.self::MAX_CHANGES],
                'changes.*' => ['array:key,template,active,from'],
                'changes.*.key' => ['required', 'string', 'distinct', Rule::in($sections->keys()->all())],
                'changes.*.template' => ['present', 'nullable', 'string', Rule::in($templateKeys)],
                'changes.*.active' => ['required', 'boolean'],
                'changes.*.from' => ['required', 'array:template,active'],
                'changes.*.from.template' => ['present', 'nullable', 'string', Rule::in($templateKeys)],
                'changes.*.from.active' => ['required', 'boolean'],
            ])->validate();
        } catch (ValidationException) {
            // The page only ever sends well-formed changes for the sections it lists; anything else
            // is a stale page (a section removed meanwhile) or a crafted request.
            return $this->refuse(__('admin.wf.save_invalid'));
        }

        $batch = $valid['changes'];

        // `nullable` skips every other rule for a blank string, so "" would pass Rule::in and only
        // fail later, inside the template builder.
        foreach ($batch as $change) {
            foreach ([$change['template'], $change['from']['template']] as $template) {
                if ($template !== null && ! in_array($template, $templateKeys, true)) {
                    return $this->refuse(__('admin.wf.save_invalid'));
                }
            }
        }

        // The plan lock is enforced here, whatever the page showed: a crafted request for the
        // editorial flow on a site without the feature is refused like the locked card.
        $wantsEditorial = array_filter($batch, fn (array $change): bool => $change['template'] === 'editorial');
        if ($wantsEditorial !== [] && ! app(Entitlement::class)->featureEnabled('workflow.editorial')) {
            return $this->refuse(__('admin.wf.locked_error'));
        }

        try {
            DB::transaction(function () use ($batch, $sections): void {
                // One queue per site: a section's row may not exist yet, and a missing row cannot be
                // locked, so two first saves would otherwise both pass the check below.
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['workflows:'.app(CurrentSite::class)->id()]);

                $workflows = Workflow::query()
                    ->whereIn('applies_to_type', array_column($batch, 'key'))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('applies_to_type');

                foreach ($batch as $change) {
                    /** @var ContentSection $section */
                    $section = $sections->get($change['key']);
                    /** @var Workflow|null $workflow */
                    $workflow = $workflows->get($change['key']);

                    if ($this->savedState($workflow) !== ['template' => $change['from']['template'], 'active' => (bool) $change['from']['active']]) {
                        throw WorkflowChangedMeanwhile::forSection($section->localizedLabel());
                    }

                    $this->applyChange($section, $workflow, $change['template'], (bool) $change['active']);
                }
            });
        } catch (WorkflowChangedMeanwhile $stale) {
            return $this->refuse($stale->getMessage());
        }

        $this->saveError = '';
        $this->showToast(__('admin.wf.saved'));

        return true;
    }

    /** Close the message of a refused save. */
    public function dismissSaveError(): void
    {
        $this->saveError = '';
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
        $movable = $this->movableCounts($sections);
        $locale = app()->getLocale();
        $templates = WorkflowTemplateRegistry::localized($locale);

        $rows = $sections->map(function (ContentSection $section) use ($workflows, $counts, $movable): array {
            /** @var Workflow|null $workflow */
            $workflow = $workflows->get($section->key);
            $tally = $counts[(int) $section->taxonomy_id] ?? null;
            $saved = $this->savedState($workflow);

            return [
                'key' => $section->key,
                'label' => $section->localizedLabel(),
                'icon' => $section->icon,
                'total' => is_numeric($tally?->total) ? (int) $tally->total : 0,
                'writers' => is_numeric($tally?->writers) ? (int) $tally->writers : 0,
                'pending' => is_numeric($tally?->pending) ? (int) $tally->pending : 0,
                'movable' => $movable[(int) $section->taxonomy_id] ?? 0,
                'template' => $saved['template'],
                'workflow_name' => $saved['template'] !== null ? $workflow?->name : null,
                'active' => $saved['active'],
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

    /**
     * What a section's saved workflow amounts to, as the page shows it: 'none' is a workflow taken
     * out of service (kept for its history, never governing), so it reads as no flow, and so does
     * a template this build no longer offers.
     *
     * @return array{template: string|null, active: bool}
     */
    private function savedState(?Workflow $workflow): array
    {
        $template = $workflow !== null && in_array($workflow->template, WorkflowTemplateRegistry::keys(), true) ? $workflow->template : null;

        return ['template' => $template, 'active' => $template !== null && (bool) $workflow->is_active];
    }

    private function refuse(string $message): bool
    {
        $this->saveError = $message;

        return false;
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
     * @return array{key: string, name: string, tenant_ulid: string|null, plan: string|null}
     */
    private function siteSummary(): array
    {
        $row = Site::query()
            ->whereKey(app(CurrentSite::class)->id())
            ->leftJoin('tenants', 'sites.tenant_id', '=', 'tenants.id')
            ->leftJoin('plans', 'tenants.plan_id', '=', 'plans.id')
            ->toBase()
            ->first(['sites.ulid as site_ulid', 'sites.name as site_name', 'tenants.ulid as tenant_ulid', 'plans.name as plan_name']);

        return [
            // the page keeps unsaved picks per site in the browser under this public key
            'key' => is_string($row?->site_ulid) ? $row->site_ulid : '',
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
     * How many posts each section's change would move, keyed by the section's taxonomy_id, in ONE
     * query for every section: the posts restartInFlightEntries() releases — those holding a stage
     * of their own, or whose pending change does — so a confirm never names fewer than it moves.
     * The list's own counts (sectionCounts) stay on the section's category only.
     *
     * @param  Collection<int, ContentSection>  $sections
     * @return array<int, int>
     */
    private function movableCounts(Collection $sections): array
    {
        $rootIds = array_values(array_unique(array_map(Cast::int(...), $sections->pluck('taxonomy_id')->filter()->all())));
        if ($rootIds === []) {
            return [];
        }

        $site = app(CurrentSite::class)->id();
        [$branch, $bindings] = $this->branchSql($rootIds);

        $rows = DB::select($branch.<<<'SQL'
            SELECT branch.root_id, count(*) AS movable
            FROM branch
            JOIN entry_taxonomies AS et ON et.site_id = ? AND et.taxonomy_id = branch.id AND et.position = 0
            JOIN entries AS e ON e.site_id = ? AND e.id = et.entry_id AND e.deleted_at IS NULL
            LEFT JOIN entry_drafts AS d ON d.site_id = ? AND d.entry_id = e.id
            WHERE NOT branch.looped AND (e.workflow_state IS NOT NULL OR d.workflow_state IS NOT NULL)
            GROUP BY branch.root_id
            SQL, [...$bindings, $site, $site, $site]);

        $counts = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $counts[Cast::int($row['root_id'] ?? null)] = Cast::int($row['movable'] ?? null);
        }

        return $counts;
    }

    /**
     * Clearing the stage pointer of a section's in-flight posts and of their pending changes: after
     * a template SWAP they re-enter at the new flow's first stage (the engine treats null as the
     * initial stage); after "no approval" they simply hold no stage. A pointer left behind is only
     * ignored while the new flow lacks that stage — a stage both flows define (`pending`) would keep
     * a post or a change at a step it never reached in the new flow. Their public status is
     * untouched, so nothing appears or disappears from the storefront.
     */
    private function restartInFlightEntries(ContentSection $section): void
    {
        if ($section->taxonomy_id === null) {
            return;
        }

        [$branch, $bindings] = $this->branchSql([(int) $section->taxonomy_id]);
        $members = $branch.<<<'SQL'
            SELECT et.entry_id
            FROM branch
            JOIN entry_taxonomies AS et ON et.site_id = ? AND et.taxonomy_id = branch.id AND et.position = 0
            WHERE NOT branch.looped
            SQL;
        $bindings[] = app(CurrentSite::class)->id();

        Entry::query()
            ->whereNotNull('workflow_state')
            ->whereRaw("entries.id IN ({$members})", $bindings)
            ->update(['workflow_state' => null]);

        EntryDraft::query()
            ->whereNotNull('workflow_state')
            ->whereRaw("entry_drafts.entry_id IN ({$members})", $bindings)
            ->update(['workflow_state' => null]);
    }

    /**
     * The categories each section files posts under, as a recursive CTE `branch (root_id, id)`: a
     * section's own category and every category below it, down the parent chain (the source of
     * truth — the ltree path may not be written yet). Membership follows SectionResolver, where
     * the NEAREST bound category wins, so the walk stops at a sub-category that another section is
     * built on: its posts belong to that section. The CYCLE clause ends a parent loop.
     *
     * @param  non-empty-list<int>  $rootIds
     * @return array{0: literal-string, 1: list<int>}
     */
    private function branchSql(array $rootIds): array
    {
        $site = app(CurrentSite::class)->id();
        /** @var literal-string $marks placeholders only; the ids go in the bindings */
        $marks = implode(', ', array_fill(0, count($rootIds), '?'));

        $sql = <<<SQL
            WITH RECURSIVE branch (root_id, id) AS (
                SELECT t.id, t.id FROM taxonomies AS t WHERE t.site_id = ? AND t.id IN ({$marks})
                UNION ALL
                SELECT branch.root_id, child.id
                FROM taxonomies AS child JOIN branch ON child.parent_id = branch.id
                WHERE child.site_id = ?
                  AND NOT EXISTS (SELECT 1 FROM content_sections AS cs WHERE cs.site_id = ? AND cs.taxonomy_id = child.id)
            ) CYCLE id SET looped USING trail

            SQL;

        return [$sql, [$site, ...$rootIds, $site, $site]];
    }
}
