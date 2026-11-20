<?php

namespace App\Modules\Admin\Livewire\Entries;

use App\Modules\Admin\Support\Entries\ReviewWaiting;
use App\Modules\Admin\Support\Entries\ScheduleInput;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\ApproveEntryForLater;
use App\Modules\Cms\Exceptions\EntryNotGovernedException;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Exceptions\SchedulePastException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Exceptions\InvalidTransitionException;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowState;
use App\Modules\Workflow\Models\WorkflowTransition;
use App\Modules\Workflow\Services\ReviewQueue;
use App\Modules\Workflow\Services\WorkflowEngine;
use App\Modules\Workflow\Services\WorkflowTrail;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use PDOException;

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

    /** Hard ceiling on how much of the queue is hydrated in one request (see waiting()). */
    private const int MAX_QUEUE = 500;

    /** Longest reason a move may carry (the same limit as the posts list's reason dialog). */
    private const int NOTE_MAX = 2000;

    /** At most this many people in the «Giao cho» picker (filtered in SQL first, so nobody eligible hides behind others). */
    private const int ASSIGNEE_LIMIT = 50;

    /** At most this many posts in one «Duyệt đăng N bài»: each is its own locked engine move inside the request. */
    private const int PICK_MAX = 50;

    #[Url(as: 'tab', except: 'queue')]
    public string $tab = 'queue';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'type', except: '')]
    public string $sectionFilter = '';

    /** The post open in the reader (ulid — never a raw id); validated against the queue, and
     *  #[Locked] so a crafted payload cannot make the client's value load-bearing. */
    #[Locked]
    public ?string $current = null;

    /** Reason for a transition that demands one. */
    public string $note = '';

    /**
     * Posts ticked for «Duyệt đăng N bài» (ulids). Only togglePick() changes it, and only with a post
     * of the list on screen, so a crafted payload cannot tick another website's post or one this
     * person may not act on; a tab or filter change clears it (never act on rows out of sight).
     *
     * @var list<string>
     */
    #[Locked]
    public array $picked = [];

    /** The reason dialog of a move that demands one (board 17 «Trả lại»): open flag, entangled with the dialog. */
    public bool $noteOpen = false;

    /** Which move the reason dialog is for; set only by askNote(). */
    #[Locked]
    public ?string $noteKey = null;

    /** «Giao cho» in the reason dialog: a member of this website who can act at the destination, or nobody. */
    public ?int $assignTo = null;

    /**
     * The posts the last «Duyệt đăng N bài» could not move, each with why: shown until dismissed,
     * so a partial failure is never hidden behind the success toast.
     *
     * @var list<array{title: string, why: string}>
     */
    #[Locked]
    public array $bulkFailed = [];

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

    /**
     * The waiting set, resolved once per request. Without this it ran three or four times for a
     * single approve click — each run a fresh query plus four eager loads.
     *
     * @var EloquentCollection<int, Entry>|null
     */
    protected ?EloquentCollection $waitingMemo = null;

    /** The queue could not be read this request (board 17 «Lỗi tải»): the screen offers «Thử lại». */
    protected bool $loadFailed = false;

    /** @var array<string, array<int, array{id: int, name: string, role: string|null}>> */
    protected array $memberMemo = [];

    public function boot(): void
    {
        // The livewire/update endpoint bypasses route middleware, so the gate is re-checked on
        // mount AND on every action.
        $this->authorize('review', Entry::class);
    }

    /**
     * A link may name one post (`?bai=<ulid>`, from "Đọc và duyệt" on Tổng quan): it opens in the
     * reader, and the list grows far enough to show its row. Only a post in this person's own
     * queue opens — the same check as open() — so an unknown ulid, another website's, or one
     * they may not act on just shows the inbox.
     */
    public function mount(): void
    {
        $ulid = request()->query('bai');
        if (! is_string($ulid) || $ulid === '') {
            return;
        }

        $this->open($ulid);
        if ($this->current !== null) {
            $at = $this->visible()->search(fn (Entry $entry): bool => $entry->ulid === $this->current);
            $this->shown = max(self::PAGE, (intdiv(is_int($at) ? $at : 0, self::PAGE) + 1) * self::PAGE);
        }
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
        $this->current = $this->visible()->firstWhere('ulid', $ulid)?->ulid;
        $this->note = '';
        $this->closeNote();
        $this->resetErrorBag();
    }

    public function closeReader(): void
    {
        $this->current = null;
        $this->note = '';
        $this->closeNote();
    }

    public function loadMore(): void
    {
        $this->shown += self::PAGE;
    }

    /** «Thử lại» on the «Chưa tải được hộp duyệt» card: the queue is read again on this render. */
    public function reloadInbox(): void
    {
        $this->waitingMemo = null;
        $this->loadFailed = false;
    }

    /**
     * Tick or untick a post for «Duyệt đăng N bài» (board 17 .sp-pick). Only a post of the list on
     * screen can be ticked; anything else is ignored.
     */
    public function togglePick(string $ulid): void
    {
        if (in_array($ulid, $this->picked, true)) {
            $this->picked = array_values(array_filter($this->picked, static fn (string $picked): bool => $picked !== $ulid));

            return;
        }

        if (count($this->picked) >= self::PICK_MAX) {
            $this->addError('inbox', __('admin.inbox.bulk_max', ['max' => self::PICK_MAX]));

            return;
        }

        if ($this->visible()->contains('ulid', $ulid)) {
            $this->picked[] = $ulid;
        }
    }

    /** «Bỏ chọn» on the selection bar. */
    public function clearPicked(): void
    {
        $this->picked = [];
    }

    /** The ✕ on the list of posts the last «Duyệt đăng N bài» could not move. */
    public function dismissBulk(): void
    {
        $this->bulkFailed = [];
    }

    /**
     * «Duyệt đăng N bài» (asked first in the browser): each ticked post this person may publish from
     * where it stands goes through ITS OWN engine transition — authorized, locked and logged on its
     * own — so one post refused or moved on meanwhile never takes the others down, and never goes
     * unreported: every post that did not move is listed with why, until dismissed. A ticked post
     * that is not at a step this person publishes from stays as it is (the bar said so before).
     */
    public function approvePicked(): void
    {
        $this->resetErrorBag();
        $this->bulkFailed = [];

        $targets = $this->publishable();
        if ($this->loadFailed) {
            return;   // the queue could not be read: the ticks stay, the «Thử lại» card shows
        }
        $failed = $this->pickedGone();
        if ($targets === []) {
            $this->picked = [];
            $this->bulkFailed = $failed;
            $this->waitingMemo = null;
            if ($failed === []) {
                $this->addError('inbox', __('admin.inbox.bulk_why_none'));
            }

            return;
        }

        $done = [];
        foreach ($targets as ['entry' => $entry, 'transition' => $transition]) {
            $why = $this->move($entry, $transition->key, null, null);
            if ($why === null) {
                $done[] = (string) $entry->ulid;
            } else {
                $failed[] = ['title' => $this->titleOf($entry), 'why' => $why];
            }
        }

        $this->picked = [];
        $this->bulkFailed = $failed;
        $this->waitingMemo = null;   // the approved posts left their stage
        if (in_array($this->current, $done, true)) {
            $this->current = null;
        }

        if ($done !== []) {
            $this->dispatch('bs-toast', text: __('admin.inbox.bulk_done', ['count' => count($done)]));
        }
    }

    /**
     * Open the reason dialog for a move that demands one («Trả lại», «Gỡ khỏi website»…): only a
     * move this person may make on the open post. The reason and the hand-off start empty.
     */
    public function askNote(string $key): void
    {
        $this->resetErrorBag();
        $this->note = '';
        $this->assignTo = null;

        $entry = $this->currentEntry();
        $transition = $entry instanceof Entry ? $this->moveOf($entry, $key) : null;
        if (! $transition instanceof WorkflowTransition || ! $transition->requires_note) {
            $this->addError('inbox', __($entry instanceof Entry ? 'admin.inbox.err_stale' : 'admin.inbox.err_gone'));

            return;
        }

        $this->noteKey = $key;
        $this->noteOpen = true;
    }

    /** Esc, ✕ or the backdrop close the dialog in the browser (noteOpen is entangled): forget it here too. */
    public function updatedNoteOpen(bool $open): void
    {
        if (! $open) {
            $this->closeNote();
        }
    }

    /** «Hủy», ✕, Esc or the backdrop of the reason dialog. */
    public function closeNote(): void
    {
        $this->noteOpen = false;
        $this->note = '';
        $this->noteKey = null;
        $this->assignTo = null;
        $this->resetErrorBag(['note', 'assignTo']);
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
        if (! $workflow instanceof Workflow || ! $transition instanceof WorkflowTransition) {
            $this->addError('inbox', __('admin.inbox.err_stale'));

            return;
        }

        // Like the hand-off below: a reason typed in a dialog that was then closed never rides
        // along on a move that asks none.
        $note = $transition->requires_note ? trim($this->note) : '';
        if ($transition->requires_note && $note === '') {
            $this->addError('note', __('admin.wfb.note_missing'));
            $this->js("document.getElementById('inbox-note')?.focus()");

            return;
        }
        if (mb_strlen($note) > self::NOTE_MAX) {
            $this->addError('note', __('entries.wf_note_too_long', ['max' => self::NOTE_MAX]));

            return;
        }

        // A hand-off only exists in the reason dialog (board 17); a value left from a closed dialog
        // never rides along on another move. And it may only name someone of THIS website who can
        // act where the post is going — never an arbitrary users.id from the wire payload.
        // Nor on a move that goes live: a live post waits in nobody's queue.
        $assignTo = $this->assignTo !== null && $transition->requires_note
            && ! $workflow->states()->where('key', $transition->to_state)->where('is_published', true)->exists() ? $this->assignTo : null;
        $assignee = $assignTo !== null ? $this->assigneeName($workflow, $transition->to_state, $entry, $assignTo) : null;
        if ($assignTo !== null && $assignee === null) {
            $this->addError('assignTo', __('admin.wfb.assignee_invalid'));

            return;
        }

        // Remember where we were BEFORE the move: afterwards this entry has left the stage and
        // the list re-sorts, so "the next one" can only be read from the list as it stood.
        /** @var list<string> $queueBefore */
        $queueBefore = $this->visible()->map(fn (Entry $entry): string => (string) $entry->ulid)->values()->all();

        $why = $this->move($entry, $key, $note !== '' ? $note : null, $assignTo);
        if ($why !== null) {
            $this->addError('inbox', $why);

            return;
        }

        $this->note = '';
        $this->closeNote();
        $this->picked = array_values(array_filter($this->picked, fn (string $ulid): bool => $ulid !== $this->current));
        $this->waitingMemo = null;   // the entry just left its stage; the queue changed
        $this->dispatch('bs-toast', text: $assignTo !== null
            ? __('admin.inbox.moved_to', ['action' => $transition->label, 'name' => $assignee])
            : __('admin.inbox.moved', ['action' => $transition->label]));

        // Handled — carry on DOWN the list: the first post that came after this one and is still
        // waiting. (Taking the head of the re-sorted list would throw the reviewer back to the
        // top after every decision.)
        $this->current = $this->nextAfter($queueBefore, (string) $this->current);
    }

    /**
     * «Duyệt và hẹn giờ đăng» (board 17, «Khi nào đăng?» → «Hẹn giờ đăng» in the first-approval dialog): the
     * open post is approved now, through the publishing move $key, to go live at $publishAt (a datetime-local
     * value in the website's clock). A missing or past time is the field's error: it is returned, and the
     * dialog keeps it on the field until it is put right. Anything else (done, refused, the post moved on)
     * is the page's message, and the dialog closes. The move must be one this person may make from where
     * the post stands, going live, asking no reason, on a post not yet live — the same set the dialog is
     * shown for; ApproveEntryForLater judges it all again on the locked row.
     *
     * @return string|null the field's error, or null once the dialog may close
     */
    public function approveLater(string $key, string $publishAt): ?string
    {
        $this->resetErrorBag();

        $when = ScheduleInput::parse($publishAt);
        $refused = ScheduleInput::error($publishAt);
        if ($when === null || $refused !== null) {
            return $refused ?? Cast::string(__('entries.sched_err_empty'));
        }

        $entry = $this->currentEntry();
        if (! $entry instanceof Entry) {
            $this->addError('inbox', __('admin.inbox.err_gone'));

            return null;
        }

        $transition = $this->moveOf($entry, $key);
        $workflow = $this->workflowFor($entry);
        $destination = $transition instanceof WorkflowTransition && $workflow instanceof Workflow ? $workflow->states->firstWhere('key', $transition->to_state) : null;
        if (! $transition instanceof WorkflowTransition || $transition->requires_note || ! $destination instanceof WorkflowState || ! $destination->is_published || $entry->status === 'published') {
            $this->addError('inbox', __('admin.inbox.err_stale'));

            return null;
        }

        $locale = $this->actionableLocale($entry);
        if ($locale === null) {
            $this->addError('inbox', __('admin.inbox.err_no_translation'));

            return null;
        }

        /** @var list<string> $queueBefore */
        $queueBefore = $this->visible()->map(fn (Entry $entry): string => (string) $entry->ulid)->values()->all();

        try {
            app(ApproveEntryForLater::class)->approve($entry, $locale, $this->actor(), $key, $when);
        } catch (SchedulePastException) {
            return Cast::string(__('entries.sched_err_past'));   // the time passed while the row was locked
        } catch (InvalidTransitionException) {
            $this->addError('inbox', __('admin.inbox.err_stale'));

            return null;
        } catch (AuthorizationException) {
            $this->addError('inbox', __('admin.wfb.err_forbidden'));

            return null;
        } catch (EntryNotGovernedException) {
            $this->addError('inbox', __('admin.wfb.err_not_governed'));

            return null;
        }

        $this->picked = array_values(array_filter($this->picked, fn (string $ulid): bool => $ulid !== $this->current));
        $this->waitingMemo = null;   // decided: the post left the queue
        $at = app(SiteTime::class)->forDisplay($when);
        $this->dispatch('bs-toast', text: __('admin.inbox.ap_done', ['when' => $at !== null ? $at->format('d/m/Y · H:i') : '']));
        $this->current = $this->nextAfter($queueBefore, (string) $this->current);

        return null;
    }

    /**
     * One engine transition on one post, through ApplyEntryWorkflowTransition (which locks the row,
     * authorizes the move on it and logs it). Returns why it did not go through, or null when it did.
     */
    private function move(Entry $entry, string $key, ?string $note, ?int $assignTo): ?string
    {
        // Move the translation that EXISTS. Publishing is per-locale, and a post written only in
        // a non-default language would otherwise take the action down with a missing-translation
        // error — or, worse on a multilingual site, publish the default-language copy while the
        // one that was actually submitted stays hidden.
        $locale = $this->actionableLocale($entry);
        if ($locale === null) {
            return Cast::string(__('admin.inbox.err_no_translation'));
        }

        try {
            app(ApplyEntryWorkflowTransition::class)->handle($entry, $locale, $this->actor(), $key, $note, $assignTo);
        } catch (InvalidTransitionException) {
            return Cast::string(__('admin.inbox.err_stale'));
        } catch (AuthorizationException) {
            return Cast::string(__('admin.wfb.err_forbidden'));
        } catch (RouteConflictException) {
            return Cast::string(__('admin.inbox.err_slug'));
        } catch (EntryNotGovernedException) {
            return Cast::string(__('admin.wfb.err_not_governed'));
        }

        return null;
    }

    /**
     * The ticked posts this person may publish from where each stands, with that publishing move:
     * the engine's moves for the post (its permission, and the post within this person's limits)
     * that end at a live step and ask no reason. Read from the pre-loaded workflows, so the bar
     * costs no query per ticked post.
     *
     * @return list<array{entry: Entry, transition: WorkflowTransition}>
     */
    private function publishable(): array
    {
        if ($this->picked === []) {
            return [];
        }

        $resolver = app(EntryWorkflowResolver::class);
        $actor = $this->actor();
        $targets = [];
        foreach ($this->visible()->whereIn('ulid', $this->picked) as $entry) {
            $workflow = $this->workflowFor($entry);
            if (! $workflow instanceof Workflow) {
                continue;
            }
            foreach ($resolver->movesFor($workflow, $entry, $actor) as $transition) {
                $destination = $workflow->states->firstWhere('key', $transition->to_state);
                if (! $transition->requires_note && $destination instanceof WorkflowState && $destination->is_published) {
                    $targets[] = ['entry' => $entry, 'transition' => $transition];

                    break;
                }
            }
        }

        return $targets;
    }

    /**
     * Ticked posts that are no longer waiting for this person (someone else moved, published or
     * deleted them after the bar was drawn): they are reported, never silently left out.
     *
     * @return list<array{title: string, why: string}>
     */
    private function pickedGone(): array
    {
        $gone = array_values(array_diff($this->picked, $this->visible()->pluck('ulid')->map(fn (mixed $ulid): string => Cast::string($ulid))->all()));
        if ($gone === []) {
            return [];
        }

        $titles = Entry::query()->whereIn('ulid', $gone)->with('translations')->get(['id', 'ulid'])
            ->mapWithKeys(fn (Entry $entry): array => [(string) $entry->ulid => $this->titleOf($entry)]);
        $why = Cast::string(__('admin.inbox.err_gone'));

        return array_map(fn (string $ulid): array => ['title' => Cast::string($titles->get($ulid, __('entries.unnamed'))), 'why' => $why], $gone);
    }

    /** A move this person may make on $entry from where it stands, by key (null when it is not one). */
    private function moveOf(Entry $entry, string $key): ?WorkflowTransition
    {
        $workflow = $this->workflowFor($entry);
        if (! $workflow instanceof Workflow) {
            return null;
        }

        foreach (app(EntryWorkflowResolver::class)->movesFor($workflow, $entry, $this->actor()) as $transition) {
            if ($transition->key === $key) {
                return $transition;
            }
        }

        return null;
    }

    /**
     * The name of member $id when they may be handed $entry at $stateKey (the same rule as the
     * picker, without its display limit), or null when they may not — the server-side check of
     * «Giao cho», whatever id the payload carries.
     */
    private function assigneeName(Workflow $workflow, string $stateKey, Entry $entry, int $id): ?string
    {
        $query = app(EntryWorkflowResolver::class)->membersWhoMayApply($workflow->transitions()->where('from_state', $stateKey)->get(), $entry);
        $name = $query?->whereKey($id)->value('users.name');

        return is_string($name) ? $name : null;
    }

    /**
     * Members of THIS website who could move $entry on from $stateKey — the people a post can be
     * handed to in the reason dialog (board 17: only those holding what the next step needs).
     * EntryWorkflowResolver::membersWhoMayApply is the SQL form of the engine's own rule (the
     * step's permission, the category limits, and for a send the right to edit this post), scoped
     * to this website's team. Keyed by users.id for the validity check.
     *
     * @return array<int, array{id: int, name: string, role: string|null}>
     */
    private function assignableMembers(Workflow $workflow, string $stateKey, Entry $entry): array
    {
        // On the instance (one per request), never a static: under Octane a static would outlive
        // the request and hand one website's members to the next.
        $memoKey = $workflow->id.':'.$stateKey.':'.$entry->id;
        if (isset($this->memberMemo[$memoKey])) {
            return $this->memberMemo[$memoKey];
        }

        $transitions = $workflow->relationLoaded('transitions')
            ? $workflow->transitions->where('from_state', $stateKey)
            : $workflow->transitions()->where('from_state', $stateKey)->get();
        $query = app(EntryWorkflowResolver::class)->membersWhoMayApply($transitions, $entry);
        if ($query === null) {
            return $this->memberMemo[$memoKey] = [];
        }

        $members = [];
        foreach ($query->with('roles')->orderBy('name')->limit(self::ASSIGNEE_LIMIT)->get(['users.id', 'users.name']) as $member) {
            $members[(int) $member->id] = ['id' => (int) $member->id, 'name' => (string) $member->name, 'role' => RoleLabel::topOf($member)];
        }

        return $this->memberMemo[$memoKey] = $members;
    }

    /**
     * The locale to act on: the site default when the post has it, otherwise the first
     * translation it does have. Null when the post has no translation at all.
     */
    private function actionableLocale(Entry $entry): ?string
    {
        $default = app(SiteLocale::class)->default();
        if ($entry->translationFor($default) instanceof EntryTranslation) {
            return $default;
        }

        $first = $entry->translations->first();

        return $first instanceof EntryTranslation ? $first->locale : null;
    }

    /**
     * The next post still waiting, taken from the order the list had BEFORE the move.
     *
     * @param  list<string>  $queueBefore
     */
    private function nextAfter(array $queueBefore, string $handled): ?string
    {
        $position = array_search($handled, $queueBefore, true);
        $successors = $position === false ? $queueBefore : array_slice($queueBefore, $position + 1);
        $stillWaiting = $this->visible()->map(fn (Entry $entry): string => (string) $entry->ulid)->all();

        foreach ($successors as $ulid) {
            if (in_array($ulid, $stillWaiting, true)) {
                return $ulid;
            }
        }

        // Nothing below it — fall back to whatever is left at the top, else an empty reader.
        return $this->visible()->first()?->ulid;
    }

    public function render(): View
    {
        $visible = $this->visible();
        $entry = $this->currentEntry();
        $noteDialog = $entry instanceof Entry && $this->noteKey !== null ? $this->noteDialog($entry, $this->noteKey) : null;
        if ($this->noteOpen && $noteDialog === null) {
            // The post left the queue or the move is gone while the dialog was open: close it, so
            // the reason shows in the page's message instead of inside an empty dialog.
            $this->closeNote();
        }

        $view = view('admin::livewire.entries.review-inbox', [
            'rows' => $this->rows($visible->take($this->shown)),
            'total' => $visible->count(),
            'queueCount' => $this->waiting()->count(),
            'mineCount' => $this->mineCount(),
            'sections' => $this->loadFailed ? [] : $this->sectionOptions(),
            'reader' => $entry instanceof Entry ? $this->reader($entry) : null,
            'loadFailed' => $this->loadFailed,
            'bulk' => $this->bulkBar(),
            'noteDialog' => $noteDialog,
        ]);
        $view->title(__('admin.inbox.title'));

        return $view;
    }

    /**
     * The selection bar (board 17 .sp-bulk): how many are ticked, how many of them this person can
     * publish, and the line saying what happens to the rest. Null when nothing is ticked.
     *
     * @return array{picked: int, ready: int, why: string}|null
     */
    private function bulkBar(): ?array
    {
        if ($this->picked === []) {
            return null;
        }

        $picked = count($this->picked);
        $ready = count($this->publishable());

        return [
            'picked' => $picked,
            'ready' => $ready,
            'why' => Cast::string(match (true) {
                $ready === 0 => __('admin.inbox.bulk_why_none'),
                $ready < $picked => __('admin.inbox.bulk_why_some', ['ready' => $ready, 'picked' => $picked]),
                default => __('admin.inbox.bulk_why_all'),
            }),
        ];
    }

    /**
     * The reason dialog (board 17 .sp-modal) for the move $key on the open post: its title, the
     * warning of what the move does, and who it may be handed to («Tên — Vai trò»).
     *
     * @return array{key: string, label: string, icon: string, live: bool, post: string, warning: string, members: list<array{id: int, label: string}>}|null
     */
    private function noteDialog(Entry $entry, string $key): ?array
    {
        $transition = $this->moveOf($entry, $key);
        $workflow = $this->workflowFor($entry);
        if (! $transition instanceof WorkflowTransition || ! $workflow instanceof Workflow) {
            return null;
        }

        $destination = $workflow->states->firstWhere('key', $transition->to_state);
        $origin = $workflow->states->firstWhere('key', $transition->from_state);
        $retract = $origin instanceof WorkflowState && $origin->is_published;
        $label = $destination instanceof WorkflowState ? $destination->label : $transition->to_state;
        $live = $destination instanceof WorkflowState && $destination->is_published;

        return [
            'key' => $transition->key,
            'label' => $transition->label,
            'icon' => $live ? 'ri-check-double' : ($retract ? 'ri-eye-off' : 'ri-arrow-go-back'),
            'live' => $live,
            'post' => $this->titleOf($entry),
            // Retracting a live post, sending it back to the writer's first step, or another move.
            'warning' => Cast::string(match (true) {
                $live => __('entries.wf_ap_text'),
                $retract => __('admin.inbox.note_retract'),
                $destination instanceof WorkflowState && $destination->is_initial => __('admin.inbox.note_return', ['label' => $label]),
                default => __('admin.inbox.note_move', ['label' => $label]),
            }),
            // A live post waits in nobody's queue: nothing to hand over when the move goes live.
            'members' => $live ? [] : array_values(array_map(static fn (array $member): array => [
                'id' => $member['id'],
                'label' => $member['role'] !== null ? Cast::string(__('admin.inbox.member_line', ['name' => $member['name'], 'role' => $member['role']])) : $member['name'],
            ], $this->assignableMembers($workflow, $transition->to_state, $entry))),
        ];
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
        if ($this->waitingMemo instanceof EloquentCollection) {
            return $this->waitingMemo;
        }

        // Bounded on purpose. A review queue is work in flight, so it is small in a healthy
        // team — but "small" is tenant behaviour, not an invariant, and this set is hydrated
        // with its relations. The ceiling keeps a neglected queue from turning every render
        // into an OOM; crossing it is the signal to move search + type filtering into SQL and
        // page this properly (ADR-18). Which posts wait (stage per type, category limits) is
        // ReviewWaiting's answer, shared with the "Duyệt bài" card of Tổng quan.
        // Read inside its own savepoint: a failed read (a timeout, a lost connection) would otherwise
        // abort the request's tenant transaction and end in a bare 500. Caught, it becomes board
        // 17's «Chưa tải được hộp duyệt» with «Thử lại»; nothing is written here, so nothing is lost.
        // A failure that also took the request's own transaction down (the connection itself is
        // lost, so the savepoint could not be rolled back) is not caught: without that transaction
        // there is no tenant context left to render under.
        $level = DB::transactionLevel();
        try {
            $entries = DB::transaction(fn (): EloquentCollection => app(ReviewWaiting::class)->read($this->actor(), self::MAX_QUEUE)['entries']);
        } catch (PDOException $failed) {   // QueryException and DeadlockException are PDOExceptions
            if (DB::transactionLevel() < $level) {
                throw $failed;
            }
            report($failed);
            $this->loadFailed = true;

            /** @var EloquentCollection<int, Entry> $none */
            $none = new EloquentCollection;

            return $this->waitingMemo = $none;
        }

        $term = trim(mb_strtolower($this->search));
        $locale = app(SiteLocale::class)->default();

        $filtered = $entries->filter(function (Entry $entry) use ($term, $locale): bool {
            if ($this->sectionFilter !== '' && $this->sectionKey($entry) !== $this->sectionFilter) {
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

        /** @var EloquentCollection<int, Entry> $result */
        $result = $filtered;

        return $this->waitingMemo = $result;
    }

    /**
     * The rows of the ACTIVE tab (the shared queue, or only what is handed to me).
     *
     * @return EloquentCollection<int, Entry>
     */
    private function visible(): EloquentCollection
    {
        if ($this->tab !== 'mine') {
            return $this->waiting();
        }

        $id = $this->actor()->id;

        /** @var EloquentCollection<int, Entry> $mine */
        $mine = $this->waiting()->filter(fn (Entry $entry): bool => $entry->assigned_to === $id)->values();

        return $mine;
    }

    /** How many of the waiting posts are handed to this person — the same number on both tabs. */
    private function mineCount(): int
    {
        $id = $this->actor()->id;

        return $this->waiting()->filter(fn (Entry $entry): bool => $entry->assigned_to === $id)->count();
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
     *     actions: list<array{key: string, label: string, to_label: string, to_published: bool, first: bool, note: bool, icon: string}>,
     *     schedule: array{at: string, offset: int, zone: string},
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

        $actor = $this->actor();
        $resolver = app(EntryWorkflowResolver::class);
        $actions = [];
        foreach (app(WorkflowEngine::class)->allowedTransitions($workflow, $fromState, $actor) as $transition) {
            if (! $resolver->mayApply($transition, $entry, $actor)) {
                continue;   // a move the action would refuse is never offered
            }
            $destination = $states->firstWhere('key', $transition->to_state);
            $live = $destination instanceof WorkflowState && $destination->is_published;
            $actions[] = [
                'key' => $transition->key,
                'label' => $transition->label,
                'to_label' => $destination instanceof WorkflowState ? $destination->label : $transition->to_state,
                'to_published' => $destination instanceof WorkflowState && $destination->is_published,
                // the first publication offers «Khi nào đăng?» (board 17): now, or approved for a later time
                'first' => $live && ! $transition->requires_note && $entry->status !== 'published',
                'note' => $transition->requires_note,
                // The same icon as its reason dialog: going live, taking a live post down, or a return.
                'icon' => $live ? 'ri-check-double' : ($states->firstWhere('key', $fromState)?->is_published === true ? 'ri-eye-off' : 'ri-arrow-go-back'),
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
            'schedule' => $this->scheduleField(),
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

    /**
     * «Đăng lúc» of the first-approval dialog: its first value (tomorrow 08:00), the website's offset from
     * UTC in seconds (the dialog's own check of a past time reads the website's clock from it; the server
     * judges it again) and that offset as the hint names it («GMT+7»).
     *
     * @return array{at: string, offset: int, zone: string}
     */
    private function scheduleField(): array
    {
        $now = app(SiteTime::class)->now();
        $offset = $now->getOffset();
        $zone = 'GMT'.($offset < 0 ? '−' : '+').intdiv(abs($offset), 3600).(abs($offset) % 3600 !== 0 ? ':'.sprintf('%02d', intdiv(abs($offset) % 3600, 60)) : '');

        return ['at' => ScheduleInput::tomorrowEight(), 'offset' => $offset, 'zone' => $zone];
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
            ->with(['states', 'transitions'])
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
        return $this->current === null ? null : $this->visible()->firstWhere('ulid', $this->current);
    }

    private function resetList(): void
    {
        $this->shown = self::PAGE;
        $this->current = null;
        $this->picked = [];
        $this->waitingMemo = null;
    }

    /** The post's title as the list shows it. */
    private function titleOf(Entry $entry): string
    {
        $translation = $entry->translationFor(app(SiteLocale::class)->default());

        return $translation instanceof EntryTranslation && $translation->title !== '' ? $translation->title : Cast::string(__('entries.unnamed'));
    }

    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
