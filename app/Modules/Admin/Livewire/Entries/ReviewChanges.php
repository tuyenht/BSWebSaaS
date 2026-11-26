<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Entries;

use App\Modules\Admin\Support\Entries\PendingChangeDiff;
use App\Modules\Admin\Support\Locales;
use App\Modules\Cms\Actions\ApplyEntryDraftTransition;
use App\Modules\Cms\Exceptions\EntryDraftRejectedException;
use App\Modules\Cms\Exceptions\EntryNotGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Exceptions\InvalidTransitionException;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowState;
use App\Modules\Workflow\Models\WorkflowTransition;
use App\Modules\Workflow\Services\ReviewQueue;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use PDOException;

/**
 * «Thay đổi của bài đang đăng» — board 17's card in the review inbox: the pending changes to posts
 * that are already on the website, waiting at a stage this person can act on. Visitors keep seeing
 * the live version until a change is approved. Each row compares the change with what visitors see
 * and moves it on: a one-step flow keeps the pair «Trả lại» · «Duyệt đăng»; a flow with more than
 * one waiting stage shows the engine's own moves from where the change stands («Chuyển phản biện»).
 *
 * Which changes wait is the inbox's own rule, asked of the change's stage instead of the post's:
 * a (section, stage) pair this person can act on (ReviewQueue), on a live post inside their
 * category limits. Every move goes through ApplyEntryDraftTransition (the engine, under the post's
 * row lock, refusing content the reviewer has not seen), never a write of its own.
 *
 * Its own component, so a tab, a search or a type filter of the inbox neither reads it again nor
 * narrows it (the board draws the card above both tabs). Paged by a keyset cursor on
 * (updated_at, id): «Tải thêm» reads the next rows after the last one shown.
 */
final class ReviewChanges extends Component
{
    use AuthorizesRequests;

    /** Rows added per «Tải thêm» (the inbox's own page). */
    private const int PAGE = 5;

    /** The count in the card's head reads at most this many (a neglected queue shows "500+"). */
    private const int COUNT_MAX = 500;

    /** At most this many rows on screen, however often «Tải thêm» was pressed. */
    private const int SHOWN_MAX = 100;

    /** Longest note a return may carry (the inbox's and the editor's limit). */
    private const int NOTE_MAX = 2000;

    /**
     * The last row on screen (keyset): rows at or above it in the list order are shown. Null until
     * the first page is drawn. Encrypted: its tie-break is the change's id, which stays on the server.
     */
    #[Locked]
    public ?string $through = null;

    /**
     * What each row on screen showed (EntryDraft::version() by the post's ulid): a move from the
     * row passes it back, so a change edited meanwhile is refused instead of moved unseen.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $seen = [];

    /** The post whose change the dialog shows (ulid); set only by openChange(). */
    #[Locked]
    public ?string $openUlid = null;

    /** The dialog's mode: «So sánh» (view), «Duyệt đăng thay đổi?» (approve) or the return note (return). */
    #[Locked]
    public string $mode = '';

    /** The move the dialog stands for (approve or return), so its confirm makes exactly that move. */
    #[Locked]
    public ?string $moveKey = null;

    /** The change the dialog showed, captured when it opened: its confirm passes this one back. */
    #[Locked]
    public ?string $openSeen = null;

    /** Open flags, entangled with the dialogs. */
    public bool $compareOpen = false;

    public bool $approveOpen = false;

    public bool $returnOpen = false;

    /** The return's note (required). */
    public string $note = '';

    /** The changes could not be read this request: the card stays away, the inbox under it still works. */
    protected bool $loadFailed = false;

    /** @var array{workflows: EloquentCollection<int, Workflow>, stages: array<string, list<string>>}|null */
    protected ?array $queueMemo = null;

    /** @var array<string, string>|null */
    protected ?array $sectionLabels = null;

    public function boot(): void
    {
        // livewire/update skips the route middleware: the gate is asked again on every call.
        $this->authorize('review', Entry::class);
    }

    public function loadMoreChanges(): void
    {
        $through = $this->cursor();
        if ($through === null || $this->shownCount() >= self::SHOWN_MAX) {
            return;
        }
        $next = $this->guarded(fn (): EloquentCollection => $this->query()
            ->whereRaw('(entry_drafts.updated_at, entry_drafts.id) < (?::timestamptz, ?)', [$through['at'], $through['id']])
            ->orderByDesc('entry_drafts.updated_at')->orderByDesc('entry_drafts.id')
            ->limit(self::PAGE)
            ->get(['entry_drafts.id', 'entry_drafts.updated_at']), new EloquentCollection);
        $last = $next->last();
        if ($last instanceof EntryDraft) {
            $this->through = $this->keyOf($last);
        }
    }

    /**
     * Open the dialog on one row: «So sánh» (view), «Duyệt đăng» (approve), or the return note
     * (return; $key names the move in a flow with more than one waiting stage). Only a change on
     * screen that waits for this person opens, with a move they may make.
     */
    public function openChange(string $ulid, string $mode, ?string $key = null): void
    {
        $this->resetErrorBag();
        $this->shut();
        $found = $this->find($ulid);
        if ($found === null) {
            $this->addError('changes', Cast::string(__('admin.inbox.chg_err_gone')));

            return;
        }
        [$change, $entry, $workflow] = $found;
        $move = match ($mode) {
            'view' => null,
            'approve' => $this->publishMove($workflow, $this->movesOf($workflow, $entry, $change), $key),
            'return' => $this->noteMove($workflow, $this->movesOf($workflow, $entry, $change), $key),
            default => false,
        };
        if ($move === false || ($mode !== 'view' && $move === null)) {
            $this->addError('changes', Cast::string(__('admin.wfb.err_forbidden')));

            return;
        }

        // «So sánh» shows the change as it is now; a move pressed on the row answers for what the row showed.
        $seen = $mode === 'view' ? $change->version() : ($this->seen[$ulid] ?? null);
        if ($seen === null) {
            $this->addError('changes', Cast::string(__('admin.inbox.chg_err_gone')));

            return;
        }

        $this->openUlid = $ulid;
        $this->mode = $mode;
        $this->moveKey = $move?->key;
        $this->openSeen = $seen;
        $this->compareOpen = $mode === 'view';
        $this->approveOpen = $mode === 'approve';
        $this->returnOpen = $mode === 'return';
    }

    /** «Trả lại» / «Duyệt đăng» / a move inside «So sánh»: the same dialog, on to that step. */
    public function switchChange(string $mode, ?string $key = null): void
    {
        $ulid = $this->openUlid;
        $seen = $this->openSeen;
        if ($ulid === null || $seen === null || $this->mode !== 'view') {
            return;
        }
        $this->seen[$ulid] = $seen;
        $this->openChange($ulid, $mode, $key);
        if ($this->openUlid === $ulid) {
            // Still the content the reviewer compared, not whatever was saved since.
            $this->openSeen = $seen;
        }
    }

    public function updatedCompareOpen(bool $open): void
    {
        if (! $open) {
            $this->closeChange();
        }
    }

    public function updatedReturnOpen(bool $open): void
    {
        if (! $open) {
            $this->closeChange();
        }
    }

    public function closeChange(): void
    {
        $this->shut();
        $this->resetErrorBag();
    }

    /** The dialog's main button: approve the change live, or send it back with the note. */
    public function confirmChange(): void
    {
        $ulid = $this->openUlid;
        if ($ulid === null || $this->moveKey === null || ! in_array($this->mode, ['approve', 'return'], true)) {
            $this->closeChange();

            return;
        }
        $note = null;
        if ($this->mode === 'return') {
            $this->resetErrorBag('note');
            $note = trim($this->note);
            if ($note === '') {
                $this->addError('note', Cast::string(__('entries.chg.return_err')));

                return;
            }
            if (mb_strlen($note) > self::NOTE_MAX) {
                $this->addError('note', Cast::string(__('validation.max.string', ['attribute' => __('admin.wfb.note_label'), 'max' => self::NOTE_MAX])));

                return;
            }
        }

        $found = $this->find($ulid);
        if ($found === null) {
            $this->shut();
            $this->addError('changes', Cast::string(__('admin.inbox.chg_err_gone')));

            return;
        }
        [$change, $entry, $workflow] = $found;
        $by = $this->writerName($change);
        $langs = $this->localeNames($change);
        $next = $this->neighbour($ulid);
        if (! $this->run($entry, $change, $this->moveKey, (string) $this->openSeen, $note)) {
            $this->shut();

            return;
        }

        $this->dispatch('bs-toast', text: Cast::string($this->mode === 'approve'
            ? __('admin.inbox.chg_t_approved', ['langs' => $langs])
            : __('admin.inbox.chg_t_returned', ['by' => $by])));
        $this->shut();
        $this->focusRow($next);
    }

    /**
     * A middle step of the flow (neither a note nor going live: «Chuyển phản biện»), from the row or
     * from «So sánh». Focus stays on the row while it still waits for this person.
     */
    public function moveChange(string $ulid, string $key): void
    {
        $this->resetErrorBag();
        $fromDialog = $this->openUlid === $ulid && $this->mode === 'view';
        $seen = $fromDialog ? $this->openSeen : ($this->seen[$ulid] ?? null);
        $this->shut();
        $found = $this->find($ulid);
        if ($found === null || $seen === null) {
            $this->addError('changes', Cast::string(__('admin.inbox.chg_err_gone')));

            return;
        }
        [$change, $entry, $workflow] = $found;
        $move = collect($this->movesOf($workflow, $entry, $change))
            ->first(fn (WorkflowTransition $t): bool => $t->key === $key && ! $t->requires_note && ! $this->publishesAt($workflow, $t->to_state));
        if (! $move instanceof WorkflowTransition) {
            $this->addError('changes', Cast::string(__('admin.wfb.err_forbidden')));

            return;
        }
        $next = $this->neighbour($ulid);
        if (! $this->run($entry, $change, $move->key, $seen, null)) {
            return;
        }

        $this->dispatch('bs-toast', text: Cast::string(__('admin.inbox.chg_t_moved', ['step' => $this->stateLabel($workflow, $move->to_state)])));
        // Still this person's at its next step: focus stays on it; otherwise on the row that took its place.
        $this->focusRow($this->find($ulid) !== null ? $ulid : $next);
    }

    public function render(): View
    {
        $read = $this->read();
        $this->seen = [];
        foreach ($read['rows'] as $row) {
            $this->seen[$row['ulid']] = $row['version'];
        }
        $dialog = $this->dialog($read['rows']);
        if ($this->openUlid !== null && $dialog === null) {
            // The change left the queue while the dialog was open: close it, so nothing acts on it.
            $this->shut();
        }

        return view('admin::livewire.entries.review-changes', [
            'rows' => $read['rows'],
            'total' => $read['total'],
            'more' => $read['more'],
            'dialog' => $dialog,
            'loadFailed' => $this->loadFailed,
            'countMax' => self::COUNT_MAX,
            'page' => self::PAGE,
        ]);
    }

    /**
     * The rows on screen, in the list order, with the count for the card's head.
     *
     * @return array{rows: list<array{ulid: string, version: string, title: string, meta: string, langs: string, by: string, at: string, step: string|null, pair: bool, return: string|null, approve: string|null, moves: list<array{key: string, label: string, kind: 'note'|'plain'|'pri', title: string}>, why: string|null, diff: list<array{code: string|null, label: string, now: string, seen: string}>}>, total: int, more: bool}
     */
    private function read(): array
    {
        $none = ['rows' => [], 'total' => 0, 'more' => false];

        return $this->guarded(function () use ($none): array {
            if ($this->queue()['stages'] === []) {
                return $none;
            }
            $ordered = fn (): Builder => $this->query()->orderByDesc('entry_drafts.updated_at')->orderByDesc('entry_drafts.id');
            $changes = null;
            $more = false;
            $through = $this->cursor();
            if ($through !== null) {
                $changes = $ordered()->whereRaw('(entry_drafts.updated_at, entry_drafts.id) >= (?::timestamptz, ?)', [$through['at'], $through['id']])
                    ->limit(self::SHOWN_MAX)->get();
                if ($changes->count() >= self::SHOWN_MAX) {
                    // A full screen (changes sent again meanwhile went to the top): the cursor follows
                    // its last row, and «Tải thêm» waits until some of them are dealt with.
                    $last = $changes->last();
                    $this->through = $last instanceof EntryDraft ? $this->keyOf($last) : $this->through;
                    $more = false;
                } elseif ($changes->isNotEmpty()) {
                    $more = $this->query()->whereRaw('(entry_drafts.updated_at, entry_drafts.id) < (?::timestamptz, ?)', [$through['at'], $through['id']])->exists();
                } else {
                    // Every row shown so far has been dealt with: start again from the top, so rows
                    // further down never wait behind an empty card.
                    $changes = null;
                }
            }
            if ($changes === null) {
                $changes = $ordered()->limit(self::PAGE + 1)->get();
                $more = $changes->count() > self::PAGE;
                $changes = $changes->take(self::PAGE)->values();
                $last = $changes->last();
                $this->through = $last instanceof EntryDraft ? $this->keyOf($last) : null;
            }
            $total = $more || $changes->count() >= self::SHOWN_MAX
                ? DB::query()->fromSub($this->query()->select('entry_drafts.id')->limit(self::COUNT_MAX + 1), 'waiting')->count()
                : $changes->count();

            /** @var EloquentCollection<int, EntryDraft> $changes */
            return ['rows' => $this->rows($changes), 'total' => $total, 'more' => $more];
        }, $none);
    }

    /**
     * A read in its own savepoint, as the inbox's queue: a failed read keeps the card away ($none)
     * instead of a bare 500, while the inbox under it still works.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @param  T  $none
     * @return T
     */
    private function guarded(Closure $read, mixed $none): mixed
    {
        $level = DB::transactionLevel();
        try {
            return DB::transaction($read);
        } catch (PDOException $failed) {   // QueryException and DeadlockException are PDOExceptions
            if (DB::transactionLevel() < $level) {
                throw $failed;
            }
            report($failed);
            $this->loadFailed = true;

            return $none;
        }
    }

    /**
     * The changes waiting for this person (the SQL half of the rule; rows() checks each again).
     *
     * @return Builder<EntryDraft>
     */
    private function query(?string $ulid = null): Builder
    {
        $stages = $this->queue()['stages'];
        $keys = array_values(array_unique($stages === [] ? [] : array_merge(...array_values($stages))));
        $actor = $this->actor();

        return EntryDraft::query()
            ->whereIn('entry_drafts.workflow_state', $keys === [] ? [''] : $keys)
            ->whereHas('entry', function (Builder $entries) use ($stages, $actor, $ulid): void {
                $entries->where('entries.status', 'published');
                if ($ulid !== null) {
                    $entries->where('entries.ulid', $ulid);
                }
                app(SectionResolver::class)->whereOnSectionStage($entries, $stages, 'entry_drafts.workflow_state');
                app(CategoryScopes::class)->whereCovered($entries, $actor);
            });
    }

    /**
     * The rows: each change judged again on its post's section (forEntry(), primed for the set),
     * with its moves, its words and what differs. Names and categories are read once for the set.
     *
     * @param  EloquentCollection<int, EntryDraft>  $changes
     * @return list<array{ulid: string, version: string, title: string, meta: string, langs: string, by: string, at: string, step: string|null, pair: bool, return: string|null, approve: string|null, moves: list<array{key: string, label: string, kind: 'note'|'plain'|'pri', title: string}>, why: string|null, diff: list<array{code: string|null, label: string, now: string, seen: string}>}>
     */
    private function rows(EloquentCollection $changes): array
    {
        $changes->load(['entry' => fn ($entries) => $entries->with(['translations', 'taxonomies.translations'])]);
        $entries = $changes->map(fn (EntryDraft $change): ?Entry => $change->entry)->filter()->values();
        $sections = app(SectionResolver::class);
        $sections->prime($entries);
        $stages = $this->queue()['stages'];
        $byType = $this->queue()['workflows']->keyBy('applies_to_type');

        $writers = User::query()->whereKey($changes->pluck('updated_by')->filter()->unique()->values()->all())->pluck('name', 'id')->all();
        $categoryIds = [];
        foreach ($changes as $change) {
            $categoryIds[] = PendingChangeDiff::targetCategory($change);
            $categoryIds[] = $change->entry?->primaryCategory()?->id;
        }
        $categoryIds = array_values(array_unique(array_filter($categoryIds, is_int(...))));
        $default = app(SiteLocale::class)->default();
        $names = $categoryIds === [] ? [] : array_map(Cast::string(...), TaxonomyTranslation::query()->whereIn('taxonomy_id', $categoryIds)
            ->where('locale', $default)->pluck('name', 'taxonomy_id')->all());
        $order = app(SiteLocale::class)->enabled();
        $diff = app(PendingChangeDiff::class);
        $covers = PendingChangeDiff::coverRefs($changes);
        $scopes = app(CategoryScopes::class);
        $actor = $this->actor();

        $rows = [];
        foreach ($changes as $change) {
            $entry = $change->entry;
            $section = $entry instanceof Entry ? ($sections->forEntry($entry)['key'] ?? null) : null;
            $workflow = $section !== null ? $byType->get($section) : null;
            if (! $entry instanceof Entry || ! $workflow instanceof Workflow || ! $this->waitsAt($workflow, $change, $stages[$section] ?? [])) {
                continue;
            }

            $target = PendingChangeDiff::targetCategory($change);
            $moves = $entry->type === 'post' && $target !== null && ! $scopes->coversId($actor, $target)
                ? []
                : $this->movesOf($workflow, $entry, $change);
            $multi = $this->waitingStages($workflow) >= 2;
            $publish = $this->publishMove($workflow, $moves);
            $back = $this->noteMove($workflow, $moves, null);
            $shown = $multi ? $this->moveButtons($workflow, $moves) : [];
            $why = match (true) {
                $multi && $shown === [] => Cast::string(__('admin.inbox.chg_why_none')),
                ! $multi && $publish === null => Cast::string(__('admin.inbox.chg_why_approve')),
                default => null,
            };

            /** @var Collection<string, EntryTranslation> $live */
            $live = $entry->translations->keyBy('locale');
            $primary = $entry->type === 'post' ? $entry->primaryCategory()?->id : null;
            $rowsDiff = $diff->between($entry, $change, $live, $primary, static fn (array $ids): array => array_intersect_key($names, array_flip($ids)), $order, $covers)['rows'];

            $writer = $change->updated_by !== null ? ($writers[$change->updated_by] ?? null) : null;
            $by = is_string($writer) ? $writer : Cast::string(__('admin.inbox.chg_writer_gone'));
            $at = app(SiteTime::class)->forDisplay($change->updated_at)?->format('H:i d/m') ?? '';
            $langs = $this->localeNames($change);
            $step = $multi ? $this->stateLabel($workflow, (string) $change->workflow_state) : null;
            $meta = array_filter([$step, $this->sectionLabel($section), $by, $at, trans_choice('admin.inbox.chg_edits', count($rowsDiff), ['n' => count($rowsDiff)]), $langs], static fn (?string $part): bool => $part !== null && $part !== '');

            $rows[] = [
                'ulid' => $entry->ulid,
                'version' => $change->version(),
                'title' => $this->titleOf($entry, $change, $default),
                'meta' => implode(' · ', $meta),
                'langs' => $langs,
                'by' => $by,
                'at' => $at,
                'step' => $step,
                'pair' => ! $multi && $publish !== null,
                'return' => ! $multi && $publish !== null ? $back?->key : null,
                'approve' => ! $multi ? $publish?->key : null,
                'moves' => $shown,
                'why' => $why,
                'diff' => $rowsDiff,
            ];
        }

        return $rows;
    }

    /**
     * The dialog over the row it was opened on, or null when that row is gone.
     *
     * @param  list<array{ulid: string, version: string, title: string, meta: string, langs: string, by: string, at: string, step: string|null, pair: bool, return: string|null, approve: string|null, moves: list<array{key: string, label: string, kind: 'note'|'plain'|'pri', title: string}>, why: string|null, diff: list<array{code: string|null, label: string, now: string, seen: string}>}>  $rows
     * @return array{ulid: string, mode: string, title: string, line: string, by: string, langs: string, diff: list<array{lang: string|null, label: string, now: string, seen: string}>, pair: bool, return: bool, moves: list<array{key: string, label: string, kind: 'note'|'plain'|'pri', title: string}>, ok: string}|null
     */
    private function dialog(array $rows): ?array
    {
        if ($this->openUlid === null) {
            return null;
        }
        $row = null;
        foreach ($rows as $one) {
            if ($one['ulid'] === $this->openUlid) {
                $row = $one;
            }
        }
        if ($row === null) {
            return null;
        }
        $move = null;
        foreach ($row['moves'] as $one) {
            if ($one['key'] === $this->moveKey) {
                $move = $one;
            }
        }

        return [
            'ulid' => $row['ulid'],
            'mode' => $this->mode,
            'title' => match ($this->mode) {
                'view' => Cast::string(__('admin.inbox.chg_compare_title', ['title' => $row['title']])),
                'approve' => Cast::string(__('entries.chg.ask_approve')),
                default => $move !== null ? $move['label'] : Cast::string(__('entries.chg.return_title')),
            },
            'line' => match ($this->mode) {
                'view' => Cast::string(__('admin.inbox.chg_compare_line', ['by' => $row['by'], 'at' => $row['at'], 'langs' => $row['langs']])),
                'return' => Cast::string(__('admin.inbox.chg_return_line', ['by' => $row['by']])),
                default => '',
            },
            'by' => $row['by'],
            'langs' => $row['langs'],
            'diff' => array_map(static fn (array $one): array => ['lang' => $one['code'] !== null ? Locales::label($one['code']) : null, 'label' => $one['label'], 'now' => $one['now'], 'seen' => $one['seen']], $row['diff']),
            'pair' => $row['pair'],
            'return' => $row['return'] !== null,
            'moves' => $row['moves'],
            'ok' => $this->mode === 'return'
                ? ($move !== null ? $move['label'] : Cast::string(__('entries.chg.btn_return')))
                : Cast::string(__('entries.chg.btn_approve')),
        ];
    }

    /**
     * A flow's moves as the row's buttons (board 17): those needing a note first, then the plain
     * steps, then the one going live; each says where it takes the change. A move that goes live and
     * also asks for a note fits neither dialog here: it stays in the editor.
     *
     * @param  list<WorkflowTransition>  $moves
     * @return list<array{key: string, label: string, kind: 'note'|'plain'|'pri', title: string}>
     */
    private function moveButtons(Workflow $workflow, array $moves): array
    {
        /** @var Closure(WorkflowTransition): ('note'|'plain'|'pri') $kind */
        $kind = fn (WorkflowTransition $t): string => match (true) {
            $t->requires_note => 'note',
            $this->publishesAt($workflow, $t->to_state) => 'pri',
            default => 'plain',
        };
        $buttons = [];
        foreach (['note', 'plain', 'pri'] as $want) {
            foreach ($moves as $move) {
                if ($kind($move) !== $want || ($move->requires_note && $this->publishesAt($workflow, $move->to_state))) {
                    continue;
                }
                $to = $this->stateLabel($workflow, $move->to_state);
                $title = Cast::string(__($this->publishesAt($workflow, $move->to_state) ? 'admin.inbox.chg_to_live' : 'admin.inbox.chg_to_step', ['step' => $to]));
                $buttons[] = ['key' => $move->key, 'label' => $move->label, 'kind' => $want,
                    'title' => $move->requires_note ? Cast::string(__('admin.inbox.chg_needs_note')).' '.$title : $title];
            }
        }

        return $buttons;
    }

    /**
     * One move of the change through the engine; a refusal is said in the card. Returns whether it moved.
     */
    private function run(Entry $entry, EntryDraft $change, string $key, string $seen, ?string $note): bool
    {
        $error = null;
        $path = '';
        try {
            app(ApplyEntryDraftTransition::class)->handle($entry, $this->localeOf($change), $this->actor(), $key, $seen, $note);
        } catch (EntryDraftRejectedException $refused) {
            $error = 'entries.change_err_'.$refused->reason;
            $path = $refused->path;
        } catch (InvalidTransitionException) {
            $error = 'admin.wfb.err_stale';
        } catch (AuthorizationException) {
            $error = 'admin.wfb.err_forbidden';
        } catch (EntryNotGovernedException) {
            $error = 'admin.wfb.err_not_governed';
        }
        if ($error !== null) {
            $this->addError('changes', Cast::string(__($error, ['path' => $path])));

            return false;
        }

        return true;
    }

    /**
     * The change on screen for $ulid, with its post and workflow, when it still waits for this person.
     *
     * @return array{0: EntryDraft, 1: Entry, 2: Workflow}|null
     */
    private function find(string $ulid): ?array
    {
        $change = $this->query($ulid)->with(['entry' => fn ($entries) => $entries->with(['translations', 'taxonomies.translations'])])->first();
        $entry = $change?->entry;
        if (! $change instanceof EntryDraft || ! $entry instanceof Entry) {
            return null;
        }
        $section = app(SectionResolver::class)->forEntry($entry)['key'] ?? null;
        $workflow = $section !== null ? $this->queue()['workflows']->firstWhere('applies_to_type', $section) : null;
        if (! $workflow instanceof Workflow || ! $this->waitsAt($workflow, $change, $this->queue()['stages'][$section] ?? [])) {
            return null;
        }

        return [$change, $entry, $workflow];
    }

    /**
     * The change waits at one of $stages, and the engine moves it from there too (a change parked at
     * an end stage or one with no move out is back with its writers, whatever its stored stage).
     *
     * @param  list<string>  $stages
     */
    private function waitsAt(Workflow $workflow, EntryDraft $change, array $stages): bool
    {
        $stage = (string) $change->workflow_state;

        return in_array($stage, $stages, true) && app(EntryWorkflowResolver::class)->changeOriginFor($workflow, $change) === $stage;
    }

    /**
     * @return list<WorkflowTransition>
     */
    private function movesOf(Workflow $workflow, Entry $entry, EntryDraft $change): array
    {
        return app(EntryWorkflowResolver::class)->changeMovesFor($workflow, $entry, $change, $this->actor());
    }

    /** @param  list<WorkflowTransition>  $moves */
    private function publishMove(Workflow $workflow, array $moves, ?string $key = null): ?WorkflowTransition
    {
        foreach ($moves as $move) {
            if (! $move->requires_note && $this->publishesAt($workflow, $move->to_state) && ($key === null || $move->key === $key)) {
                return $move;
            }
        }

        return null;
    }

    /** @param  list<WorkflowTransition>  $moves */
    private function noteMove(Workflow $workflow, array $moves, ?string $key): ?WorkflowTransition
    {
        foreach ($moves as $move) {
            if ($move->requires_note && ! $this->publishesAt($workflow, $move->to_state) && ($key === null || $move->key === $key)) {
                return $move;
            }
        }

        return null;
    }

    /** How many stages of the flow wait on someone (neither the first, nor live, nor an end stage). */
    private function waitingStages(Workflow $workflow): int
    {
        return $workflow->states->filter(fn (WorkflowState $s): bool => ! $s->is_initial && ! $s->is_published && ! $s->is_terminal)->count();
    }

    private function publishesAt(Workflow $workflow, string $state): bool
    {
        return $workflow->states->firstWhere('key', $state)?->is_published === true;
    }

    private function stateLabel(Workflow $workflow, string $state): string
    {
        $found = $workflow->states->firstWhere('key', $state);

        return $found instanceof WorkflowState ? $found->label : $state;
    }

    /**
     * The site's active flows and the stages this person can act on, read once per request.
     *
     * @return array{workflows: EloquentCollection<int, Workflow>, stages: array<string, list<string>>}
     */
    private function queue(): array
    {
        if ($this->queueMemo === null) {
            $queue = app(ReviewQueue::class);
            $workflows = $queue->activeWorkflows();
            $this->queueMemo = ['workflows' => $workflows, 'stages' => $queue->actionableStagesIn($workflows, $this->actor())];
        }

        return $this->queueMemo;
    }

    /** A section's name as the admin nav says it. */
    private function sectionLabel(string $key): string
    {
        $this->sectionLabels ??= ContentSection::query()->whereIn('key', array_keys($this->queue()['stages']))->get(['key', 'label'])
            ->mapWithKeys(fn (ContentSection $section): array => [$section->key => __('section.'.$section->key) === 'section.'.$section->key ? $section->label : Cast::string(__('section.'.$section->key))])
            ->all();

        return $this->sectionLabels[$key] ?? $key;
    }

    /** The title the change gives the post (in the site's language when the change holds it), else the live one. */
    private function titleOf(Entry $entry, EntryDraft $change, string $default): string
    {
        $locales = $change->content['locales'];
        $text = $locales[$default] ?? reset($locales);
        $title = is_array($text) ? Cast::stringOrNull($text['title'] ?? null) : null;
        if ($title === null || trim($title) === '') {
            $live = $entry->translationFor($default);
            $title = $live instanceof EntryTranslation ? $live->title : '';
        }

        return $title !== '' ? $title : Cast::string(__('entries.unnamed'));
    }

    /** The change's languages by name, in the website's order. */
    private function localeNames(EntryDraft $change): string
    {
        $order = app(SiteLocale::class)->enabled();
        $codes = array_map(strval(...), array_keys($change->content['locales']));
        usort($codes, static fn (string $a, string $b): int => (array_search($a, $order, true) ?: 0) <=> (array_search($b, $order, true) ?: 0));

        return implode(', ', array_map(Locales::label(...), $codes));
    }

    /** The language a move is logged in: the website's own when the change holds it, else its first. */
    private function localeOf(EntryDraft $change): string
    {
        $codes = array_map(strval(...), array_keys($change->content['locales']));
        $default = app(SiteLocale::class)->default();

        return in_array($default, $codes, true) || $codes === [] ? $default : $codes[0];
    }

    private function writerName(EntryDraft $change): string
    {
        $name = $change->updated_by !== null ? User::query()->whereKey($change->updated_by)->value('name') : null;

        return is_string($name) ? $name : Cast::string(__('admin.inbox.chg_writer_gone'));
    }

    /** The row after $ulid on screen (or before it, at the end): where focus goes when it leaves. */
    private function neighbour(string $ulid): ?string
    {
        $order = array_keys($this->seen);
        $at = array_search($ulid, $order, true);
        if (! is_int($at)) {
            return null;
        }

        return $order[$at + 1] ?? $order[$at - 1] ?? null;
    }

    /** Focus a row's first button once the page has been redrawn; with no row left, the inbox's first tab. */
    private function focusRow(?string $ulid): void
    {
        $target = $ulid !== null ? '[data-chg="'.$ulid.'"] button' : '[data-inbox-tabs] button';
        $this->js('setTimeout(() => document.querySelector('.Js::from($target).')?.focus(), 60)');
    }

    /** The keyset position of $change, sealed for the browser. */
    private function keyOf(EntryDraft $change): string
    {
        return Crypt::encryptString((string) json_encode(['at' => $change->updated_at?->format('Y-m-d H:i:s.uP') ?? '1970-01-01 00:00:00+00:00', 'id' => $change->id]));
    }

    /** @return array{at: string, id: int}|null */
    private function cursor(): ?array
    {
        if ($this->through === null) {
            return null;
        }
        try {
            $key = json_decode(Crypt::decryptString($this->through), true);
        } catch (DecryptException) {
            return null;
        }

        return is_array($key) && is_string($key['at'] ?? null) && is_int($key['id'] ?? null) ? ['at' => $key['at'], 'id' => $key['id']] : null;
    }

    private function shownCount(): int
    {
        return count($this->seen);
    }

    private function shut(): void
    {
        $this->openUlid = null;
        $this->mode = '';
        $this->moveKey = null;
        $this->openSeen = null;
        $this->compareOpen = false;
        $this->approveOpen = false;
        $this->returnOpen = false;
        $this->note = '';
    }

    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
