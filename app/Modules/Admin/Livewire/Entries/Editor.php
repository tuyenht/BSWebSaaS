<?php

namespace App\Modules\Admin\Livewire\Entries;

use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\EntryNotGovernedException;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Exceptions\WorkflowGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Support\EntryBlockMediaReconciler;
use App\Modules\Cms\Support\EntryCategoryWriter;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Cms\Validation\EntryContentRules;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaLibraryUrls;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\Slugger;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SchemaImages;
use App\Modules\Workflow\Exceptions\InvalidTransitionException;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowState;
use App\Modules\Workflow\Models\WorkflowTransition;
use App\Modules\Workflow\Services\WorkflowEngine;
use App\Modules\Workflow\Services\WorkflowTrail;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The content editor: a block-list builder over the shared ComponentRegistry (add /
 * reorder / remove / edit props). It serves both entry types — a `page` (flat URL) and
 * a `post` (URL prefixed by its primary category, so posts need a category picker). The
 * type is resolved server-side (from the content_section on create, from the entry on
 * edit) and #[Locked] so the client can't flip it. Save persists a draft + its Page-JSON
 * body (validated against the registry allow-list — ValidPageJson, D1: posts reuse the
 * block-list); Publish runs the Tầng-1 workflow (PublishEntry). Every mutation authorizes
 * through EntryPolicy. The entry is addressed by ulid, never its raw id.
 *
 * Where an ACTIVE workflow governs the post's section (design.md §15.4 tier-2), the Publish
 * button gives way to the "Trạng thái duyệt" block: the flow's stages, the transitions the
 * engine allows this user, a mandatory-note hand-off, and the approval trail. Which flow
 * applies follows the PICKED category — so an unsaved post already shows the right block and
 * a transition persists it first, the same way Publish does.
 */
#[Layout('admin::layouts.app')]
final class Editor extends Component
{
    use AuthorizesRequests;

    /** How many trail rows the block shows before "Xem thêm" (design: 3, +3 per load). */
    private const int TRAIL_PAGE = 3;

    /** The states a translation row may hold (entry_translations_status_check). */
    private const array TRANSLATION_STATES = ['draft', 'pending', 'published', 'archived'];

    /**
     * Cap on the "Giao cho" list. It rides inside the Livewire snapshot of every governed
     * render, so an unbounded team would grow the payload without bound (ADR-18: paginate
     * everything). A site whose approving team is larger than this needs a picker with search,
     * not a longer <select>.
     */
    private const int ASSIGNEE_LIMIT = 50;

    /** Which entry is being edited — server-resolved (mount/persist); never client-set. */
    #[Locked]
    public ?string $ulid = null;

    /** Entry type (page|post); server-resolved, never client-set. */
    #[Locked]
    public string $type = 'page';

    /** Server-resolved from the site's default locale; never client-set. */
    #[Locked]
    public string $locale = 'vi';

    public string $title = '';

    public string $slug = '';

    /** Primary category id (posts only; index 0 of entry_taxonomies drives the URL). */
    public ?int $categoryId = null;

    /** Featured/OG image — the picked media's storage_key, persisted to entry.meta['image_url']. */
    public string $coverImageKey = '';

    /** Whether the library picker is open (its media grid is only queried while true). */
    public bool $showMediaPicker = false;

    /** Block image picker: the target prop path inside $blocks (e.g. "0.props.image_url" or
     *  "0.props.logos.1.image_url"), held while the picker is open for a block image field. */
    public bool $showBlockImagePicker = false;

    public string $blockImagePickerPath = '';

    /** @var list<array<string, mixed>> */
    public array $blocks = [];

    #[Locked]
    public ?string $status = null;

    /** Reason for a return/retract — transitions flagged requires_note refuse without one. */
    public string $transitionNote = '';

    /** Member (users.id) to hand the post to on a note transition; must be able to act at the next stage. */
    public ?int $assignTo = null;

    /**
     * Approval trail rows shown so far (newest first) + the keyset cursor for the next page and
     * the per-entry total — held in state so "Xem thêm" appends instead of re-reading. Server
     * data, so #[Locked]: the client pages it, never edits it.
     *
     * @var list<array{id: int, at: string, actor: string|null, transition: string, from: string, to: string, note: string|null}>
     */
    #[Locked]
    public array $trail = [];

    #[Locked]
    public ?string $trailCursor = null;

    #[Locked]
    public int $trailTotal = 0;

    /**
     * Per-request memo of hand-off candidates by destination stage and entry (protected = not Livewire state).
     *
     * @var array<string, array<int, array{id: int, name: string}>> keyed "{workflow}:{stage}:{entry}"
     */
    protected array $memberMemo = [];

    /** Per-request memo of the governing workflow + the category it was resolved for. */
    protected ?Workflow $workflowMemo = null;

    protected ?int $workflowMemoFor = null;

    public function mount(?string $ulid = null, ?string $section = null, ?string $locale = null): void
    {
        $this->locale = $this->resolveLocale(app(SiteLocale::class), $locale ?? request()->query('locale'));

        if ($ulid === null) {
            $this->authorize('create', Entry::class);

            // New entry: take the type (and a default category for posts) from the
            // content_section the admin came from (?section=…); default to a page.
            $contentSection = $this->resolveSection($section ?? request()->query('section'));
            $this->type = $contentSection instanceof ContentSection ? ($contentSection->entry_type ?? 'page') : 'page';
            $this->categoryId = $this->type === 'post' && $contentSection instanceof ContentSection && $contentSection->taxonomy_id !== null
                ? (int) $contentSection->taxonomy_id
                : null;

            return;
        }

        $entry = $this->resolveOrFail($ulid);
        $this->authorize('update', $entry);

        $this->ulid = $entry->ulid;
        $this->type = $entry->type;
        $this->status = $entry->status;

        $translation = $entry->translationFor($this->locale);
        if ($translation instanceof EntryTranslation) {
            $this->title = $translation->title;
            $this->slug = $translation->slug;
            $this->blocks = $this->normalizeBlocks($translation->body['blocks'] ?? []);
        }

        $cover = data_get($entry->meta, 'image_url');
        $this->coverImageKey = is_string($cover) ? $cover : '';

        if ($this->type === 'post') {
            $this->categoryId = $entry->primaryCategory()?->id;
        }

        if ($this->governingWorkflow() !== null) {
            $this->loadTrail($entry->id);
        }
    }

    /**
     * The locale to edit — a `?locale=` query param when it is one of the site's enabled
     * content-locales (the Pages list's per-language "Sửa"/"Thêm" links carry it), else the site
     * default. #[Locked] $locale then pins it for the component's lifetime so the client can't
     * flip which translation is being written.
     */
    private function resolveLocale(SiteLocale $siteLocale, mixed $requested): string
    {
        if (is_string($requested) && in_array($requested, $siteLocale->enabled(), true)) {
            return $requested;
        }

        return $siteLocale->default();
    }

    public function addBlock(string $type): void
    {
        if (! app(ComponentRegistry::class)->has($type)) {
            $this->addError('blocks', __('entries.block_unsupported', ['type' => $type]));

            return;
        }

        $this->resetErrorBag('blocks');
        $this->blocks[] = ['type' => $type, 'props' => $this->defaultProps($type)];
    }

    public function removeBlock(int $index): void
    {
        if (! array_key_exists($index, $this->blocks)) {
            return;
        }

        $blocks = $this->blocks;
        array_splice($blocks, $index, 1);
        $this->blocks = $blocks;
    }

    public function moveUp(int $index): void
    {
        if ($index <= 0 || ! array_key_exists($index, $this->blocks)) {
            return;
        }

        $blocks = $this->blocks;
        [$blocks[$index - 1], $blocks[$index]] = [$blocks[$index], $blocks[$index - 1]];
        $this->blocks = $blocks;
    }

    public function moveDown(int $index): void
    {
        if (! array_key_exists($index + 1, $this->blocks)) {
            return;
        }

        $blocks = $this->blocks;
        [$blocks[$index], $blocks[$index + 1]] = [$blocks[$index + 1], $blocks[$index]];
        $this->blocks = $blocks;
    }

    /** Append a blank item (shaped by the list's `of` schema) to a list-typed prop. */
    public function addItem(int $index, string $key): void
    {
        $list = $this->resolveList($index, $key);
        if ($list === null) {
            return;
        }

        // Clear a stale save-time error so it doesn't linger over valid edits (mirrors addBlock).
        $this->resetErrorBag('blocks');
        $items = $list['items'];
        $items[] = $list['blank'];
        $this->putItems($index, $key, $items);
    }

    public function removeItem(int $index, string $key, int $itemIndex): void
    {
        $list = $this->resolveList($index, $key);
        if ($list === null || ! array_key_exists($itemIndex, $list['items'])) {
            return;
        }

        $items = $list['items'];
        array_splice($items, $itemIndex, 1);
        $this->putItems($index, $key, $items);
    }

    public function moveItemUp(int $index, string $key, int $itemIndex): void
    {
        $this->swapItems($index, $key, $itemIndex, $itemIndex - 1);
    }

    public function moveItemDown(int $index, string $key, int $itemIndex): void
    {
        $this->swapItems($index, $key, $itemIndex, $itemIndex + 1);
    }

    public function save(): void
    {
        $this->persist();
        session()->flash('saved', true);
    }

    public function publish(): void
    {
        $entry = $this->persist();

        try {
            $this->status = app(PublishEntry::class)->handle($entry, $this->locale, $this->user());
        } catch (RouteConflictException) {
            $this->addError('slug', __('entries.slug_conflict'));
        } catch (WorkflowGovernedException) {
            // A tab that still shows the tier-1 button after the flow was switched on: the
            // draft is saved, and the next render swaps in the approval block.
            $this->addError('workflow', __('entries.workflow_governed'));
        }
    }

    /**
     * Move the post along its workflow (design screen 2 action bar). Persists first, exactly
     * like publish(), so an unsaved post can be submitted in one move. The engine is the only
     * judge of the move — the pre-checks below just put the failure on the right field.
     */
    public function applyTransition(string $key): void
    {
        $this->resetErrorBag(['workflow', 'transitionNote', 'assignTo']);

        $entry = $this->persist();
        $this->status = $entry->status;

        $workflow = app(EntryWorkflowResolver::class)->forEntry($entry);
        if ($workflow === null) {
            $this->addError('workflow', __('admin.wfb.err_not_governed'));

            return;
        }

        $transition = $workflow->transitions()->where('key', $key)->first();
        if (! $transition instanceof WorkflowTransition) {
            $this->addError('workflow', __('admin.wfb.err_stale'));

            return;
        }

        $note = trim($this->transitionNote);
        if ($transition->requires_note && $note === '') {
            $this->addError('transitionNote', __('admin.wfb.note_missing'));

            return;
        }

        // A hand-off only exists on the note modal (design screen 2); a value left over from a
        // cancelled modal must not ride along on a plain move. And it may only name someone who
        // can act at the destination — never an arbitrary (or another site's) users.id from the
        // wire payload.
        $assignTo = $transition->requires_note ? $this->assignTo : null;
        if ($assignTo !== null && ! array_key_exists($assignTo, $this->assignableMembers($workflow, $transition->to_state, $entry))) {
            $this->addError('assignTo', __('admin.wfb.assignee_invalid'));

            return;
        }

        try {
            $destination = app(ApplyEntryWorkflowTransition::class)->handle(
                $entry, $this->locale, $this->user(), $key, $note !== '' ? $note : null, $assignTo,
            );
        } catch (RouteConflictException) {
            $this->addError('slug', __('entries.slug_conflict'));

            return;
        } catch (InvalidTransitionException) {
            // Another tab moved the post first, or the flow was reconfigured under this one.
            $this->addError('workflow', __('admin.wfb.err_stale'));

            return;
        } catch (AuthorizationException) {
            $this->addError('workflow', __('admin.wfb.err_forbidden'));

            return;
        } catch (EntryNotGovernedException) {
            // NOT a broad RuntimeException: QueryException is one too, and reporting a deadlock
            // as "no workflow here any more" would push the editor toward publishing directly.
            $this->addError('workflow', __('admin.wfb.err_not_governed'));

            return;
        }

        $entry->refresh();
        $this->status = $entry->status;
        $this->transitionNote = '';
        $this->assignTo = null;
        $this->loadTrail($entry->id);

        $stepLabel = $workflow->states()->where('key', $destination)->value('label');

        $this->dispatch('workflow-moved');   // closes the note modal only on success
        $this->dispatch('bs-toast', text: __('admin.wfb.moved', [
            'action' => $transition->label,
            'step' => is_string($stepLabel) ? $stepLabel : $destination,
        ]));
    }

    /** "Xem thêm": the next keyset page of the trail, appended below what is already shown. */
    public function loadMoreTrail(): void
    {
        if ($this->ulid === null || $this->trailCursor === null) {
            return;
        }

        // Re-authorize per request (livewire/update skips the route middleware): the trail
        // carries reviewers' notes, so it is read on the same terms as the entry itself.
        $entry = $this->resolveOrFail($this->ulid);
        $this->authorize('update', $entry);

        $page = app(WorkflowTrail::class)->forEntry($entry->id, self::TRAIL_PAGE, $this->trailCursor);
        $this->trail = array_merge($this->trail, $this->trailRows($page['rows']));
        $this->trailCursor = $page['cursor'];
        $this->trailTotal = $page['total'];
    }

    /** (Re)load the first page of the trail — after mount, and after every move. */
    private function loadTrail(int $entryId): void
    {
        $page = app(WorkflowTrail::class)->forEntry($entryId, self::TRAIL_PAGE);
        $this->trail = $this->trailRows($page['rows']);
        $this->trailCursor = $page['cursor'];
        $this->trailTotal = $page['total'];
    }

    /**
     * Trail rows as plain state: the timestamp becomes ISO-8601 so it survives the Livewire
     * snapshot (the view renders it relative, in the site's zone).
     *
     * @param  list<array{id: int, at: CarbonInterface, actor: string|null, transition: string, from: string, to: string, note: string|null}>  $rows
     * @return list<array{id: int, at: string, actor: string|null, transition: string, from: string, to: string, note: string|null}>
     */
    private function trailRows(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'at' => $row['at']->toIso8601String(),
            'actor' => $row['actor'],
            'transition' => $row['transition'],
            'from' => $row['from'],
            'to' => $row['to'],
            'note' => $row['note'],
        ], $rows);
    }

    /**
     * The active workflow governing the PICKED category's section, or null (tier-1). Memoized
     * per request (the component instance lives one request), keyed by the category so a
     * category change inside the same request is never answered from the previous pick.
     */
    private function governingWorkflow(): ?Workflow
    {
        if ($this->type !== 'post' || $this->categoryId === null) {
            return null;
        }

        if ($this->workflowMemoFor !== $this->categoryId) {
            $category = Taxonomy::query()->whereKey($this->categoryId)->first();
            $this->workflowMemo = $category instanceof Taxonomy ? app(EntryWorkflowResolver::class)->forCategory($category) : null;
            $this->workflowMemoFor = $this->categoryId;
        }

        return $this->workflowMemo;
    }

    /**
     * Everything the "Trạng thái duyệt" block renders — stages, the current one, the user's
     * transitions (with hand-off candidates for the note ones) and the hints around them. A
     * flat set of queries: states, allowed transitions, and ONE members lookup per distinct
     * destination that a note transition can reach.
     *
     * @return array{
     *     name: string,
     *     steps: list<array{key: string, label: string, is_published: bool, is_terminal: bool, is_initial: bool, state: 'done'|'current'|'todo'}>,
     *     current: array{index: int, label: string, is_published: bool},
     *     next_label: string|null,
     *     pre_workflow: bool,
     *     is_live: bool,
     *     assignee: string|null,
     *     missing: list<string>,
     *     actions: list<array{key: string, label: string, to_label: string, to_published: bool, note: bool, members: list<array{id: int, name: string}>}>,
     * }
     */
    private function workflowPanel(Workflow $workflow, ?Entry $entry): array
    {
        $states = $workflow->states()->orderBy('sort_order')->orderBy('id')->get()->keyBy('key');
        $initial = $states->first(fn (WorkflowState $state): bool => $state->is_initial);
        $initialKey = $initial instanceof WorkflowState ? $initial->key : (string) $states->keys()->first();

        // The origin the ENGINE will use decides which buttons exist (one definition, shared with
        // ApplyEntryWorkflowTransition); the drawn position falls back to the start when that key
        // is one the (rebuilt) flow no longer defines.
        $fromState = $entry !== null ? app(EntryWorkflowResolver::class)->originFor($workflow, $entry) : $initialKey;
        $currentKey = $states->has($fromState) ? $fromState : $initialKey;

        $keys = $states->keys()->values()->all();
        $currentIndex = max(0, (int) array_search($currentKey, $keys, true));

        $steps = [];
        foreach ($states->values() as $index => $state) {
            $steps[] = [
                'key' => $state->key,
                'label' => $state->label,
                'is_published' => $state->is_published,
                'is_terminal' => $state->is_terminal,
                'is_initial' => $state->is_initial,
                'state' => $index < $currentIndex ? 'done' : ($index === $currentIndex ? 'current' : 'todo'),
            ];
        }

        $current = $states->get($currentKey);
        $nextState = $states->values()->get($currentIndex + 1);

        // A post not saved yet will be this user's own once the move persists it, so it is judged
        // as theirs; the per-entry rule (EntryWorkflowResolver::mayApply) then hides a move the
        // action would refuse.
        $user = $this->user();
        $subject = $entry ?? new Entry(['author_id' => $user->id]);
        $resolver = app(EntryWorkflowResolver::class);

        $actions = [];
        foreach (app(WorkflowEngine::class)->allowedTransitions($workflow, $fromState, $user) as $transition) {
            if (! $resolver->mayApply($transition, $subject, $user)) {
                continue;
            }
            $destination = $states->get($transition->to_state);
            $actions[] = [
                'key' => $transition->key,
                'label' => $transition->label,
                'to_label' => $destination instanceof WorkflowState ? $destination->label : $transition->to_state,
                'to_published' => $destination instanceof WorkflowState && $destination->is_published,
                'note' => $transition->requires_note,
                'members' => $transition->requires_note
                    ? array_values($this->assignableMembers($workflow, $transition->to_state, $subject))
                    : [],
            ];
        }

        $assignee = $entry?->assigned_to !== null ? User::query()->whereKey($entry->assigned_to)->value('name') : null;

        return [
            'name' => $workflow->name,
            'steps' => $steps,
            'current' => [
                'index' => $currentIndex,
                'label' => $current instanceof WorkflowState ? $current->label : $currentKey,
                'is_published' => $current instanceof WorkflowState && $current->is_published,
            ],
            'next_label' => $nextState instanceof WorkflowState ? $nextState->label : null,
            // "Written before the flow existed, so it counts as at the start" — only true for
            // content that actually lands at the start; a live post simply stands at its stage.
            'pre_workflow' => $entry !== null && $entry->workflow_state === null && $currentKey === $initialKey,
            'is_live' => $entry?->status === 'published',
            'assignee' => is_string($assignee) ? $assignee : null,
            'missing' => $this->missingPublicFields(),
            'actions' => $actions,
        ];
    }

    /**
     * Members of THIS site who could move $entry on from $stateKey — the people a returned post
     * can be handed to. A member qualifies through at least one transition out of that stage:
     * its permission, and for a send (entries.submit) also the right to edit THIS entry, so a
     * post is never handed to someone the send would then refuse (EntryWorkflowResolver::
     * membersWhoMayApply, the SQL form of mayApply). Keyed by users.id for the validity check.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function assignableMembers(Workflow $workflow, string $stateKey, Entry $entry): array
    {
        // Memo on the instance (one per request) — never a static: under Octane a static would
        // outlive the request and hand one site's members to the next. The entry is part of the
        // key because who may send depends on its author; an unsaved post is the actor's own.
        $memoKey = $workflow->id.':'.$stateKey.':'.($entry->exists ? $entry->id : 'new:'.$entry->author_id);
        if (isset($this->memberMemo[$memoKey])) {
            return $this->memberMemo[$memoKey];
        }

        $query = app(EntryWorkflowResolver::class)
            ->membersWhoMayApply($workflow->transitions->where('from_state', $stateKey), $entry);

        if ($query === null) {
            return $this->memberMemo[$memoKey] = [];
        }

        // Filtered in SQL before the cap, so the cap never hides a member behind people who
        // could not take the post on.
        $members = [];
        foreach ($query->orderBy('name')->limit(self::ASSIGNEE_LIMIT)->get(['users.id', 'users.name']) as $member) {
            $members[(int) $member->id] = ['id' => (int) $member->id, 'name' => (string) $member->name];
        }

        return $this->memberMemo[$memoKey] = $members;
    }

    /**
     * Public-facing fields still empty on this post — said up front so the author fills them
     * before a reviewer has to send it back. Only what the storefront actually shows for a post.
     *
     * @return list<string>
     */
    private function missingPublicFields(): array
    {
        $missing = [];
        if ($this->coverImageKey === '') {
            $missing[] = __('admin.wfb.missing_cover');
        }
        if ($this->blocks === []) {
            $missing[] = __('admin.wfb.missing_body');
        }

        return $missing;
    }

    public function openCoverPicker(): void
    {
        abort_unless(Gate::allows('media.view'), 403);
        $this->showMediaPicker = true;
    }

    public function closeCoverPicker(): void
    {
        $this->showMediaPicker = false;
    }

    public function selectCover(string $key): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        // Only accept a storage_key from THIS site's library (RLS-scoped) — never an arbitrary or
        // cross-site string injected via the wire payload.
        if (Media::query()->where('storage_key', $key)->exists()) {
            $this->coverImageKey = $key;
        }

        $this->showMediaPicker = false;
    }

    public function clearCover(): void
    {
        $this->coverImageKey = '';
    }

    public function openBlockImagePicker(string $path): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        $this->blockImagePickerPath = $path;
        $this->showBlockImagePicker = true;
    }

    public function closeBlockImagePicker(): void
    {
        $this->showBlockImagePicker = false;
        $this->blockImagePickerPath = '';
    }

    /**
     * Set a block image prop to a media_id from THIS site's library (RLS-scoped) — never an
     * arbitrary id from the wire payload. Content references media by id (ADR-17), not a URL.
     */
    public function selectBlockImage(int $mediaId): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        if ($this->blockImagePickerPath !== '' && Media::query()->whereKey($mediaId)->exists()) {
            $this->setBlockProp($this->blockImagePickerPath, $mediaId);
        }

        $this->showBlockImagePicker = false;
        $this->blockImagePickerPath = '';
    }

    public function clearBlockImage(string $path): void
    {
        $this->setBlockProp($path, null);
    }

    /** Set a dotted prop path inside $blocks (e.g. "0.props.logos.1.image_url"), keeping the list type. */
    private function setBlockProp(string $path, mixed $value): void
    {
        $blocks = $this->blocks;
        data_set($blocks, $path, $value);
        /** @var list<array<string, mixed>> $blocks */
        $this->blocks = $blocks;
    }

    public function render(): View
    {
        $urls = app(MediaUrlResolver::class);
        $schemas = $this->schemas();
        $workflow = $this->governingWorkflow();

        return view('admin::livewire.entries.editor', [
            'availableTypes' => app(ComponentRegistry::class)->types(),
            'schemas' => $schemas,
            'categories' => $this->type === 'post' ? $this->availableCategories() : [],
            'urls' => $urls,
            'coverUrl' => $this->coverImageKey !== '' ? $urls->url($this->coverImageKey, 640) : null,
            'blockImageUrls' => $this->resolveBlockImageUrls($schemas),
            'pickerMedia' => $this->showMediaPicker || $this->showBlockImagePicker
                ? Media::query()->orderByDesc('created_at')->limit(60)->get()
                : collect(),
            'workflow' => $workflow !== null
                ? $this->workflowPanel($workflow, $this->ulid !== null ? $this->resolveOrFail($this->ulid) : null)
                : null,
        ]);
    }

    /**
     * Preview URLs for every block image prop, resolved in ONE query (a thumbnail sits next to each
     * picker). A media_id → its URL; unknown ids are simply absent.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $schemas
     * @return array<int, string>
     */
    private function resolveBlockImageUrls(array $schemas): array
    {
        $ids = [];
        foreach ($this->blocks as $block) {
            $type = $block['type'] ?? null;
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            if (is_string($type) && isset($schemas[$type])) {
                $ids = array_merge($ids, SchemaImages::ids($props, $schemas[$type]));
            }
        }

        return app(MediaLibraryUrls::class)->urlsForIds($ids);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = app(EntryContentRules::class)->rules();

        // A post's URL is its category slug + post slug, so a post must have a category.
        if ($this->type === 'post') {
            $rules['categoryId'] = [
                'required', 'integer',
                Rule::exists('taxonomies', 'id')->where('vocabulary', 'post_cat'),
                $this->keepsGovernedSection(...),
                $this->staysInCategoryLimits(...),
            ];
        }

        return $rules;
    }

    /**
     * A post an active workflow governs may not be re-filed out of that workflow's section.
     *
     * The gate asks "which workflow governs this entry?" AFTER the save has written the picked
     * category, and `categoryId` is client-owned — so without this rule, re-pointing the picker
     * at a category no section binds, pressing Publish, and re-pointing it back would take the
     * post public with no approval and no trail. Filing it under another category of the SAME
     * section (a sub-category, say) is ordinary editing and stays allowed.
     */
    private function keepsGovernedSection(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->ulid === null || ! is_numeric($value)) {
            return;   // a new post has nothing to escape from; `exists` reports a bad value
        }

        $workflows = app(EntryWorkflowResolver::class);
        $current = $workflows->forEntry($this->resolveOrFail($this->ulid));
        if ($current === null) {
            return;   // tier-1 content: filing is free
        }

        $target = Taxonomy::query()->whereKey((int) $value)->first();
        $destination = $target instanceof Taxonomy ? $workflows->forCategory($target) : null;

        if ($destination?->id !== $current->id) {
            $fail(__('entries.category_governed', ['workflow' => $current->name]));
        }
    }

    /**
     * A member limited to some categories files a post only inside them (EntryPolicy::fileUnder):
     * said on the field here; EntryCategoryWriter refuses the write itself whatever the form sends.
     */
    private function staysInCategoryLimits(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            return;   // `required` / `integer` report it
        }

        $response = Gate::inspect('fileUnder', [Entry::class, 'post', (int) $value]);
        if ($response->denied()) {
            $fail((string) $response->message());
        }
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            ...app(EntryContentRules::class)->messages(),
            'categoryId.required' => __('entries.category_required'),
            'categoryId.exists' => __('entries.category_invalid'),
        ];
    }

    /**
     * A blank link is made from the title (any writing system) and made free for this language: no
     * other page's link and no other route (a category, a product) may already hold the public path it would get, and a
     * flat page is never given `home`, which publishing turns into the site's root. A link the
     * person typed is never touched. A title with
     * nothing usable leaves it blank, so validation asks for the link. Returns whether it filled one.
     */
    private function fillBlankSlug(): bool
    {
        if (trim($this->slug) !== '') {
            return false;
        }

        $ownId = $this->ulid !== null ? $this->resolveOrFail($this->ulid)->id : null;
        $prefix = $this->publicPathPrefix();

        $this->slug = Slugger::fromTitle(
            $this->title,
            $this->locale,
            fn (string $base): array => [
                ...Slugger::takenAmong(
                    EntryTranslation::query()
                        ->where('locale', $this->locale)
                        ->when($ownId !== null, fn ($q) => $q->where('entry_id', '!=', $ownId)),
                    'slug',
                    $base,
                ),
                ...Slugger::takenAmong(
                    SiteRoute::query()
                        ->where('locale', $this->locale)
                        ->when($ownId !== null, fn ($q) => $q->where(
                            fn ($own) => $own->whereNull('entry_id')->orWhere('entry_id', '!=', $ownId),
                        )),
                    'path',
                    $base,
                    $prefix,
                ),
                ...($prefix === '/' ? [PathBuilder::HOME_SLUG] : []),
            ],
        );

        return $this->slug !== '';
    }

    /**
     * The part of the public path that comes before the slug, composed by PathBuilder for this
     * entry and its picked category exactly as publishing will ('/' for a flat page).
     */
    private function publicPathPrefix(): string
    {
        $entry = $this->ulid !== null ? $this->resolveOrFail($this->ulid) : new Entry(['type' => $this->type]);

        if ($this->type === 'post') {
            $category = $this->categoryId !== null
                ? Taxonomy::query()->with('translations')->find($this->categoryId)
                : null;
            $categories = new EloquentCollection;
            if ($category instanceof Taxonomy) {
                $category->setRelation('pivot', (new Pivot)->forceFill(['position' => 0]));
                $categories->push($category);
            }
            $entry->setRelation('taxonomies', $categories);
        }

        $path = app(PathBuilder::class)->forEntry($entry, $this->locale, 'x');

        return str_ends_with($path, 'x') ? substr($path, 0, -1) : '/';
    }

    /** Validate + persist the entry and its translation (+ category for posts); returns it. */
    private function persist(): Entry
    {
        $filled = $this->fillBlankSlug();
        try {
            $this->validate();
        } catch (ValidationException $e) {
            // A link made for a save that failed must not stay behind: it would pin the old title.
            if ($filled) {
                $this->slug = '';
            }

            throw $e;
        }

        if ($this->ulid === null) {
            $this->authorize('create', Entry::class);
            // Opening the editor needs only `create`; what is saved must lie inside the member's
            // category limits (a page has no category, so a limited member writes no page).
            $this->authorize('fileUnder', [Entry::class, $this->type, $this->type === 'post' ? $this->categoryId : null]);
            $entry = Entry::query()->create([
                'type' => $this->type,
                'status' => 'draft',
                'author_id' => $this->user()->id,
                'meta' => [], // a fresh instance's meta is null until set; [] keeps the merge below array-typed
            ]);
            $this->ulid = $entry->ulid;
            $this->status = $entry->status;
        } else {
            $entry = $this->resolveOrFail($this->ulid);
            $this->authorize('update', $entry);
        }

        // Featured/OG image: store the picked media's storage_key in entry.meta['image_url'] (the
        // storefront resolves it to an absolute og:image + card thumbnail). Merge so any other meta
        // key survives; an empty pick clears it.
        $meta = $entry->meta;
        if ($this->coverImageKey !== '') {
            $meta['image_url'] = $this->coverImageKey;
        } else {
            unset($meta['image_url']);
        }
        $entry->meta = $meta;
        $sharedChanged = $entry->isDirty('meta');
        $entry->save();

        $translation = $entry->translations()->firstOrNew(['locale' => $this->locale]);
        $translation->fill([
            'title' => $this->title,
            'slug' => $this->slug,
            'body' => ['blocks' => $this->blocks],
            'status' => $this->translationStatus($entry, $translation),
        ])->save();

        // Materialize block image usage into mediables so it surfaces in "where is this media used".
        app(EntryBlockMediaReconciler::class)->reconcile($entry);

        if ($this->type === 'post') {
            app(EntryCategoryWriter::class)->assign($entry, $this->categoryId !== null ? [$this->categoryId] : [], $this->user());
        }

        // A save on live content is what visitors now see: keep that version (skipped when unchanged).
        // A changed cover is shared by every language's page, so then every language is recorded.
        if ($entry->status === 'published') {
            $revisions = app(RecordEntryRevision::class);
            if ($sharedChanged) {
                $revisions->forAllLocales($entry, RevisionKind::Edit, $this->user());
            } else {
                $revisions->forLocale($entry, $translation, RevisionKind::Edit, $this->user());
            }
        }

        return $entry;
    }

    /**
     * The status to store on the saved translation. Translations take only draft · pending ·
     * published · archived (entry_translations_status_check); a scheduled or private entry keeps
     * the translation's current state — the same as TransitionEntryStatus, which never touches
     * translations — and a new translation starts as draft.
     */
    private function translationStatus(Entry $entry, EntryTranslation $translation): string
    {
        if (in_array($entry->status, self::TRANSLATION_STATES, true)) {
            return $entry->status;
        }

        return $translation->exists ? $translation->status : 'draft';
    }

    private function resolveSection(?string $key): ?ContentSection
    {
        if ($key === null || $key === '') {
            return null;
        }

        return ContentSection::query()->where('key', $key)->first();
    }

    /**
     * Post categories (post_cat) for the picker, mapped to id + localized name.
     *
     * @return list<array{id: int, name: string}>
     */
    private function availableCategories(): array
    {
        $categories = Taxonomy::query()
            ->where('vocabulary', 'post_cat')
            ->with('translations:id,taxonomy_id,locale,name,slug')
            ->get()
            ->map(function (Taxonomy $taxonomy): array {
                $translation = $taxonomy->translationFor($this->locale);

                return [
                    'id' => $taxonomy->id,
                    'name' => $translation instanceof TaxonomyTranslation ? $translation->name : __('entries.unnamed'),
                ];
            })
            ->all();

        return array_values($categories);
    }

    private function resolveOrFail(string $ulid): Entry
    {
        return Entry::query()->where('ulid', $ulid)->firstOrFail();
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * Resolve a list-typed prop: its current items (re-indexed) + a blank item template
     * shaped by the schema `of`. Returns null when #index.$key isn't an authorable list.
     *
     * @return array{items: array<int, mixed>, blank: mixed}|null
     */
    private function resolveList(int $index, string $key): ?array
    {
        if (! array_key_exists($index, $this->blocks)) {
            return null;
        }

        $type = $this->blocks[$index]['type'] ?? null;
        $registry = app(ComponentRegistry::class);
        if (! is_string($type) || ! $registry->has($type)) {
            return null;
        }

        $spec = $registry->get($type)->schema()[$key] ?? null;
        if (! is_array($spec) || ($spec['type'] ?? null) !== 'list') {
            return null;
        }

        $props = $this->blocks[$index]['props'] ?? null;
        $current = is_array($props) ? ($props[$key] ?? null) : null;

        return [
            'items' => is_array($current) ? array_values($current) : [],
            'blank' => $this->blankFromOf($spec['of'] ?? null),
        ];
    }

    /** A blank item value for a list's `of` schema (a scalar default, or a field map). */
    private function blankFromOf(mixed $of): mixed
    {
        if (! is_array($of)) {
            return '';
        }

        return isset($of['type']) && is_string($of['type'])
            ? $this->defaultForSpec($of)
            : $this->defaultsFromSchema($of);
    }

    /**
     * Write items back into a block's list prop (copy-narrow-reassign for static safety).
     *
     * @param  array<int, mixed>  $items
     */
    private function putItems(int $index, string $key, array $items): void
    {
        $blocks = $this->blocks;
        $block = $blocks[$index];
        $props = is_array($block['props'] ?? null) ? $block['props'] : [];
        $props[$key] = $items;
        $block['props'] = $props;
        $blocks[$index] = $block;
        $this->blocks = $blocks;
    }

    private function swapItems(int $index, string $key, int $a, int $b): void
    {
        $list = $this->resolveList($index, $key);
        if ($list === null || ! array_key_exists($a, $list['items']) || ! array_key_exists($b, $list['items'])) {
            return;
        }

        $items = $list['items'];
        [$items[$a], $items[$b]] = [$items[$b], $items[$a]];
        $this->putItems($index, $key, $items);
    }

    /**
     * Starter props for a freshly added block, derived from its §E7 schema.
     *
     * @return array<string, mixed>
     */
    private function defaultProps(string $type): array
    {
        $registry = app(ComponentRegistry::class);

        return $registry->has($type) ? $this->defaultsFromSchema($registry->get($type)->schema()) : [];
    }

    /**
     * @param  array<array-key, mixed>  $schema
     * @return array<string, mixed>
     */
    private function defaultsFromSchema(array $schema): array
    {
        $props = [];
        foreach ($schema as $prop => $spec) {
            $props[(string) $prop] = $this->defaultForSpec($spec);
        }

        return $props;
    }

    /** A prop's starter value from its spec (array with a `type`); safe default otherwise. */
    private function defaultForSpec(mixed $spec): mixed
    {
        $type = is_array($spec) ? ($spec['type'] ?? 'text') : 'text';
        $default = is_array($spec) ? ($spec['default'] ?? null) : null;

        return match ($type) {
            'list' => [],
            'int' => $default ?? 0,
            'image' => $default,   // a media_id, or null for an unset picker
            default => $default ?? '',
        };
    }

    /**
     * Every registered type's §E7 schema, for the editor view to render inputs/repeaters.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function schemas(): array
    {
        $registry = app(ComponentRegistry::class);
        $schemas = [];
        foreach ($registry->types() as $type) {
            $schemas[$type] = $registry->get($type)->schema();
        }

        return $schemas;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeBlocks(mixed $blocks): array
    {
        if (! is_array($blocks)) {
            return [];
        }

        $normalized = [];
        foreach ($blocks as $block) {
            if (is_array($block) && isset($block['type']) && is_string($block['type'])) {
                $normalized[] = [
                    'type' => $block['type'],
                    'props' => is_array($block['props'] ?? null) ? $block['props'] : [],
                ];
            }
        }

        return $normalized;
    }
}
