<?php

namespace App\Modules\Admin\Livewire\Entries;

use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Exceptions\EntryNotGovernedException;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Exceptions\InvalidTransitionException;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowState;
use App\Modules\Workflow\Services\ReviewQueue;
use App\Modules\Workflow\Services\WorkflowEngine;
use App\Modules\Workflow\Services\WorkflowTrail;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "Hộp duyệt" — the review inbox (design_handoff_workflow screen 3): a list of what is waiting
 * for this person on the left, the post they picked on the right, and one set of approval
 * buttons at the foot of the reader.
 *
 * This is the surface for people who APPROVE, which is why it never touches the editor's write
 * path: it reads a post and applies a transition, so `entries.review`/`entries.approve` is all
 * it asks for (EntryPolicy::review). An approve-only role can do its whole job here without the
 * right to edit a single word — the separation the `editorial` template exists to express.
 *
 * Which posts are waiting is a question about STAGES, answered by ReviewQueue: one `whereIn`
 * over entries rather than a per-row walk.
 */
#[Layout('admin::layouts.app')]
final class ReviewInbox extends Component
{
    use AuthorizesRequests;

    /** Rows added per "Tải thêm" (design: +5). */
    private const int PAGE = 5;

    /** Trail rows shown inside the reader. */
    private const int TRAIL_PAGE = 3;

    #[Url(as: 'tab', except: 'queue')]
    public string $tab = 'queue';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'type', except: '')]
    public string $sectionFilter = '';

    /** The post open in the reader (ulid — never a raw id). */
    public ?string $current = null;

    /** Reason for a transition that demands one. */
    public string $note = '';

    /** How many rows the list shows; grows by PAGE (see listQuery for why not a keyset cursor). */
    #[Locked]
    public int $shown = self::PAGE;

    /**
     * Per-request caches. The list resolves a workflow, its stages and its section label for
     * every row; without these each row costs its own queries and the inbox scales with the
     * size of the queue instead of staying flat (Query-Budget test).
     *
     * @var EloquentCollection<string, Workflow>|null
     */
    protected ?EloquentCollection $workflowsByType = null;

    /** @var array<string, string>|null */
    protected ?array $sectionOptions = null;

    public function boot(): void
    {
        // The livewire/update endpoint bypasses route middleware, so the gate is re-checked on
        // mount AND on every action.
        $this->authorize('review', Entry::class);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['queue', 'mine'], true) ? $tab : 'queue';
        $this->resetList();
    }

    public function updatedSearch(): void
    {
        $this->resetList();
    }

    public function updatedSectionFilter(): void
    {
        $this->resetList();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->sectionFilter = '';
        $this->resetList();
    }

    /** Open a post in the reader (only one that is actually in this person's queue). */
    public function open(string $ulid): void
    {
        $this->current = $this->waiting()->firstWhere('ulid', $ulid)?->ulid;
        $this->note = '';
        $this->resetErrorBag();
    }

    public function closeReader(): void
    {
        $this->current = null;
        $this->note = '';
    }

    public function loadMore(): void
    {
        $this->shown += self::PAGE;
    }

    /**
     * Apply a transition to the open post. The engine owns the rules; this only decides where
     * the failure is shown and moves the reader on to the next piece of work.
     */
    public function apply(string $key): void
    {
        $this->resetErrorBag();

        $entry = $this->currentEntry();
        if (! $entry instanceof Entry) {
            $this->addError('inbox', __('admin.inbox.err_gone'));

            return;
        }

        $workflow = app(EntryWorkflowResolver::class)->forEntry($entry);
        $transition = $workflow?->transitions()->where('key', $key)->first();
        if ($workflow === null || $transition === null) {
            $this->addError('inbox', __('admin.inbox.err_stale'));

            return;
        }

        $note = trim($this->note);
        if ($transition->requires_note && $note === '') {
            $this->addError('note', __('admin.wfb.note_missing'));

            return;
        }

        try {
            app(ApplyEntryWorkflowTransition::class)->handle(
                $entry, app(SiteLocale::class)->default(), $this->actor(), $key, $note !== '' ? $note : null,
            );
        } catch (InvalidTransitionException) {
            $this->addError('inbox', __('admin.inbox.err_stale'));

            return;
        } catch (AuthorizationException) {
            $this->addError('inbox', __('admin.wfb.err_forbidden'));

            return;
        } catch (RouteConflictException) {
            $this->addError('inbox', __('admin.inbox.err_slug'));

            return;
        } catch (EntryNotGovernedException) {
            $this->addError('inbox', __('admin.wfb.err_not_governed'));

            return;
        }

        $this->note = '';
        $this->dispatch('inbox-moved');
        $this->dispatch('bs-toast', text: __('admin.inbox.moved', ['action' => $transition->label]));

        // Handled — move to the next one waiting so a reviewer can work straight down the list.
        $this->current = $this->waiting()->firstWhere('ulid', '!=', $this->current)?->ulid;
    }

    public function render(): View
    {
        $waiting = $this->waiting();
        $entry = $this->currentEntry();

        $view = view('admin::livewire.entries.review-inbox', [
            'rows' => $this->rows($waiting->take($this->shown)),
            'total' => $waiting->count(),
            'mineCount' => $this->mineCount($waiting),
            'sections' => $this->sectionOptions(),
            'reader' => $entry instanceof Entry ? $this->reader($entry) : null,
        ]);
        $view->title(__('admin.inbox.title'));

        return $view;
    }

    /**
     * Everything waiting for this actor, newest movement first.
     *
     * The list is a QUEUE, not an archive: it only ever holds posts parked mid-flow, so it is
     * small and bounded by how fast a team works through it. That is why "Tải thêm" grows a
     * limit instead of carrying a keyset cursor — re-reading a few dozen rows costs nothing and
     * the list can never skip or repeat an item while people are moving posts through it. If a
     * site ever lets this grow into the thousands, that is the signal to switch to a cursor.
     *
     * @return EloquentCollection<int, Entry>
     */
    private function waiting(): EloquentCollection
    {
        $stages = app(ReviewQueue::class)->actionableStageKeys($this->actor());
        if ($stages === []) {
            /** @var EloquentCollection<int, Entry> $empty */
            $empty = Entry::query()->whereRaw('1 = 0')->get();

            return $empty;
        }

        $query = Entry::query()
            ->whereIn('workflow_state', $stages)
            ->with(['translations', 'author', 'taxonomies.translations'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($this->tab === 'mine') {
            $query->where('assigned_to', $this->actor()->id);
        }

        $entries = $query->get();

        // Search + type filter run in memory: the set is a queue (tens of rows), and both need
        // the localized title / resolved section that only the loaded relations can answer.
        $term = trim(mb_strtolower($this->search));
        $locale = app(SiteLocale::class)->default();

        return $entries->filter(function (Entry $entry) use ($term, $locale): bool {
            if ($this->sectionFilter !== '' && ($this->sectionKey($entry) ?? '') !== $this->sectionFilter) {
                return false;
            }

            if ($term === '') {
                return true;
            }

            $translation = $entry->translationFor($locale);
            $author = $entry->author;
            $title = mb_strtolower($translation instanceof EntryTranslation ? $translation->title : '');
            $authorName = mb_strtolower($author instanceof User ? $author->name : '');

            return str_contains($title, $term) || str_contains($authorName, $term);
        })->values();
    }

    /**
     * @param  EloquentCollection<int, Entry>  $waiting
     */
    private function mineCount(EloquentCollection $waiting): int
    {
        $id = $this->actor()->id;

        return $this->tab === 'mine'
            ? $waiting->count()
            : $waiting->filter(fn (Entry $entry): bool => $entry->assigned_to === $id)->count();
    }

    /**
     * List rows: title, stage, section, who sent it and how long it has waited.
     *
     * @param  Collection<int, Entry>  $entries
     * @return list<array{ulid: string, title: string, stage: string, section: string, author: string, waited_at: DateTimeInterface|null, late: bool, current: bool}>
     */
    private function rows(Collection $entries): array
    {
        $locale = app(SiteLocale::class)->default();
        $rows = [];

        foreach ($entries as $entry) {
            $workflow = $this->workflowFor($entry);
            $waited = $this->waitedAt($entry);
            $translation = $entry->translationFor($locale);

            $rows[] = [
                'ulid' => (string) $entry->ulid,
                'title' => $translation instanceof EntryTranslation && $translation->title !== '' ? $translation->title : (string) __('entries.unnamed'),
                'stage' => $this->stageLabel($workflow, $entry),
                'section' => $this->sectionLabel($entry),
                'author' => $this->authorName($entry),
                'waited_at' => $waited,
                // Design: more than three days waiting turns the line amber.
                // Design: past three days of waiting the line turns amber.
                'late' => $waited !== null && $waited->getTimestamp() < now()->subDays(3)->getTimestamp(),
                'current' => $entry->ulid === $this->current,
            ];
        }

        return $rows;
    }

    /**
     * The reader pane: the post itself, its stage, the moves this actor may make, and the trail.
     *
     * @return array{
     *     ulid: string, title: string, section: string, author: string, sent_at: DateTimeInterface|null,
     *     cover: string|null, blocks: list<array<array-key, mixed>>, excerpt: string|null,
     *     seo_title: string|null, seo_description: string|null, path: string|null,
     *     steps: list<array{key: string, label: string, is_published: bool, state: string}>,
     *     step_no: int, step_count: int, step_label: string, workflow: string,
     *     actions: list<array{key: string, label: string, to_label: string, to_published: bool, note: bool}>,
     *     trail: list<array{id: int, at: string, actor: string|null, transition: string, from: string, to: string, note: string|null}>,
     * }|null
     */
    private function reader(Entry $entry): ?array
    {
        $workflow = app(EntryWorkflowResolver::class)->forEntry($entry);
        if ($workflow === null) {
            return null;
        }

        $locale = app(SiteLocale::class)->default();
        $translation = $entry->translationFor($locale);
        $states = $workflow->states()->orderBy('sort_order')->orderBy('id')->get();
        $fromState = app(EntryWorkflowResolver::class)->originFor($workflow, $entry);
        $currentIndex = max(0, $states->search(fn (WorkflowState $state): bool => $state->key === $fromState) ?: 0);

        $steps = [];
        foreach ($states as $index => $state) {
            $steps[] = [
                'key' => $state->key,
                'label' => $state->label,
                'is_published' => $state->is_published,
                'state' => $index < $currentIndex ? 'done' : ($index === $currentIndex ? 'current' : 'todo'),
            ];
        }

        $actions = [];
        foreach (app(WorkflowEngine::class)->allowedTransitions($workflow, $fromState, $this->actor()) as $transition) {
            $destination = $states->firstWhere('key', $transition->to_state);
            $actions[] = [
                'key' => $transition->key,
                'label' => $transition->label,
                'to_label' => $destination instanceof WorkflowState ? $destination->label : $transition->to_state,
                'to_published' => $destination instanceof WorkflowState && $destination->is_published,
                'note' => $transition->requires_note,
            ];
        }

        $trail = app(WorkflowTrail::class)->forEntry($entry->id, self::TRAIL_PAGE);

        return [
            'ulid' => (string) $entry->ulid,
            'title' => $translation instanceof EntryTranslation && $translation->title !== '' ? $translation->title : (string) __('entries.unnamed'),
            'section' => $this->sectionLabel($entry),
            'author' => $this->authorName($entry),
            'sent_at' => $this->waitedAt($entry),
            'cover' => $this->coverUrl($entry),
            'blocks' => $this->readerBlocks($translation),
            'excerpt' => $translation?->excerpt,
            'seo_title' => $translation?->seo_title,
            'seo_description' => $translation?->seo_description,
            'path' => $this->publicPath($entry, $locale),
            'steps' => $steps,
            'step_no' => $currentIndex + 1,
            'step_count' => $states->count(),
            'step_label' => $states->get($currentIndex) instanceof WorkflowState ? $states->get($currentIndex)->label : $fromState,
            'workflow' => $workflow->name,
            'actions' => $actions,
            'trail' => array_map(static fn (array $row): array => [
                'id' => $row['id'],
                'at' => $row['at']->locale(app()->getLocale())->diffForHumans(),
                'actor' => $row['actor'],
                'transition' => $row['transition'],
                'from' => $row['from'],
                'to' => $row['to'],
                'note' => $row['note'],
            ], $trail['rows']),
        ];
    }

    private function coverUrl(Entry $entry): ?string
    {
        $key = data_get($entry->meta, 'image_url');

        return is_string($key) && $key !== '' ? app(MediaUrlResolver::class)->url($key, 640) : null;
    }

    /**
     * Every active workflow of this site, keyed by the content type it governs, with its stages
     * loaded — resolved ONCE per request and shared by every row.
     *
     * @return EloquentCollection<string, Workflow>
     */
    private function workflowsByType(): EloquentCollection
    {
        /** @var EloquentCollection<string, Workflow> $byType */
        $byType = $this->workflowsByType ??= Workflow::query()
            ->where('is_active', true)
            ->with('states')
            ->get()
            ->keyBy('applies_to_type');

        return $byType;
    }

    /** The workflow governing an entry, from the pre-loaded map (no query per row). */
    private function workflowFor(Entry $entry): ?Workflow
    {
        $key = $this->sectionKey($entry);

        return $key === null ? null : $this->workflowsByType()->get($key);
    }

    private function stageLabel(?Workflow $workflow, Entry $entry): string
    {
        if ($workflow === null) {
            return (string) $entry->workflow_state;
        }

        $key = app(EntryWorkflowResolver::class)->originFor($workflow, $entry);

        $state = $workflow->states->firstWhere('key', $key);

        return $state instanceof WorkflowState ? $state->label : $key;
    }

    private function sectionKey(Entry $entry): ?string
    {
        return app(SectionResolver::class)->forEntry($entry)['key'] ?? null;
    }

    private function sectionLabel(Entry $entry): string
    {
        $key = $this->sectionKey($entry);

        return $key === null ? '' : $this->sectionOptions()[$key] ?? $key;
    }

    /**
     * Governed content types, for the filter — labelled as the admin nav labels them.
     *
     * @return array<string, string>
     */
    private function sectionOptions(): array
    {
        if ($this->sectionOptions !== null) {
            return $this->sectionOptions;
        }

        $keys = array_keys(app(ReviewQueue::class)->actionableStages($this->actor()));
        if ($keys === []) {
            return $this->sectionOptions = [];
        }

        return $this->sectionOptions = ContentSection::query()
            ->whereIn('key', $keys)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (ContentSection $section): array => [$section->key => __('section.'.$section->key) === 'section.'.$section->key ? $section->label : __('section.'.$section->key)])
            ->all();
    }

    /**
     * The post's blocks, narrowed to the shape the reader renders (a malformed row is skipped
     * rather than crashing a reviewer's screen).
     *
     * @return list<array<array-key, mixed>>
     */
    private function readerBlocks(?EntryTranslation $translation): array
    {
        $raw = $translation instanceof EntryTranslation ? ($translation->body['blocks'] ?? null) : null;
        if (! is_array($raw)) {
            return [];
        }

        $blocks = [];
        foreach ($raw as $block) {
            if (is_array($block)) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /** The post's canonical path for this locale, when it already has one. */
    private function publicPath(Entry $entry, string $locale): ?string
    {
        $path = $entry->routes()->where('locale', $locale)->value('path');

        return is_string($path) ? $path : null;
    }

    /** When the post last moved — rendered as relative time by the view. */
    private function waitedAt(Entry $entry): ?DateTimeInterface
    {
        $at = $entry->updated_at;

        return $at instanceof DateTimeInterface ? $at : null;
    }

    /** The person who wrote it, or a plain line when that account is gone (author_id is SET NULL). */
    private function authorName(Entry $entry): string
    {
        $author = $entry->author;

        return $author instanceof User ? $author->name : __('admin.wfb.actor_gone');
    }

    private function currentEntry(): ?Entry
    {
        return $this->current === null ? null : $this->waiting()->firstWhere('ulid', $this->current);
    }

    private function resetList(): void
    {
        $this->shown = self::PAGE;
        $this->current = null;
    }

    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
