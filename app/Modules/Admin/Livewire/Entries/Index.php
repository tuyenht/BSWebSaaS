<?php

namespace App\Modules\Admin\Livewire\Entries;

use App\Modules\Admin\Support\CategoryTree;
use App\Modules\Admin\Support\Entries\EntryListFilter;
use App\Modules\Admin\Support\Entries\EntryListGuide;
use App\Modules\Admin\Support\Entries\EntryListQuery;
use App\Modules\Admin\Support\Entries\ListCursor;
use App\Modules\Admin\Support\Entries\ListGroup;
use App\Modules\Admin\Support\Entries\PostRoleNote;
use App\Modules\Admin\Support\FirstHints;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\DuplicateEntry;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\EntryNotGovernedException;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Exceptions\WorkflowGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Support\CategoryItemCounts;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Support\EntryBlockMediaReconciler;
use App\Modules\Cms\Support\EntryTrash;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Cms\Validation\EntryContentRules;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\ConcurrencyError;
use App\Modules\Shared\Support\Slugger;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Exceptions\InvalidTransitionException;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowState;
use App\Modules\Workflow\Models\WorkflowTransition;
use App\Modules\Workflow\Services\WorkflowEngine;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * The Pages list (design_handoff_pages_admin §3a). Lists a content_section's entries
 * (site-scoped by BelongsToSite + RLS), resolved from ?section=… — pages by default. Status
 * tabs + counts, name/slug search, and sortable columns all run server-side. The page tree is
 * kept intact: only parents are sorted, children ride directly under their parent, and a child
 * whose parent is filtered out is promoted to the top level (never orphaned under a wrong
 * parent). Up to PAGES_IN_MEMORY pages the tree is grouped + paged in memory; posts, and pages
 * past that, are filtered, sorted and paged in the database (EntryListQuery), one page of rows at
 * a time, at a flat query budget (enforced by a Query-Budget test). Numbered pages reach the first
 * 10,000 rows; past them the pager goes on with an encrypted cursor, and counts stop at "hơn
 * 10.000". Read access is gated by EntryPolicy::viewAny; the entry is addressed by ulid, never its
 * raw id.
 *
 * The header follows the shared list layout: status groups (a row from 900px, the Filter
 * sheet's first section below), search, the filters (Mẫu trang, Bản dịch, Ngôn ngữ, Người tạo,
 * Thời gian for posts), a Sort menu whose default depends on the type (pages: their order on
 * the website; posts: last edited first), and what each member chooses to show (Cột on a wide
 * list, Hiện trên thẻ on phone cards), kept in their own users.preferences.
 */
#[Layout('admin::layouts.app')]
final class Index extends Component
{
    use AuthorizesRequests;

    /** The content section the screen opened on (?section=…); fixed for the screen's life, never sent back by the browser. */
    #[Locked]
    public ?string $section = null;

    #[Url(as: 'tab', except: 'all')]
    public string $tab = 'all';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** '' = the type's default sort (pages: order on the website; posts: last edited). */
    #[Url(as: 'sort', except: '')]
    public string $sortKey = '';

    /** '' = the chosen key's natural direction (newest / most first for dates and views). */
    #[Url(as: 'dir', except: '')]
    public string $sortDir = '';

    #[Url(as: 'page', except: 1)]
    public int $page = 1;

    /** Rows per page — cycled 10/20/50 by the footer's "Dòng/trang" control (§3a). */
    #[Url(as: 'per_page', except: 20)]
    public int $perPage = 20;

    /**
     * The page read past the numbered pages: an encrypted note of the row the page follows (or
     * precedes), so the list goes on from there however deep it is. Never in the URL; the row's id
     * inside stays unreadable. Empty on a numbered page; every change of what the list shows clears it.
     */
    #[Locked]
    public string $cursor = '';

    /** @var list<string> ulids selected for a bulk action (transient — not URL-persisted). */
    public array $selected = [];

    /**
     * The shared toast's message (components/toast): what a move to the Trash did, with «Hoàn tác»
     * for 10 seconds, and what «Hoàn tác» then did. Every other message is the SweetAlert pill
     * (bs-toast), which clears this one first, so the two never stand on the screen together.
     */
    public string $toastMsg = '';

    public string $toastTone = 'success';

    /** Counts the messages, so the toast's timer only ever clears the message it was started for. */
    public int $toastSeq = 0;

    /**
     * @var list<string>|null the rows the shown message moved to the Trash, which «Hoàn tác» brings
     *                        back. Locked, so the browser cannot name other rows; the undo still asks
     *                        the policy about each row, as any restore does.
     */
    #[Locked]
    public ?array $trashUndo = null;

    /** @var list<string> selected content-locales — OR within the group. */
    #[Url(as: 'lang')]
    public array $langF = [];

    /** @var list<string> selected author ulids — OR within the group. */
    #[Url(as: 'author')]
    public array $creatorF = [];

    /** @var list<string> selected templates (TEMPLATE_NONE marks "no template") — OR within the group. */
    #[Url(as: 'tpl')]
    public array $templateF = [];

    /** @var list<string> translation states (full / partial / none) — OR within the group; multi-language sites only. */
    #[Url(as: 'tr')]
    public array $transF = [];

    /**
     * @var list<string> chosen category ulids of a post list — OR within the group; a category brings
     *                   everything under it (ticking one ticks its children, see settleCategories())
     */
    #[Url(as: 'cat')]
    public array $catF = [];

    /** @var list<string> the categories as they were before a change from the browser (see updating()) */
    private array $catBefore = [];

    /** The section's categories, read once per request (a post list only). */
    private ?CategoryTree $catTree = null;

    /** "Thời gian" of a post list: a preset from WHEN, or RANGE while the two dates below decide. */
    #[Url(as: 'when', except: '')]
    public string $when = '';

    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    /** @var list<string> what this member shows on a wide list ("Cột"); kept in their own preferences. */
    public array $cols = self::COLS_DEFAULT;

    /** @var list<string> what this member adds to a phone card ("Hiện trên thẻ"); kept in their own preferences. */
    public array $card = [];

    /** "Hiện thêm nút khi đủ chỗ" (Cột): a wide row also shows Nhân bản and Thùng rác beside «Sửa» when they fit; kept in their own preferences. */
    public bool $xbtn = false;

    private ?string $sectionFor = null;

    private ?ContentSection $sectionRow = null;

    /** The guide («Hướng dẫn») is printed on the screen's first draw only (a redraw keeps it, wire:ignore). */
    private bool $guideDue = false;

    /** Whether the listed section runs an approval workflow, fixed when the screen opens (it offers «Bước duyệt»). */
    #[Locked]
    public bool $governed = false;

    /** The workflow read as the screen opened, reused by that first draw (not kept between requests). */
    private ?Workflow $mountedWorkflow = null;

    private bool $workflowRead = false;

    /** The listed type (page / post), fixed when the screen opens. */
    #[Locked]
    public string $entryType = 'page';

    /**
     * Where this list's view choices live in the member's preferences, fixed when the screen opens from the
     * resolved list: "entries.page", or "entries.post.<key>" of a section that exists — so the keys a member
     * can collect are bounded by the site's own lists, whatever the browser sends.
     */
    #[Locked]
    public string $viewKey = 'entries.page';

    /** Tabs that map to a concrete status; the rest: all = every non-trashed, trash = deleted. */
    private const array STATUS_TABS = ['published', 'draft', 'scheduled', 'private', 'pending', 'archived'];

    /** Every status group a member may pick. */
    private const array TABS = ['all', ...self::STATUS_TABS, 'trash'];

    /** The "Thời gian" presets ('' = any time); RANGE means the two dates are in use. */
    public const array WHEN = ['', 'today', '7', '30', '90'];

    public const string RANGE = 'range';

    /** Translation states: every site language has content, some do, only one does. */
    public const array TRANS = ['full', 'partial', 'none'];

    /** Sort keys, each with the one type it belongs to (null = both). */
    public const array SORTS = ['order' => 'page', 'name' => null, 'updated' => null, 'pub' => 'post', 'status' => null, 'views' => null, 'creator' => null];

    /**
     * Optional columns of a wide list ("Cột"), in the menu's order, and the ones on until the member says
     * otherwise. Chuyên mục is a post list's and Mẫu trang a page list's; Bước duyệt is offered only while
     * the section runs an approval workflow (the choice is kept either way).
     */
    private const array COLS = ['path', 'desc', 'langs', 'cat', 'tpl', 'step', 'views', 'creator'];

    private const array COLS_DEFAULT = ['path', 'langs', 'cat', 'step', 'views', 'creator'];

    /** The columns a member could choose before "Chuyên mục" came: a choice saved then starts with it shown. */
    private const array COLS_BEFORE_CAT = ['path', 'langs', 'creator', 'tpl'];

    /** What a phone card may add ("Hiện trên thẻ"), all off until the member turns them on. */
    private const array CARD = ['cat', 'tpl', 'path', 'langs'];

    /** Sentinel value for the "no template" (null template_override) filter option. */
    private const string TEMPLATE_NONE = '__default__';

    /** The most rows one bulk action takes: every one is locked and worked through in a single request. */
    private const int BULK_LIMIT = 200;

    /** The longest reason a move from the list takes (the reason dialog stops there too). */
    private const int NOTE_MAX = 2000;

    /** A page list this size or smaller is read whole and sorted, filtered and treed in memory. */
    private const int PAGES_IN_MEMORY = 1000;

    /** At most this many pages ride below the top pages of one page of the tree. */
    private const int TREE_CHILDREN = 1000;

    /** The deepest branch of sub-pages one move to the Trash takes along. */
    private const int TREE_DEPTH = 12;

    /** What a row shows besides its own columns, loaded for the rows of the page only. */
    private const array ROW_RELATIONS = [
        'translations:id,entry_id,locale,title,slug,status',
        'routes:id,entry_id,locale,path',
        'author:id,ulid,name',
    ];

    /** The same, less the author: a list read in SQL already knows its authors. */
    private const array ROW_RELATIONS_BUT_AUTHOR = [
        'translations:id,entry_id,locale,title,slug,status',
        'routes:id,entry_id,locale,path',
    ];

    /**
     * Every request of this screen — the first render, each action and each live property change — is
     * refused once the member may no longer see entries: the livewire/update endpoint skips route middleware.
     */
    public function boot(): void
    {
        $this->authorize('viewAny', Entry::class);
    }

    public function mount(?string $section = null): void
    {
        $this->section = $section ?? request()->query('section');
        // #[Url] hydrates perPage directly from the query string during mount, which bypasses
        // updatedPerPage() below (that hook only fires on a later property *change*, not this
        // initial value) — so a degenerate `?per_page=0` must also be clamped here, or paginate()
        // divides the row total by zero on the very first request.
        if (! in_array($this->perPage, [10, 20, 50], true)) {
            $this->perPage = 20;
        }

        $section = $this->resolveSection();
        $this->entryType = $section instanceof ContentSection && $section->entry_type === 'post' ? 'post' : 'page';
        $this->viewKey = $this->entryType === 'post' && $section instanceof ContentSection ? 'entries.post.'.$section->key : 'entries.page';
        $lists = Cast::arr(Cast::arr($this->actingUser()->preferences)['lists'] ?? null);
        $view = Cast::arr($lists[$this->viewKey] ?? null);
        $cols = $this->colsHere();
        $this->cols = isset($view['cols']) ? self::known(Cast::arr($view['cols']), $cols) : self::known(self::COLS_DEFAULT, $cols);
        // a column added since the choice was saved starts as its default says (shown), not as hidden
        $offered = isset($view['offered']) ? Cast::strings($view['offered']) : self::COLS_BEFORE_CAT;
        $this->cols = self::known([...$this->cols, ...array_diff(self::COLS_DEFAULT, $offered)], $cols);
        $this->card = self::known(Cast::arr($view['card'] ?? null), $this->cardHere());
        $this->xbtn = ($view['xbtn'] ?? false) === true;
        $this->guideDue = true;
        // whether the section runs an approval workflow (offers the Bước duyệt column): asked once, as the screen opens
        $this->mountedWorkflow = $this->entryType === 'post' && $section instanceof ContentSection ? app(WorkflowEngine::class)->activeFor($section->key) : null;
        $this->workflowRead = true;
        $this->governed = $this->mountedWorkflow !== null;
        $this->clean();
    }

    public function updatedSearch(): void
    {
        $this->restart(); // a new term must not strand the user on a stale page number
    }

    /** A category is about to change from the browser: remember what was chosen, to see which one was ticked or unticked. */
    public function updating(string $property): void
    {
        if (Str::before($property, '.') === 'catF') {
            $this->catBefore = $this->catF;
        }
    }

    /**
     * A filter changed from the browser (the Filter panel binds its fields live): keep only values
     * the screen knows, then return to page 1. A preset of "Thời gian" drops the two dates; a
     * date picks the range.
     */
    public function updated(string $property): void
    {
        $name = Str::before($property, '.');
        if ($name === 'when' && $this->when !== self::RANGE) {
            $this->from = $this->to = '';
        }
        if ($name === 'from' || $name === 'to') {
            $this->when = self::dateOr($this->from) !== '' || self::dateOr($this->to) !== '' ? self::RANGE : '';
        }
        if ($name === 'catF') {
            $this->settleCategories($this->catBefore);
        }
        if (in_array($name, ['tab', 'catF', 'transF', 'langF', 'creatorF', 'templateF', 'when', 'from', 'to', 'sortKey', 'sortDir'], true)) {
            $this->clean();
            $this->restart();
        }
        if ($name === 'tab') {
            $this->selected = []; // as setTab(): never act on rows of another group
        }
    }

    /** "Cột" changed: kept for this member only. */
    public function updatedCols(): void
    {
        $this->cols = self::known($this->cols, $this->colsHere());
        $this->saveView();
    }

    /** "Hiện trên thẻ" changed: kept for this member only. */
    public function updatedCard(): void
    {
        $this->card = self::known($this->card, $this->cardHere());
        $this->saveView();
    }

    /** "Hiện thêm nút khi đủ chỗ" changed: kept for this member only (the typed property takes a yes or no only). */
    public function updatedXbtn(): void
    {
        $this->saveView();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'all';
        $this->restart();
        // A selection made on one tab is not shown on the next, and the next tab's bar offers
        // other actions (the Trash's is "Xóa vĩnh viễn") — never let it act on rows out of sight.
        $this->selected = [];
    }

    /** A column header: the same key again turns the direction round; a new key starts in its natural direction. */
    public function sortBy(string $key): void
    {
        if (! in_array($key, ['name', 'status', 'creator', 'updated'], true)) {
            return;
        }

        [$current, $dir] = $this->sorting();
        $this->setSorting($key, $current === $key ? ($dir === 'asc' ? 'desc' : 'asc') : self::naturalDir($key));
    }

    /** The Sort menu: one choice, in its natural direction. */
    public function setSort(string $key): void
    {
        if (! $this->sortsHere($key)) {
            return;
        }

        $this->setSorting($key, self::naturalDir($key));
    }

    /** Back to the type's default sort (the sort chip's ✕). */
    public function resetSort(): void
    {
        $this->sortKey = $this->sortDir = '';
        $this->restart();
    }

    /** "Bỏ lọc" on the Thời gian chip. */
    public function clearWhen(): void
    {
        $this->when = $this->from = $this->to = '';
        $this->restart();
    }

    /** Footer "Dòng/trang" group — one of 10 / 20 / 50, then back to page 1. Anything else is ignored. */
    public function setPerPage(int $rows): void
    {
        if (! in_array($rows, [10, 20, 50], true)) {
            return;
        }

        $this->perPage = $rows;
        $this->restart();
    }

    /** Guards a live property change (e.g. a forged $wire.set) — mount()'s own clamp covers the URL. */
    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 20, 50], true)) {
            $this->perPage = 20;
        }
        $this->restart();
    }

    /** Back to the first page: what the list shows changed, so a page number or a cursor means nothing now. */
    private function restart(): void
    {
        $this->page = 1;
        $this->cursor = '';
    }

    /**
     * Footer pager past the numbered pages: the next or previous page, after or before a row the
     * last render named. A note that does not open, or one made for another view of the list, goes
     * back to the first page.
     */
    public function turnPage(string $token): void
    {
        $note = ListCursor::open($token, $this->fingerprint());
        if ($note === null) {
            $this->restart();

            return;
        }
        $this->cursor = $token;
        $this->page = $note['n'];
    }

    /** Footer pager — page-pill/arrow clicks; paginate() clamps an out-of-range target below. */
    public function gotoPage(int $page): void
    {
        $this->page = max(1, $page);
        $this->cursor = '';
    }

    public function toggleFilterValue(string $group, string $value): void
    {
        if ($group === 'cat') {
            $before = $this->catF;
            $this->catF = $this->toggleIn($this->catF, $value);
            $this->settleCategories($before);
        } elseif ($group === 'lang') {
            $this->langF = $this->toggleIn($this->langF, $value);
        } elseif ($group === 'creator') {
            $this->creatorF = $this->toggleIn($this->creatorF, $value);
        } elseif ($group === 'template') {
            $this->templateF = $this->toggleIn($this->templateF, $value);
        } elseif ($group === 'trans' && in_array($value, self::TRANS, true)) {
            $this->transF = $this->toggleIn($this->transF, $value);
        }

        $this->restart();
    }

    public function resetFilterGroup(string $group): void
    {
        if ($group === 'cat') {
            $this->catF = [];
        } elseif ($group === 'lang') {
            $this->langF = [];
        } elseif ($group === 'creator') {
            $this->creatorF = [];
        } elseif ($group === 'template') {
            $this->templateF = [];
        } elseif ($group === 'trans') {
            $this->transF = [];
        }

        $this->restart();
    }

    /** "Bỏ lọc": every filter, the search words and the group go; the sort stays. */
    public function clearAllFilters(): void
    {
        $this->catF = [];
        $this->langF = [];
        $this->creatorF = [];
        $this->templateF = [];
        $this->transF = [];
        $this->when = $this->from = $this->to = '';
        $this->search = '';
        if ($this->tab !== 'all') {
            $this->tab = 'all';
            $this->selected = [];
        }
        $this->restart();
    }

    /**
     * Row status menu → published (via PublishEntry, route-synced) or draft/private/archived («Gỡ
     * khỏi website — giữ lại nội dung», via TransitionEntryStatus, which needs the publish right on
     * this row); for a member who may only send for review, pending («Gửi duyệt», via PublishEntry,
     * which sends a draft). A post whose section runs an approval workflow moves through
     * applyTransition() instead; a direct publish of one (a tab opened before the workflow was
     * switched on) comes back as a plain toast, as does a move this member may not make on this row.
     */
    public function changeStatus(string $ulid, string $status): void
    {
        $entry = $this->findLiveEntry($ulid);
        if ($entry === null) {
            $this->toastStaleRow();

            return;
        }
        $user = $this->actingUser();
        $locale = app(SiteLocale::class)->default();
        // «Gỡ khỏi website» is for a live row this member may publish, never the Home page: the
        // bulk bar's rule, judged here too (the menu only hides it)
        if ($status === EntryStatus::Archived->value) {
            $why = $this->whyNotUnpublish($entry, $user, $this->homeIds([$entry->ulid]));
            if ($why === 'entries.skip_home') {
                $this->toast(__('entries.skip_home_note'));

                return;
            }
            if ($why === 'entries.skip_not_live') {
                $this->toastStaleRow();   // no longer live (moved meanwhile): the menu offered it on a live row only

                return;
            }
            if ($why !== null) {
                $this->refuse($this->refusalFor($entry, 'status'));

                return;
            }
        }

        try {
            if ($status === EntryStatus::Pending->value) {
                // Only a sender sends: a publisher's PublishEntry would publish instead.
                if ($user->can('publish', $entry) || ! $user->can('submit', $entry)) {
                    throw new AuthorizationException;
                }
                $moved = app(PublishEntry::class)->attempt($entry, $locale, $user)['moved'];
                // not moved: no longer a draft (someone sent or published it meanwhile)
                $moved ? $this->toast(__('entries.ed.toast_sent', ['unit' => __('entries.noun_'.$entry->type)])) : $this->toastStaleRow();

                return;
            }
            if ($status === EntryStatus::Published->value) {
                app(PublishEntry::class)->handle($entry, $locale, $user);
            } elseif (in_array($status, [EntryStatus::Draft->value, EntryStatus::Private->value, EntryStatus::Archived->value], true)) {
                app(TransitionEntryStatus::class)->handle($entry, EntryStatus::from($status), $user);
            } else {
                return; // scheduled has its own datetime path; unknown values are ignored
            }
        } catch (WorkflowGovernedException) {
            $this->toast(__('entries.workflow_governed'));

            return;
        } catch (ModelNotFoundException) {
            $this->toastStaleRow(); // sent to the Trash or purged since the list loaded it

            return;
        } catch (AuthorizationException) {
            $this->refuse($this->refusalFor($entry, 'status'));

            return;
        }

        $this->toast(__('entries.status_changed'));
    }

    /**
     * The row status menu in a section that runs an approval workflow: one of the engine's moves
     * («Gửi duyệt», «Duyệt đăng», «Trả lại» …). The engine judges it on the locked row (the move's
     * right, the row's stage, the reason a move demands); this only checks what it is sent and says
     * how it went. A move that demands a reason is refused without one here first.
     */
    public function applyTransition(string $ulid, string $key, string $note = ''): void
    {
        $note = trim($note);
        if (mb_strlen($note) > self::NOTE_MAX) {
            $this->toast(__('entries.wf_note_too_long', ['max' => self::NOTE_MAX]));

            return;
        }
        $entry = $this->findLiveEntry($ulid);
        if ($entry === null) {
            $this->toastStaleRow();

            return;
        }
        $workflow = app(EntryWorkflowResolver::class)->forEntry($entry);
        if ($workflow === null) {
            $this->toast(__('admin.wfb.err_not_governed'));

            return;
        }
        $transition = $workflow->transitions()->where('key', $key)->first();
        if (! $transition instanceof WorkflowTransition) {
            $this->toast(__('admin.wfb.err_stale'));

            return;
        }
        if ($transition->requires_note && $note === '') {
            $this->toast(__('admin.wfb.note_missing'));

            return;
        }

        try {
            $this->move($entry, $key, $note);
        } catch (InvalidTransitionException) {
            $this->toast(__('admin.wfb.err_stale'));   // moved on elsewhere meanwhile, or the flow changed

            return;
        } catch (AuthorizationException) {
            $this->refuse($this->refusalFor($entry, 'status'));

            return;
        } catch (RouteConflictException) {
            $this->toast(__('admin.inbox.err_slug'));

            return;
        } catch (EntryNotGovernedException) {
            $this->toast(__('admin.wfb.err_not_governed'));

            return;
        }

        $this->toast(__($note !== '' ? 'entries.wf_moved_note' : 'entries.wf_moved', ['label' => $transition->label]));
    }

    /**
     * The bulk bar in a section that runs an approval workflow: one of the engine's moves that needs
     * no reason, made on each ticked row through the engine. A row it cannot move (another stage,
     * not this member's to move, moved on meanwhile) is skipped and counted in the one toast.
     */
    public function bulkTransition(string $key): void
    {
        $workflow = $this->governed ? $this->sectionWorkflow() : null;
        $transition = $workflow?->transitions->firstWhere('key', $key);
        if (! $workflow instanceof Workflow || ! $transition instanceof WorkflowTransition || $transition->requires_note) {
            $this->toast(__($workflow === null ? 'admin.wfb.err_not_governed' : 'admin.wfb.err_stale'));

            return;
        }
        $user = $this->actingUser();
        $replace = ['label' => $transition->label, 'noun' => __('entries.noun_'.$this->entryType)];

        $this->reportBulk($this->runBulk(
            'entries.bulk_moved',
            'entries.bulk_skipped',
            fn (Entry $entry): bool => $this->whyNotMove($entry, $user, $workflow, $key) === null,
            function (Entry $entry) use ($key): bool {
                try {
                    $this->move($entry, $key, '');
                } catch (InvalidTransitionException|EntryNotGovernedException) {
                    return false;   // left its stage or its workflow meanwhile: skipped, not failed
                }

                return true;
            },
        ), 'entries.bulk_moved', 'entries.bulk_skipped', replace: $replace);
    }

    public function scheduleEntry(string $ulid, string $publishAt): void
    {
        // Future-only: a past (or now) time would let entries:publish-due publish it on its very
        // next run, so "scheduled" would flash and vanish. Reject it with a plain-language toast.
        $when = $this->parseSchedule($publishAt);
        if ($when === null || ! $when->isFuture()) {
            $this->toast(__('entries.schedule_future_only'));

            return;
        }

        $entry = $this->findLiveEntry($ulid);
        if ($entry === null) {
            $this->toastStaleRow();

            return;
        }

        try {
            app(TransitionEntryStatus::class)->handle($entry, EntryStatus::Scheduled, $this->actingUser(), $when);
        } catch (WorkflowGovernedException) {
            $this->toast(__('entries.workflow_governed'));

            return;
        } catch (ModelNotFoundException) {
            $this->toastStaleRow(); // sent to the Trash or purged since the list loaded it

            return;
        } catch (AuthorizationException) {
            $this->refuse($this->refusalFor($entry, 'status'));

            return;
        }

        $this->toast(__('entries.scheduled_set'));
    }

    /** Parse the datetime-local value in the site's zone → UTC; null when empty or malformed (only a forged/broken submit sends that). */
    private function parseSchedule(string $publishAt): ?CarbonImmutable
    {
        if (trim($publishAt) === '') {
            return null;
        }

        try {
            return app(SiteTime::class)->toStorage($publishAt);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Inline rename — updates the default-locale translation title, under the editor's own title
     * rule. Only live rows: the Trash offers no inline editing.
     */
    public function saveTitle(string $ulid, string $value): void
    {
        $entry = $this->findLiveEntry($ulid);
        if ($entry === null) {
            $this->toastStaleRow();

            return;
        }
        if (! $this->allowed('update', $entry, 'edit')) {
            return;
        }

        // The entry row first, as every entry action takes it: a check of the search words running
        // meanwhile waits for this rename instead of writing the old title's words back.
        // A row trashed while this waited is stale; otherwise its locked values replace the ones read before.
        $locked = Entry::withTrashed()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
        if ($locked->trashed()) {
            $this->toastStaleRow();

            return;
        }
        $entry->setRawAttributes($locked->getAttributes(), true);

        $value = trim($value);
        $translation = $entry->translationFor(app(SiteLocale::class)->default());
        if ($value === '' || $translation === null || $translation->title === $value) {
            return;
        }

        if (! $this->passesContentRule('title', $value)) {
            return;
        }

        $translation->update(['title' => $value]);
        $this->recordLiveEdit($entry, $translation);
        $this->toast(__('entries.title_saved', ['noun' => __('entries.noun_'.$entry->type)]));
    }

    /**
     * Inline slug edit — slugifies the leaf server-side (VN-aware), updates the default-locale
     * translation, and (for a published page) moves its route + cascades 301s to descendants,
     * all in one transaction so a slug clash rolls back cleanly. The home page slug is locked.
     * Only live rows: a trashed page keeps its routes, so moving its slug would move the URLs of
     * its live children.
     */
    public function saveSlug(string $ulid, string $value): void
    {
        $entry = $this->findLiveEntry($ulid);
        if ($entry === null) {
            $this->toastStaleRow();

            return;
        }
        if (! $this->allowed('update', $entry, 'edit')) {
            return;
        }

        $locale = app(SiteLocale::class)->default();
        $translation = $entry->translationFor($locale);
        if ($translation === null) {
            return;
        }

        if ($translation->slug === PathBuilder::HOME_SLUG) {
            $this->toast(__('entries.home_locked'));

            return;
        }

        $slug = Slugger::make($value, $locale, PHP_INT_MAX);
        if ($slug === '') {
            $this->toast(__('entries.slug_untranslatable'));

            return;
        }
        if ($slug === $translation->slug || ! $this->passesContentRule('slug', $slug)) {
            return;
        }

        try {
            DB::transaction(function () use ($entry, $translation, $slug, $locale): void {
                $translation->update(['slug' => $slug]);
                if ($entry->status === 'published') {
                    $routes = app(RouteSync::class);
                    $routes->sync($entry, $locale, $slug);
                    $routes->syncDescendants($entry, $locale);
                }
                $this->recordLiveEdit($entry, $translation);
            });
        } catch (RouteConflictException $e) {
            $this->toast(__($e->reserved() ? 'entries.slug_reserved' : 'entries.slug_conflict'));

            return;
        }

        $this->toast(__('entries.slug_saved'));
    }

    /**
     * The editor's rule for one field (EntryContentRules), so an inline edit cannot store what the
     * editor would refuse. A refusal is the rule's plain-language message, as a toast: the list's
     * inline fields have no error line of their own.
     */
    private function passesContentRule(string $field, string $value): bool
    {
        $rules = app(EntryContentRules::class);
        $validator = Validator::make([$field => $value], [$field => $rules->rules()[$field]], $rules->messages());
        if ($validator->fails()) {
            $this->toast($validator->errors()->first($field));

            return false;
        }

        return true;
    }

    /** An inline rename of live content changes what visitors see — keep that version, like an editor save. */
    private function recordLiveEdit(Entry $entry, EntryTranslation $translation): void
    {
        if ($entry->status === 'published') {
            app(RecordEntryRevision::class)->forLocale($entry, $translation, RevisionKind::Edit, $this->actingUser());
        }
    }

    public function duplicate(string $ulid): void
    {
        $entry = $this->findLiveEntry($ulid);
        if ($entry === null) {
            $this->toastStaleRow();

            return;
        }
        try {
            $copy = app(DuplicateEntry::class)->handle($entry, $this->actingUser());
        } catch (AuthorizationException $e) {
            // DuplicateEntry words its own refusals (no right to create, outside the limits, in the Trash)
            $this->refuse($e->getMessage() !== '' ? $e->getMessage() : __('entries.err_duplicate_refused'));

            return;
        }
        // Materialize the copied block images into the usage index, exactly as the editor does on save.
        app(EntryBlockMediaReconciler::class)->reconcile($copy);

        $this->toast(__('entries.duplicated', ['noun' => __('entries.noun_'.$entry->type)]));
    }

    /**
     * «Chuyển vào Thùng rác» on a row: done at once, and the message offers «Hoàn tác» for 10 seconds
     * (the Trash keeps the row EntryTrash::days() days besides). It asks first only where the design
     * does: a page with sub-pages, which go with it so their addresses do not break, and the Home page.
     * The question goes to the browser (entries-ask-trash), whose «Chuyển» calls back with $confirmed;
     * the sub-pages are looked up again then, so the answer is about the tree as it is now.
     */
    public function trash(string $ulid, bool $confirmed = false): void
    {
        $entry = $this->findLiveEntry($ulid);   // trashing it again would restart its purge clock
        if ($entry === null) {
            $this->toastStaleRow();

            return;
        }
        if (! $this->allowed('delete', $entry, 'delete')) {
            return;
        }

        $kids = $entry->type === 'page' ? $this->liveSubPages($entry) : [];
        if ($kids === null) {   // too big a branch to move in one go: nothing moves, and the member is told
            $this->refuse(Cast::string(__('entries.trash_kids_too_many', ['max' => $this->subPageCap(), 'depth' => self::TREE_DEPTH])));

            return;
        }
        $home = $this->homeIds([$entry->ulid]) !== [];
        if (! $confirmed && ($kids !== [] || $home)) {
            $this->askTrash($entry, count($kids), $home);

            return;
        }
        // the sub-pages go with their parent or not at all: a parent gone with a child left behind breaks its address
        $user = $this->actingUser();
        foreach ($kids as $kid) {
            if (! $user->can('delete', $kid)) {
                $this->refuse(Cast::string(__('entries.trash_kids_refused')));

                return;
            }
        }

        $rows = [$entry, ...$kids];
        DB::transaction(fn (): mixed => app(CategoryItemCounts::class)->batch(function () use ($rows): bool {
            foreach ($rows as $row) {
                $row->delete();
            }

            return true;
        }));

        $ulids = array_map(static fn (Entry $row): string => $row->ulid, $rows);
        $this->selected = array_values(array_diff($this->selected, $ulids));
        $this->sayTrashed($ulids, count($rows) === 1
            ? Cast::string(__('entries.trashed_one', ['name' => $this->titleOf($entry)]))
            : Cast::string(__('entries.trashed_many', ['count' => count($rows), 'noun' => __('entries.noun_'.$entry->type)])));
    }

    /**
     * «Hoàn tác» on the message of a move to the Trash: every row it moved comes back, each through
     * the same rule and policy as «Khôi phục» (a row restored or purged meanwhile is left out). A
     * branch of sub-pages or several moves joined in one message can hold more than BULK_LIMIT rows:
     * they come back BULK_LIMIT at a time, never refused for their number.
     */
    public function undoTrash(): void
    {
        $ulids = $this->trashUndo ?? [];
        $this->toastMsg = '';
        $this->trashUndo = null;
        if ($ulids === []) {
            return;
        }

        $user = $this->actingUser();
        $run = $this->runBulk(
            'entries.bulk_restored',
            'entries.bulk_skipped_trash',
            fn (Entry $entry): bool => $this->whyNotRestore($entry, $user) === null,
            function (Entry $entry): bool {
                $this->authorize('restore', $entry);

                return $entry->restore();
            },
            $ulids,
            capped: false,
        );
        if ($run === null) {
            return;
        }

        $back = count($run['done']);
        $back === 0
            ? $this->toastStaleRow()
            : $this->say(Cast::string(__('entries.undo_trash_done', ['count' => $back, 'noun' => __('entries.noun_'.$this->entryType)])));
    }

    /** The toast's «Đóng» or its timer: clears only the message it was shown for. */
    public function closeToast(?int $seq = null): void
    {
        if ($seq === null || $seq === $this->toastSeq) {
            $this->toastMsg = '';
            $this->trashUndo = null;
        }
    }

    public function restore(string $ulid): void
    {
        $entry = $this->findTrashedEntry($ulid);
        if ($entry === null) {
            $this->toastStaleRow();

            return;
        }
        if (! $this->allowed('restore', $entry, 'delete')) {
            return;
        }
        $entry->restore();

        $this->toast(__('entries.restored', ['noun' => __('entries.noun_'.$entry->type)]));
    }

    public function forceDelete(string $ulid): void
    {
        $entry = $this->findTrashedEntry($ulid);   // only what is in the Trash is ever purged
        if ($entry === null) {
            $this->toastStaleRow();

            return;
        }
        if (! $this->allowed('forceDelete', $entry, 'delete')) {
            return;
        }
        $entry->forceDelete(); // EntryObserver clears the block-image usage index

        $this->toast(__('entries.destroyed'));
    }

    /** @param  list<string>  $ulids  the current page's ulids (the view passes what is visible). */
    public function toggleSelectAll(array $ulids): void
    {
        $allSelected = $ulids !== [] && array_diff($ulids, $this->selected) === [];
        $this->selected = $allSelected
            ? array_values(array_diff($this->selected, $ulids))
            : array_values(array_unique([...$this->selected, ...$ulids]));
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /**
     * Publish the selected rows, or — for someone without publish rights — send their drafts for
     * review. A row counts as done only when this run moved it (PublishEntry::attempt judges that
     * under the row lock); everything else is reported as skipped, never as an error.
     */
    public function bulkPublish(): void
    {
        $user = $this->actingUser();
        $locale = app(SiteLocale::class)->default();
        $publish = app(PublishEntry::class);
        $doneKey = $user->can('entries.publish') ? 'entries.bulk_published' : 'entries.bulk_sent';

        $this->reportBulk($this->runBulk(
            $doneKey,
            'entries.bulk_skipped',
            fn (Entry $entry): bool => $this->whyNotPublish($entry, $user, $locale) === null,
            fn (Entry $entry): bool => $publish->attempt($entry, $locale, $user)['moved'],
        ), $doneKey, 'entries.bulk_skipped');
    }

    /** «Gỡ khỏi website»: a published row leaves the website (Lưu trữ), through the same status move as the row's menu. */
    public function bulkUnpublish(): void
    {
        $user = $this->actingUser();
        $transition = app(TransitionEntryStatus::class);
        $homes = $this->homeIds($this->selected);

        $this->reportBulk($this->runBulk(
            'entries.bulk_unpublished',
            'entries.bulk_skipped',
            fn (Entry $entry): bool => $this->whyNotUnpublish($entry, $user, $homes) === null,
            function (Entry $entry) use ($transition, $user): bool {
                $transition->handle($entry, EntryStatus::Archived, $user);

                return true;
            },
        ), 'entries.bulk_unpublished', 'entries.bulk_skipped', $homes !== []);
    }

    public function bulkDraft(): void
    {
        $user = $this->actingUser();
        $transition = app(TransitionEntryStatus::class);
        $homes = $this->homeIds($this->selected);

        $this->reportBulk($this->runBulk(
            'entries.bulk_drafted',
            'entries.bulk_skipped',
            fn (Entry $entry): bool => $this->whyNotDraft($entry, $user, $homes) === null,
            function (Entry $entry) use ($transition, $user): bool {
                $transition->handle($entry, EntryStatus::Draft, $user);

                return true;
            },
        ), 'entries.bulk_drafted', 'entries.bulk_skipped', $homes !== []);
    }

    public function bulkDuplicate(): void
    {
        $user = $this->actingUser();
        $duplicate = app(DuplicateEntry::class);
        $reconciler = app(EntryBlockMediaReconciler::class);

        $this->reportBulk($this->runBulk(
            'entries.bulk_duplicated',
            'entries.bulk_skipped',
            fn (Entry $entry): bool => $this->whyNotDuplicate($entry, $user) === null,
            function (Entry $entry) use ($duplicate, $reconciler, $user): bool {
                $reconciler->reconcile($duplicate->handle($entry, $user));

                return true;
            },
        ), 'entries.bulk_duplicated', 'entries.bulk_skipped');
    }

    /**
     * «Chuyển vào Thùng rác (N)»: at once, like the row's, with «Hoàn tác» on the message. Only the
     * rows ticked go, as in the design. The Home page is skipped, and so is a page that still has
     * live sub-pages: they would stay behind it with a broken address, so it is moved from its own
     * row, which takes its sub-pages along (the message says how to remove either).
     */
    public function bulkTrash(): void
    {
        $user = $this->actingUser();
        $homes = $this->homeIds($this->selected);
        $parents = $this->parentIds($this->selected);

        $run = $this->runBulk(
            'entries.bulk_trashed',
            'entries.bulk_skipped',
            fn (Entry $entry): bool => $this->whyNotTrash($entry, $user, $homes, $parents) === null,
            function (Entry $entry): bool {
                $this->authorize('delete', $entry);

                return (bool) $entry->delete();
            },
        );
        if ($run === null) {
            return;
        }

        $message = $this->bulkMessage($run, 'entries.bulk_trashed', 'entries.bulk_skipped', $homes !== [], $parents !== []);
        $run['done'] === [] ? $this->toast($message) : $this->sayTrashed($run['done'], $message);
    }

    public function bulkRestore(): void
    {
        $user = $this->actingUser();

        $this->reportBulk($this->runBulk(
            'entries.bulk_restored',
            'entries.bulk_skipped_trash',
            fn (Entry $entry): bool => $this->whyNotRestore($entry, $user) === null,
            function (Entry $entry): bool {
                $this->authorize('restore', $entry);

                return $entry->restore();
            },
        ), 'entries.bulk_restored', 'entries.bulk_skipped_trash');
    }

    public function bulkForceDelete(): void
    {
        $user = $this->actingUser();

        $this->reportBulk($this->runBulk(
            'entries.bulk_destroyed',
            'entries.bulk_skipped_trash',
            fn (Entry $entry): bool => $this->whyNotForceDelete($entry, $user) === null,
            function (Entry $entry): bool {
                $this->authorize('forceDelete', $entry);

                return (bool) $entry->forceDelete();
            },
        ), 'entries.bulk_destroyed', 'entries.bulk_skipped_trash');
    }

    /*
     * Which rows each bulk action is for, and in the design's words why a row is not: the row's
     * kind (in the Trash or not), its state, and this person's right to change it. One rule per
     * action, so the count on a button («Đăng (3)») and the skip line under the bar use the very
     * rule the action runs by. Restore and Xóa vĩnh viễn are for the Trash only; everything else is
     * for live content only ("không đụng bài trong Thùng rác"). The actions still check the right
     * themselves — these rules only keep the wrong rows out. Each answer is null (the row is one
     * the action is for) or the lang key of the reason.
     */

    /**
     * A workflow move is for a row standing at the move's stage that this member may move
     * (EntryWorkflowResolver::movesFor, the rule the pill offers by); the engine judges it again.
     * The stage reason names the stage, so it is returned already worded.
     */
    private function whyNotMove(Entry $entry, User $user, Workflow $workflow, string $key): ?string
    {
        if ($entry->trashed()) {
            return 'entries.skip_in_trash';
        }
        foreach (app(EntryWorkflowResolver::class)->movesFor($workflow, $entry, $user) as $move) {
            if ($move->key === $key) {
                return null;
            }
        }
        $transition = $workflow->transitions->firstWhere('key', $key);
        if ($transition instanceof WorkflowTransition && $transition->from_state !== EntryWorkflowResolver::originIn($workflow, $entry)) {
            $stage = $workflow->states->firstWhere('key', $transition->from_state);

            return Cast::string(__('entries.skip_not_at_step', ['step' => $stage instanceof WorkflowState ? $stage->label : $transition->from_state]));
        }

        return $this->whyRefused($entry, $user, 'edit');
    }

    /**
     * @param  array<int, true>  $homes  the Home page among the rows (homeIds())
     * @param  array<int, true>  $parents  the pages among the rows with live sub-pages (parentIds())
     */
    private function whyNotTrash(Entry $entry, User $user, array $homes, array $parents): ?string
    {
        return match (true) {
            $entry->trashed() => 'entries.skip_in_trash',
            isset($homes[$entry->id]) => 'entries.skip_home',
            isset($parents[$entry->id]) => 'entries.skip_has_kids',
            ! $user->can('delete', $entry) => $this->whyRefused($entry, $user, 'delete'),
            default => null,
        };
    }

    private function whyNotRestore(Entry $entry, User $user): ?string
    {
        return match (true) {
            ! $entry->trashed() => 'entries.skip_not_in_trash',
            ! $user->can('restore', $entry) => $this->whyRefused($entry, $user, 'delete'),
            default => null,
        };
    }

    private function whyNotForceDelete(Entry $entry, User $user): ?string
    {
        return match (true) {
            ! $entry->trashed() => 'entries.skip_not_in_trash',
            ! $user->can('forceDelete', $entry) => $this->whyRefused($entry, $user, 'delete'),
            default => null,
        };
    }

    /**
     * A draft is already a draft: "moving" it again would only write a misleading audit row. The
     * Home page stays published (the design: it is never taken off by a bulk move).
     *
     * @param  array<int, true>  $homes
     */
    private function whyNotDraft(Entry $entry, User $user, array $homes): ?string
    {
        return match (true) {
            $entry->trashed() => 'entries.skip_in_trash',
            ! $user->can('publish', $entry) => $this->whyRefused($entry, $user, 'edit'),
            isset($homes[$entry->id]) => 'entries.skip_home',
            $entry->status === EntryStatus::Draft->value => 'entries.skip_draft',
            default => null,
        };
    }

    /**
     * «Gỡ khỏi website» is for what visitors can see now: a published row (not the Home page).
     *
     * @param  array<int, true>  $homes
     */
    private function whyNotUnpublish(Entry $entry, User $user, array $homes): ?string
    {
        return match (true) {
            $entry->trashed() => 'entries.skip_in_trash',
            ! $user->can('publish', $entry) => $this->whyRefused($entry, $user, 'edit'),
            isset($homes[$entry->id]) => 'entries.skip_home',
            $entry->status !== EntryStatus::Published->value => 'entries.skip_not_live',
            default => null,
        };
    }

    private function whyNotDuplicate(Entry $entry, User $user): ?string
    {
        return match (true) {
            $entry->trashed() => 'entries.skip_in_trash',
            ! $user->can('create', Entry::class) => 'entries.skip_role_create',
            ! app(CategoryScopes::class)->coversEntry($user, $entry) => 'entries.skip_scope',
            default => null,
        };
    }

    /**
     * A publisher's Đăng is for anything not yet fully live. Fully live means the entry AND its
     * default-language text are published: the default language can change, so an entry can be
     * live while the text visitors now get by default is still a draft — Đăng has work to do
     * there. Skipping a live row here, before PublishEntry, keeps a re-click from writing another
     * Publish version, audit row and route sync for nothing. A sender's Gửi duyệt is for their
     * own drafts only (EntryPolicy::submit).
     */
    private function whyNotPublish(Entry $entry, User $user, string $locale): ?string
    {
        if ($entry->trashed()) {
            return 'entries.skip_in_trash';
        }

        if ($user->can('publish', $entry)) {
            return $entry->status === EntryStatus::Published->value
                && $entry->translationFor($locale)?->status === EntryStatus::Published->value ? 'entries.skip_published' : null;
        }
        if ($user->can('entries.publish')) {
            return $this->whyRefused($entry, $user, 'edit');   // a publisher outside their category limits
        }

        return match (true) {
            ! $user->can('update', $entry) => $this->whyRefused($entry, $user, 'edit'),
            $entry->status !== EntryStatus::Draft->value => 'entries.skip_not_draft',
            ! $user->can('submit', $entry) => 'entries.skip_role_edit',
            default => null,
        };
    }

    /**
     * Why the policy says no to this member on this row, in the skip line's short words: outside
     * their category limits, someone else's record for an own-only right, or not a right their role has.
     *
     * @param  'edit'|'delete'  $verb
     */
    private function whyRefused(Entry $entry, User $user, string $verb): string
    {
        if (! app(CategoryScopes::class)->coversEntry($user, $entry)) {
            return 'entries.skip_scope';
        }
        $own = $entry->author_id !== null && $entry->author_id === $user->id;

        return ! $own && $user->can('entries.'.$verb.'.own') ? 'entries.skip_others' : 'entries.skip_role_'.$verb;
    }

    /**
     * Run one bulk action over the selection and report it in ONE toast — a second toast would
     * replace the first: what was done, then what was skipped, governed or failed.
     *
     * - The rows are locked up front, in id order: two overlapping runs queue behind each other
     *   instead of deadlocking, and every rule judges the row as it is now.
     * - A row the rule rejects is skipped, never acted on: it may have been selected on another
     *   tab, or forged. A row the action refuses (AuthorizationException) is skipped too.
     * - Each row runs in its own savepoint, so a row that fails leaves nothing half-done; it is
     *   reported for the logs and counted, and the rest carry on.
     * - The category totals the rows' changes move (trash, restore, purge) are gathered over the
     *   run and written once per category at its end (CategoryItemCounts::batch); a row whose
     *   savepoint rolled back drops its share.
     * - A deadlock or serialization failure is different. PostgreSQL has aborted the transaction,
     *   and Laravel rethrows it out of a nested transaction WITHOUT rolling back to the savepoint,
     *   so not one more statement could run. The whole run is rolled back to the savepoint taken
     *   before it — the request transaction is usable again (the list still renders) — the
     *   selection is kept, and the person is asked to try again.
     *
     * The selection is cleared once the run is done; «Hoàn tác» passes its own rows and leaves the
     * selection as it is.
     *
     * @param  callable(Entry): bool  $eligible  the row is one this action is for
     * @param  callable(Entry): bool  $action  runs the action; true when the row really changed
     * @param  list<string>|null  $ulids  the rows to act on; the selection when null
     * @param  bool  $capped  refuse more than BULK_LIMIT rows (a selection); false works through any
     *                        number BULK_LIMIT at a time, in one transaction (the rows «Hoàn tác» names)
     * @return array{tally: array<string, int>, done: list<string>}|null what the run did; null when
     *                                                                   it did nothing (already told)
     */
    private function runBulk(string $doneKey, string $skippedKey, callable $eligible, callable $action, ?array $ulids = null, bool $capped = true): ?array
    {
        $fromSelection = $ulids === null;
        $ulids ??= $this->selected;
        if ($capped && count($ulids) > self::BULK_LIMIT) {
            $this->toast(__('entries.bulk_too_many', ['max' => self::BULK_LIMIT]));

            return null;
        }

        $tally = [$doneKey => 0, $skippedKey => 0, 'entries.bulk_governed' => 0, 'entries.bulk_failed' => 0];
        $done = [];
        $counts = app(CategoryItemCounts::class);

        DB::beginTransaction();
        try {
            $counts->batch(function () use (&$tally, &$done, $ulids, $counts, $eligible, $action, $doneKey, $skippedKey): void {
                foreach ($this->selectedChunks($ulids) as $chunk) {
                    foreach ($chunk as $entry) {
                        $key = $this->bulkRow($counts, $entry, $eligible, $action, $doneKey, $skippedKey);
                        $tally[$key]++;
                        if ($key === $doneKey) {
                            $done[] = $entry->ulid;
                        }
                    }
                }
            });
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            if (! ConcurrencyError::is($e)) {
                throw $e;
            }
            $this->toast(__('entries.bulk_conflict'));

            return null;
        }

        if ($fromSelection) {
            $this->selected = [];
        }

        return ['tally' => $tally, 'done' => $done];
    }

    /**
     * The one toast of a bulk run: what was done, then what was skipped, governed or failed, and
     * why the Home page, or a page with sub-pages, was left out when one was among the rows.
     *
     * @param  array{tally: array<string, int>, done: list<string>}  $run
     * @param  array<string, string>  $replace
     */
    private function bulkMessage(array $run, string $doneKey, string $skippedKey, bool $homeAmong = false, bool $parentAmong = false, array $replace = []): string
    {
        $tally = $run['tally'];
        $parts = [Cast::string(__($doneKey, ['count' => $tally[$doneKey]] + $replace))];
        foreach ([$skippedKey, 'entries.bulk_governed', 'entries.bulk_failed'] as $key) {
            if ($tally[$key] > 0) {
                $parts[] = Cast::string(__($key, ['count' => $tally[$key]]));
            }
        }
        if ($homeAmong && $tally[$skippedKey] > 0) {
            $parts[] = Cast::string(__('entries.skip_home_note'));
        }
        if ($parentAmong && $tally[$skippedKey] > 0) {
            $parts[] = Cast::string(__('entries.skip_kids_note'));
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array{tally: array<string, int>, done: list<string>}|null  $run
     * @param  array<string, string>  $replace  more words for the done line («Đã “Duyệt đăng” 3 bài viết»)
     */
    private function reportBulk(?array $run, string $doneKey, string $skippedKey, bool $homeAmong = false, array $replace = []): void
    {
        if ($run !== null) {
            $this->toast($this->bulkMessage($run, $doneKey, $skippedKey, $homeAmong, replace: $replace));
        }
    }

    /**
     * The message of a move to the Trash, with «Hoàn tác». A move made while the last one's message
     * is still up joins it, as in the design: one message for all of them, whose «Hoàn tác» brings
     * every one back and whose 10 seconds start again.
     *
     * @param  list<string>  $ulids
     */
    private function sayTrashed(array $ulids, string $message): void
    {
        $before = $this->toastMsg !== '' ? ($this->trashUndo ?? []) : [];
        $all = array_values(array_unique([...$before, ...$ulids]));
        if ($before !== []) {
            $message = Cast::string(__('entries.trashed_many', ['count' => count($all), 'noun' => __('entries.noun_'.$this->entryType)]));
        }
        $this->say($message, $all);
    }

    /** @param  list<string>|null  $undo  the rows «Hoàn tác» brings back out of the Trash */
    private function say(string $message, ?array $undo = null): void
    {
        $this->toastMsg = $message;
        $this->toastTone = 'success';
        $this->toastSeq++;
        $this->trashUndo = $undo;
    }

    /**
     * Ask before a row goes to the Trash, in the design's words: a page with sub-pages (they go
     * with it) or the Home page (the website has none until it comes back).
     */
    private function askTrash(Entry $entry, int $kids, bool $home): void
    {
        $homeText = Cast::string(__('entries.trash_ask_home_text'));
        if ($kids > 0) {
            $title = __('entries.trash_ask_kids_title', ['count' => $kids]);
            $text = ($home ? $homeText.' ' : '').Cast::string(__('entries.trash_ask_kids_text', ['days' => EntryTrash::days()]));
            $ok = __('entries.trash_ask_kids_ok', ['count' => $kids + 1]);
        } else {
            $title = __('entries.trash_ask_title', ['name' => $this->titleOf($entry)]);
            $text = $homeText;
            $ok = __('entries.action_trash');
        }

        $this->dispatch('entries-ask-trash', ulid: $entry->ulid, title: Cast::string($title), text: $text, ok: Cast::string($ok), cancel: Cast::string(__('admin.cancel')));
    }

    /**
     * Every live page under this one (its sub-pages, theirs, …), read level by level as RouteSync
     * walks the tree, with a cap on depth and size against a looped or huge tree. A branch past the
     * cap — more than subPageCap() pages in all, or deeper than TREE_DEPTH levels — is null: the whole
     * branch is too big to move at once, and no part of it is moved, so no sub-page is ever left
     * behind its parent without a word.
     *
     * @return list<Entry>|null
     */
    private function liveSubPages(Entry $page): ?array
    {
        $cap = $this->subPageCap();
        $found = [];
        $seen = [$page->id => true];
        $frontier = [$page->id];
        for ($depth = 0; $frontier !== []; $depth++) {
            $room = $cap - count($found);
            // one more than there is room for: finding it means the branch is over the cap
            $children = Entry::query()->where('type', 'page')->whereIn('parent_id', $frontier)->orderBy('id')
                ->limit($room + 1)
                ->get(['id', 'site_id', 'ulid', 'type', 'status', 'parent_id', 'author_id', 'deleted_at', 'updated_at']);
            // past the size cap, or a level below the deepest one a branch may have (a 12-level branch
            // passes: the 13th read only looks for pages under it)
            if ($children->count() > $room || ($depth >= self::TREE_DEPTH && $children->isNotEmpty())) {
                return null;
            }
            $frontier = [];
            foreach ($children as $child) {
                if (! isset($seen[$child->id])) {
                    $seen[$child->id] = true;
                    $found[] = $child;
                    $frontier[] = $child->id;
                }
            }
        }

        return $found;
    }

    /**
     * The most sub-pages one move to the Trash takes along: TREE_CHILDREN. The undocumented setting
     * cms.trash_subpages_max lowers it, so a test can reach the cap without building a thousand pages.
     */
    private function subPageCap(): int
    {
        return max(1, min(self::TREE_CHILDREN, Cast::int(config('cms.trash_subpages_max'), self::TREE_CHILDREN)));
    }

    /**
     * Which of these rows is the website's Home page (a page whose address is the root «/»: the
     * home slug in a language), in one query.
     *
     * @param  list<string>  $ulids
     * @return array<int, true> entry id => true
     */
    private function homeIds(array $ulids): array
    {
        if ($ulids === []) {
            return [];
        }
        $ids = EntryTranslation::query()
            ->whereIn('slug', [PathBuilder::HOME_SLUG, ''])
            ->whereIn('entry_id', Entry::withTrashed()->select('id')->where('type', 'page')->whereIn('ulid', $ulids))
            ->distinct()
            ->pluck('entry_id');

        $homes = [];
        foreach ($ids as $id) {
            $homes[Cast::int($id)] = true;
        }

        return $homes;
    }

    /**
     * Which of these rows is a page that still has a live sub-page (one query): the bulk move to the
     * Trash leaves it out, as the row's move would take the sub-pages with it.
     *
     * @param  list<string>  $ulids
     * @return array<int, true> entry id => true
     */
    private function parentIds(array $ulids): array
    {
        if ($ulids === []) {
            return [];
        }
        $ids = Entry::query()->where('type', 'page')
            ->whereIn('parent_id', Entry::withTrashed()->select('id')->where('type', 'page')->whereIn('ulid', $ulids))
            ->distinct()
            ->pluck('parent_id');

        $parents = [];
        foreach ($ids as $id) {
            $parents[Cast::int($id)] = true;
        }

        return $parents;
    }

    /** The row's title in the website's default language (one query), for a message that names it. */
    private function titleOf(Entry $entry): string
    {
        return Cast::string($entry->translationFor(app(SiteLocale::class)->default())?->title, Cast::string(__('entries.untitled')));
    }

    /**
     * One row of a bulk run, in its own savepoint.
     *
     * @param  callable(Entry): bool  $eligible
     * @param  callable(Entry): bool  $action
     * @return string the tally key the row counts under
     */
    private function bulkRow(CategoryItemCounts $counts, Entry $entry, callable $eligible, callable $action, string $doneKey, string $skippedKey): string
    {
        if (! $eligible($entry)) {
            return $skippedKey;
        }

        try {
            return $counts->batch(fn (): bool => DB::transaction(fn (): bool => $action($entry))) ? $doneKey : $skippedKey;
        } catch (WorkflowGovernedException) {
            return 'entries.bulk_governed';   // left as is: only the editor's approval block may move it
        } catch (AuthorizationException) {
            return $skippedKey;               // not theirs to change: an expected answer, not a fault
        } catch (Throwable $e) {
            if (ConcurrencyError::is($e)) {
                throw $e;                     // the transaction is aborted: runBulk() abandons the run
            }
            // A slug clash or a database failure: its savepoint is rolled back, so report it and
            // carry on — the toast says how many rows failed.
            report($e);

            return 'entries.bulk_failed';
        }
    }

    /**
     * The rows of a run, locked BULK_LIMIT at a time in ascending id order across the whole run (see
     * runBulk()): up to BULK_LIMIT rows are one locked read, as before; more are first listed by id
     * (no lock), then locked chunk by chunk, so the order matches the purge's and the two never deadlock.
     *
     * @param  list<string>  $ulids
     * @return iterable<EloquentCollection<int, Entry>>
     */
    private function selectedChunks(array $ulids): iterable
    {
        if (count($ulids) <= self::BULK_LIMIT) {
            yield $this->selectedEntries('ulid', $ulids);

            return;
        }
        $ids = [];
        foreach (array_chunk($ulids, self::BULK_LIMIT * 10) as $part) {
            foreach (Entry::withTrashed()->whereIn('ulid', $part)->pluck('id') as $id) {
                $ids[] = Cast::int($id);
            }
        }
        sort($ids);
        foreach (array_chunk($ids, self::BULK_LIMIT) as $chunk) {
            yield $this->selectedEntries('id', $chunk);
        }
    }

    /**
     * Rows of a run, locked in id order (see runBulk()). Trashed rows are loaded too, so each rule
     * can tell a row of the wrong kind (skipped) from one that no longer exists (left out).
     *
     * @param  'ulid'|'id'  $column
     * @param  list<string>|list<int>  $keys
     * @return EloquentCollection<int, Entry>
     */
    private function selectedEntries(string $column, array $keys): EloquentCollection
    {
        // The row's primary category is read by every rule for a member limited to some
        // categories, and by the category totals a trash, restore or purge moves: load it with the
        // rows (one query) rather than once per row.
        return Entry::withTrashed()->whereIn($column, $keys)->orderBy('id')->lockForUpdate()
            ->with(self::primaryCategoryOnly())
            ->get();
    }

    /**
     * The eager load of each row's primary category alone, so a per-row "may change" check costs
     * no query per row.
     *
     * @return array<string, Closure(Relation<*, *, *>): void>
     */
    private static function primaryCategoryOnly(): array
    {
        return ['taxonomies' => static function (Relation $primary): void {
            if ($primary instanceof BelongsToMany) {
                // What the limits read (id, path) and what the section of a post is derived from
                // (parent_id, vocabulary): an action run on these rows asks for its workflow.
                $primary->wherePivot('position', 0)
                    ->select(['taxonomies.id', 'taxonomies.parent_id', 'taxonomies.vocabulary', 'taxonomies.path']);
            }
        }];
    }

    /*
     * A row action names its row by ulid, as the list showed it. By the click the row may have
     * changed kind (a colleague trashed or restored it) or be gone; a forged ulid looks the same.
     * The finders then return null and the action answers toastStaleRow(): one plain message for
     * every case, and the list re-renders as it is now — never a 404 over the page.
     */

    /**
     * Make workflow move $key on $entry through the engine, in the language the entry has: the site
     * default when it has that text, otherwise its first translation (a post written only in another
     * language would otherwise fail on the missing default text).
     *
     * @throws InvalidTransitionException|AuthorizationException|RouteConflictException|EntryNotGovernedException
     */
    private function move(Entry $entry, string $key, string $note): void
    {
        $default = app(SiteLocale::class)->default();
        $locale = $entry->translationFor($default) !== null
            ? $default
            : Cast::string($entry->translations()->orderBy('id')->value('locale'), $default);

        app(ApplyEntryWorkflowTransition::class)->handle($entry, $locale, $this->actingUser(), $key, $note !== '' ? $note : null);
    }

    /**
     * The listed section's active workflow with its stages and moves loaded (two queries, once per
     * request), or null when the section runs none. Read again on every request: a workflow switched
     * off while the list stayed open is seen at once.
     */
    private function sectionWorkflow(): ?Workflow
    {
        if (! $this->workflowRead) {
            $this->mountedWorkflow = $this->entryType === 'post' && is_string($this->section) && $this->section !== ''
                ? app(WorkflowEngine::class)->activeFor($this->section) : null;
            $this->workflowRead = true;
        }
        $this->mountedWorkflow?->loadMissing(self::flowParts());

        return $this->mountedWorkflow;
    }

    /**
     * The workflow's stages and moves as the list reads them (the step column, the pill's moves and
     * the bulk bar's), in their order.
     *
     * @return array<string, Closure(Relation<*, *, *>): mixed>
     */
    private static function flowParts(): array
    {
        return [
            'states' => static fn (Relation $q): mixed => $q->select(['id', 'workflow_id', 'key', 'label', 'sort_order', 'is_initial', 'is_published', 'is_terminal'])->orderBy('sort_order')->orderBy('id'),
            'transitions' => static fn (Relation $q): mixed => $q->select(['id', 'workflow_id', 'key', 'label', 'from_state', 'to_state', 'required_permission', 'requires_note'])->orderBy('id'),
        ];
    }

    /**
     * Each row's workflow moves for this member (the pill's menu): the engine's moves from the row's
     * stage that this member may make, from the loaded workflow (no query per row). The first move
     * that puts a not-yet-live post on the website asks first, and so does one that takes a live
     * post off it (`off`: no template brings an archived post back from the list); a move that
     * demands a reason opens the reason dialog.
     *
     * @param  array<int|string, array{entry: Entry, depth: int}>  $items
     * @return array<string, list<array{key: string, label: string, note: bool, first: bool, off: bool, icon: string}>>
     */
    private static function rowMoves(Workflow $workflow, array $items, User $user): array
    {
        $resolver = app(EntryWorkflowResolver::class);
        $out = [];
        foreach ($items as $item) {
            $entry = $item['entry'];
            if ($entry->trashed()) {
                continue;
            }
            $moves = [];
            foreach ($resolver->movesFor($workflow, $entry, $user) as $move) {
                $to = $workflow->states->firstWhere('key', $move->to_state);
                $live = $to instanceof WorkflowState && $to->is_published;
                $moves[] = [
                    'key' => $move->key,
                    'label' => $move->label,
                    'note' => $move->requires_note,
                    'first' => ! $move->requires_note && $live && $entry->status !== EntryStatus::Published->value,
                    'off' => ! $move->requires_note && ! $live && $entry->status === EntryStatus::Published->value,
                    'icon' => match (true) {
                        $move->requires_note => 'ri-arrow-go-back',
                        $live => 'ri-check-double',
                        $to instanceof WorkflowState && $to->is_terminal => 'ri-archive',
                        default => 'ri-send-plane',
                    },
                ];
            }
            $out[$entry->ulid] = $moves;
        }

        return $out;
    }

    /** A row that is not in the Trash — anything else is null, like an unknown ulid. */
    private function findLiveEntry(string $ulid): ?Entry
    {
        return Entry::query()->where('ulid', $ulid)->first();
    }

    /** A row in the Trash — a live row is null here, so it can never be restored or purged by mistake. */
    private function findTrashedEntry(string $ulid): ?Entry
    {
        return Entry::onlyTrashed()->where('ulid', $ulid)->first();
    }

    private function toastStaleRow(): void
    {
        $this->toast(__('entries.row_stale'));
    }

    private function actingUser(): User
    {
        $user = Auth::user();
        assert($user instanceof User);

        return $user;
    }

    private function toast(string $message): void
    {
        $this->closeToast();
        $this->dispatch('bs-toast', text: $message);
    }

    /**
     * Ask the policy for $ability on this row; a refusal is answered with the reason, in a toast that
     * stays until it is closed, instead of the error page Livewire would show.
     *
     * @param  'edit'|'delete'  $verb  what the reason speaks of
     */
    private function allowed(string $ability, Entry $entry, string $verb): bool
    {
        try {
            $this->authorize($ability, $entry);
        } catch (AuthorizationException) {
            $this->refuse($this->refusalFor($entry, $verb));

            return false;
        }

        return true;
    }

    /** A refusal: the lock mark, and it stays until the member closes it (it says what to do). */
    private function refuse(string $message): void
    {
        $this->closeToast();
        $this->dispatch('bs-toast', text: $message, icon: self::lockIcon(), stay: true, close: __('admin.close'));
    }

    private static function lockIcon(): string
    {
        return Blade::render('<x-bs-admin::icon name="ri-lock" class="size-4" />');
    }

    /**
     * Why this member may not $verb this row, in the design's words: outside their category limits
     * (a page: every page, for a limited member), someone else's record for an own-only right, or
     * not a right their role has.
     *
     * @param  'edit'|'delete'|'status'  $verb
     */
    private function refusalFor(Entry $entry, string $verb): string
    {
        $user = $this->actingUser();
        $scopes = app(CategoryScopes::class);
        if (! $scopes->coversEntry($user, $entry)) {
            return self::scopeWhy($entry->type, $scopes->refusal($user));
        }
        if ($verb === 'status') {
            return Cast::string(__('entries.refused_status'));
        }
        $noun = __('entries.noun_'.$entry->type);
        $own = $entry->author_id !== null && $entry->author_id === $user->id;

        return Cast::string(! $own && $user->can('entries.'.$verb.'.own')
            ? __('entries.refused_others_'.$verb, ['noun' => $noun])
            : __('entries.refused_role_'.$verb, ['noun' => $noun]));
    }

    /**
     * The limits sentence for a row of $type: for a page it adds that pages are out of reach (a
     * page has no category, so no limit ever covers it).
     */
    private static function scopeWhy(string $type, string $refusal): string
    {
        return $type === 'page'
            ? Cast::string(__('entries.scope_page_refusal', ['why' => preg_replace('/[.。]$/u', '', $refusal) ?? $refusal]))
            : $refusal;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function toggleIn(array $values, string $value): array
    {
        $index = array_search($value, $values, true);
        if ($index !== false) {
            unset($values[$index]);

            return array_values($values);
        }

        $values[] = $value;

        return $values;
    }

    /**
     * Keep this member's Cột / Hiện trên thẻ choice in their own preferences, nobody else's. One UPDATE
     * writes just this list's entry inside preferences.lists, so whatever else was saved since the screen
     * opened (the theme, another list's choice, from another tab) is kept rather than overwritten.
     */
    private function saveView(): void
    {
        $user = $this->actingUser();

        // preferences and preferences.lists are made objects first (a missing or odd value starts empty), then
        // only lists.<this list> is set
        DB::connection($user->getConnectionName())->update(<<<SQL
            update {$user->getTable()} set preferences = jsonb_set(
                jsonb_set(
                    case when jsonb_typeof(preferences) = 'object' then preferences else '{}'::jsonb end,
                    '{lists}',
                    case when jsonb_typeof(preferences->'lists') = 'object' then preferences->'lists' else '{}'::jsonb end,
                    true
                ),
                array['lists', ?]::text[],
                ?::jsonb,
                true
            ) where id = ?
            SQL,
            [$this->viewKey, json_encode($this->savedView(), JSON_THROW_ON_ERROR), $user->getKey()],
        );
    }

    /**
     * What is kept of the member's view choices, with the columns the list offered then, so a column
     * added later starts as its default says for them.
     *
     * @return array{cols: list<string>, card: list<string>, xbtn: bool, offered: list<string>}
     */
    private function savedView(): array
    {
        return ['cols' => $this->cols, 'card' => $this->card, 'xbtn' => $this->xbtn, 'offered' => $this->colsHere()];
    }

    /**
     * Each row's place in the section's approval workflow («Bước 2/3») and what comes next («Tiếp: Duyệt»,
     * the first move from there that needs no note), or «Bước cuối». The steps and moves are read once for
     * the page (two queries), never per row; a record that has not joined the workflow stands on its first step.
     *
     * @param  array<int|string, array{entry: Entry, depth: int}>  $items
     * @return array<string, array{text: string, next: string}>
     */
    private static function rowSteps(Workflow $workflow, array $items): array
    {
        $workflow->loadMissing(self::flowParts());
        $keys = array_values(array_map(Cast::string(...), $workflow->states->pluck('key')->all()));
        $out = [];
        foreach ($items as $item) {
            $entry = $item['entry'];
            // the stage the editor stands the record at: its stored one while the workflow still defines it, a live
            // record at the published stage, any other at the first (EntryWorkflowResolver::originIn)
            try {
                $at = EntryWorkflowResolver::originIn($workflow, $entry);
            } catch (RuntimeException) {
                $at = null;
            }
            $n = $at === null ? false : array_search($at, $keys, true);
            $n = is_int($n) ? $n : false;
            $next = $workflow->transitions->first(static fn (WorkflowTransition $t): bool => $t->from_state === $at && ! $t->requires_note);
            $out[$entry->ulid] = [
                'text' => $n === false ? '—' : Cast::string(__('entries.step_of', ['n' => $n + 1, 'm' => count($keys)])),
                'next' => $at === null ? '—' : ($next instanceof WorkflowTransition ? Cast::string(__('entries.step_next', ['label' => $next->label])) : Cast::string(__('entries.step_last'))),
            ];
        }

        return $out;
    }

    /**
     * The rows' relations, the translations with their excerpt while the member shows "Mô tả" (read in the
     * same query, never one per row).
     *
     * @param  list<string>  $relations
     * @return list<string>
     */
    private function rowRelations(array $relations): array
    {
        return in_array('desc', $this->cols, true)
            ? array_map(static fn (string $r): string => str_starts_with($r, 'translations:') ? $r.',excerpt' : $r, $relations)
            : $relations;
    }

    /**
     * The columns this list may show from "Cột": Chuyên mục and Bước duyệt are a post list's, Mẫu trang
     * a page list's.
     *
     * @return list<string>
     */
    private function colsHere(): array
    {
        return array_values(array_diff(self::COLS, $this->entryType === 'post' ? ['tpl'] : ['cat', 'step']));
    }

    /**
     * The details this list offers in "Hiện trên thẻ" (Chuyên mục is a post list's).
     *
     * @return list<string>
     */
    private function cardHere(): array
    {
        return $this->entryType === 'post' ? self::CARD : array_values(array_diff(self::CARD, ['cat']));
    }

    /**
     * The values of $values the screen knows, in the screen's own order.
     *
     * @param  array<mixed>  $values
     * @param  list<string>  $known
     * @return list<string>
     */
    private static function known(array $values, array $known): array
    {
        return array_values(array_intersect($known, array_filter($values, 'is_string')));
    }

    /** Public properties come from the browser: keep only values the screen knows. */
    private function clean(): void
    {
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'all';
        $this->transF = self::known($this->transF, self::TRANS);
        $this->langF = array_values(array_unique(array_filter($this->langF, 'is_string')));
        $this->creatorF = array_values(array_unique(array_filter($this->creatorF, 'is_string')));
        $this->templateF = array_values(array_unique(array_filter($this->templateF, 'is_string')));
        $this->catF = $this->entryType === 'post' ? array_values(array_unique(array_filter($this->catF, 'is_string'))) : [];
        if ($this->entryType !== 'post') {
            $this->when = $this->from = $this->to = ''; // Thời gian is a post filter (pages have no publish date to pick by)
        }
        $this->from = self::dateOr($this->from);
        $this->to = self::dateOr($this->to);
        if ($this->from !== '' && $this->to !== '' && $this->from > $this->to) {
            [$this->from, $this->to] = [$this->to, $this->from]; // dates picked the wrong way round mean the same span
        }
        $ranged = $this->from !== '' || $this->to !== '';
        $this->when = $ranged ? self::RANGE : (in_array($this->when, self::WHEN, true) ? $this->when : '');
        if ($this->sortKey !== '' && ! $this->sortsHere($this->sortKey)) {
            $this->sortKey = $this->sortDir = ''; // a direction means nothing without its order: back to the default, both
        }
        $this->sortDir = in_array($this->sortDir, ['asc', 'desc'], true) ? $this->sortDir : '';
    }

    /** "2026-10-08" as the date field sends it, a real day; anything else is dropped. */
    private static function dateOr(string $value): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
    }

    private function sortsHere(string $key): bool
    {
        return array_key_exists($key, self::SORTS) && (self::SORTS[$key] === null || self::SORTS[$key] === $this->entryType);
    }

    /** Dates and views read newest / most first; names, order, status and people A to Z. */
    private static function naturalDir(string $key): string
    {
        return in_array($key, ['updated', 'pub', 'views'], true) ? 'desc' : 'asc';
    }

    /** The type's own default: pages in their order on the website, posts last edited first. */
    private function defaultSortKey(): string
    {
        return $this->entryType === 'page' ? 'order' : 'updated';
    }

    /**
     * The sort in force: [key, direction].
     *
     * @return array{0: string, 1: string}
     */
    private function sorting(): array
    {
        $key = $this->sortKey !== '' && $this->sortsHere($this->sortKey) ? $this->sortKey : $this->defaultSortKey();
        $dir = in_array($this->sortDir, ['asc', 'desc'], true) ? $this->sortDir : self::naturalDir($key);

        return [$key, $dir];
    }

    /** Store a sort, leaving the URL bare when it is the type's default. */
    private function setSorting(string $key, string $dir): void
    {
        $default = $key === $this->defaultSortKey();
        $this->sortKey = $default ? '' : $key;
        $this->sortDir = $dir === self::naturalDir($key) ? '' : $dir;
        $this->restart();
    }

    /**
     * The chosen "Thời gian" span as UTC bounds [start, end), days counted in the site's zone. A preset
     * ("Hôm nay", "7 ngày qua"…) is whole days up to the end of today, so a post scheduled for a later day is
     * never in it.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function period(): array
    {
        $zone = app(SiteTime::class)->zone();
        $today = CarbonImmutable::now($zone)->startOfDay();

        if ($this->when !== '' && $this->when !== self::RANGE) {
            $start = $this->when === 'today' ? $today : $today->subDays(Cast::int($this->when) - 1);

            return [$start->utc(), $today->addDay()->utc()];
        }

        $day = fn (string $date): ?CarbonImmutable => $date === '' ? null : CarbonImmutable::parse($date, $zone)->startOfDay();

        return [$day($this->from)?->utc(), $day($this->to)?->addDay()->utc()];
    }

    /**
     * A record's translation state among the site's languages: every one, some, or only one.
     *
     * @param  list<string>  $locales
     */
    private static function transState(Entry $entry, array $locales): string
    {
        $have = $entry->translations->filter(fn (EntryTranslation $t): bool => in_array($t->locale, $locales, true))->count();
        if (count($locales) < 2 || $have >= count($locales)) {
            return 'full';
        }

        return $have <= 1 ? 'none' : 'partial';
    }

    /**
     * How many filter values are on (the Lọc badge): each chosen value and the time span as one. Below
     * 900px, where the status groups live in the Filter sheet, a group other than "Tất cả" adds one more
     * (render() passes both counts; from 900 the groups have their own row and are not counted).
     */
    private function filterCount(): int
    {
        return ($this->catF === [] ? 0 : count($this->categoryTree()->topmost($this->catF))) + count($this->templateF) + count($this->transF) + count($this->creatorF) + count($this->langF)
            + ($this->when !== '' ? 1 : 0);
    }

    /** The section's categories, read once per request (an empty tree for a page list). */
    private function categoryTree(): CategoryTree
    {
        if ($this->catTree === null) {
            $this->catTree = $this->entryType === 'post'
                ? CategoryTree::postSection(app(CurrentSite::class)->id(), app(SiteLocale::class)->default(), $this->resolveSection()?->taxonomy_id)
                : CategoryTree::none();
        }

        return $this->catTree;
    }

    /**
     * A category ticked brings everything under it; one unticked takes everything under it and above it
     * (a parent cannot stay ticked with a child gone). Compares the choice with what it was; keys of
     * categories not in this section are dropped.
     *
     * @param  list<string>  $before
     */
    private function settleCategories(array $before): void
    {
        if ($this->entryType !== 'post') {
            $this->catF = [];

            return;
        }
        $tree = $this->categoryTree();
        $now = $tree->known($this->catF);
        $before = $tree->known($before);
        $set = $now;
        foreach (array_diff($now, $before) as $added) {
            $set = [...$set, ...$tree->keysUnder($added)];
        }
        foreach (array_diff($before, $now) as $removed) {
            $set = array_diff($set, $tree->keysUnder($removed), $tree->keysAbove($removed));
        }
        $this->catF = array_values(array_unique($set));
    }

    public function render(): View
    {
        $this->clean();
        // The list's statements are planned for this website's own numbers. PgBouncer keeps
        // prepared statements, and a plan Postgres makes once for every website (a generic plan)
        // assumes a website of average size: on a website of a million posts, a section's counts
        // planned that way did not finish within a minute, where a plan for the website takes a second.
        // SET LOCAL ends with the request's tenancy transaction, so it never reaches another request.
        if (DB::transactionLevel() > 0) {
            DB::statement('SET LOCAL plan_cache_mode = force_custom_plan');
        }
        $section = $this->resolveSection();
        $type = $this->entryType;
        $siteLocale = app(SiteLocale::class);
        $locale = $siteLocale->default();
        $locales = $siteLocale->enabled();
        $multilingual = count($locales) >= 2;
        if (! $multilingual) {
            $this->transF = []; // one language: every record is complete, the filter is not offered
        }

        $user = $this->actingUser();
        $scopes = app(CategoryScopes::class);
        [$from, $until] = $this->period();
        // the section's categories (one read): the Chuyên mục filter's choices and each row's category path
        $tree = $this->categoryTree();
        $this->catF = $tree->known($this->catF);
        $filter = new EntryListFilter($type === 'post' ? 'post' : 'page', $this->tab, $section?->taxonomy_id, $this->search, $this->langF, $this->creatorF,
            $this->templateF, $this->transF, $locales, $locale, $from, $until, $tree->idsUnder($this->catF));
        // Whether this member has category limits here rides along on the rows' own query, so a
        // member without limits costs the list nothing more; so does a post's cover (its storage key
        // alone, never the whole meta).
        $list = new EntryListQuery($filter, app(CurrentSite::class)->id(), function (Builder $rows) use ($scopes, $user, $type): void {
            $scopes->selectHasLimits($rows, $user, EntryListQuery::COLUMNS);
            if ($type === 'post') {
                $rows->selectRaw("entries.meta->>'image_url' as cover_key");
            }
        });

        // Group counts, bounded: a website past 10,000 rows reads "hơn 10.000", at the cost of 10,001 rows.
        // Pages count first, to know whether they fit in memory; a list of posts counts its groups in the
        // statement that counts its facets.
        $inMemory = false;
        $tally = [];
        if ($type === 'page') {
            $tally = EntryListQuery::counts($list->tabCountSql());
            $inMemory = ($this->tab === 'trash' ? $tally['trash'] : $tally['all']) <= self::PAGES_IN_MEMORY;
        }

        $read = $inMemory
            ? $this->readInMemory($list, $locale, $locales, $user, $scopes)
            : $this->readInSql($list, $type === 'post', $tally, $locale, $locales, $multilingual, $user, $scopes);
        $tally += $read['counts'];
        $counts = [];
        foreach (self::TABS as $tab) {
            $counts[$tab] = $tally[$tab] ?? 0;
        }
        $rows = $read['rows'];
        $limited = $read['limited'];

        // Which rows this person may change at all, as far as their category limits go (the
        // primary category is loaded above, so this costs no query per row). Every action still
        // asks the policy itself; this only lets a row show its actions locked, with the reason.
        // Which rows they may edit is asked of the policy row by row (edit.any, or edit.own on
        // their own record, inside those limits), so a row they may not edit offers the reason
        // instead of a link that would be refused; permissions and limits are read once per
        // request, so this too adds no query per row.
        //
        // Each row's own answers, as the design offers its actions: the status menu only on a row the
        // member may edit (a publisher's moves, or a sender's «Gửi duyệt» on a draft), Nhân bản and
        // Thùng rác only where the policy allows them on that row. A row outside their limits that
        // their role could otherwise edit (any page, for a limited member) shows «🔒 Xem» and the
        // reason, with its items locked under that reason.
        $canCreate = $user->can('entries.create');
        $editAny = $user->can('entries.edit.any');
        $editOwn = $user->can('entries.edit.own');
        $deleteAny = $user->can('entries.delete.any');
        $deleteOwn = $user->can('entries.delete.own');
        $scopeRefusal = $limited ? $scopes->refusal($user) : null;
        $pageUlids = [];
        $rowCovered = [];
        $rowEditable = [];
        $rowCan = [];
        foreach ($rows->items() as $item) {
            $entry = $item['entry'];
            $ulid = $entry->ulid;
            $pageUlids[] = $ulid;
            $covered = ! $limited || $scopes->coversEntry($user, $entry);
            $edit = $user->can('update', $entry);
            $publish = $edit && $user->can('publish', $entry);
            $own = $entry->author_id !== null && $entry->author_id === $user->id;
            $rowCovered[$ulid] = $covered;
            $rowEditable[$ulid] = $edit;
            $rowCan[$ulid] = [
                'publish' => $publish,
                'send' => $edit && ! $publish && $entry->status === EntryStatus::Draft->value && $user->can('submit', $entry),
                'delete' => $user->can('delete', $entry),
                'duplicate' => $canCreate && $covered,
                'out' => ! $covered && $scopeRefusal !== null && ($editAny || ($editOwn && $own))
                    ? self::scopeWhy($type, $scopeRefusal) : null,
                // a locked Thùng rác is shown on such a row only to a role that could otherwise remove it
                'deleteRole' => $deleteAny || ($deleteOwn && $own),
            ];
        }
        // A member limited to some categories may add no page (a page has no category): the add button
        // stays, locked, with the reason.
        $addLock = $type === 'page' && $canCreate && $scopes->isLimited($user)
            ? self::scopeWhy('page', $scopeRefusal ?? $scopes->refusal($user)) : null;

        // Each post row's main category, as a path from the top of the section's tree, and its cover in a
        // small rendition (both from what the rows already carry: no query per row).
        $rowCategory = [];
        $rowCover = [];
        if ($type === 'post') {
            $urls = app(MediaUrlResolver::class);
            foreach ($rows->items() as $item) {
                $entry = $item['entry'];
                $primary = $entry->relationLoaded('taxonomies') ? $entry->taxonomies->first() : null;
                $rowCategory[$entry->ulid] = $tree->label($primary instanceof Taxonomy ? $primary->id : null);
                $key = $entry->getAttribute('cover_key');
                $rowCover[$entry->ulid] = is_string($key) && $key !== '' ? $urls->url($key, 96) : null;
            }
        }

        // The Chuyên mục choices; the number beside each counts the posts of the whole section filed there or
        // below, so it is shown where it is what the list holds: every status, no search, no category limits.
        $scoped = $type === 'post' && ($limited || $scopes->isLimited($user));
        $counted = $this->tab === 'all' && trim($this->search) === '' && ! $scoped;
        $catOptions = [];
        foreach ($tree->options() as $option) {
            $catOptions[] = ['value' => $option['ulid'], 'label' => $option['label'], 'depth' => $option['depth']]
                + ($counted ? ['count' => $this->countLabel($option['count'])] : []);
        }
        $catChips = array_map(fn (array $c): array => ['group' => 'cat', 'value' => $c['ulid'], 'label' => Cast::string(__('entries.chip_cat', ['name' => $c['label']]))],
            $tree->topmost($this->catF));

        // The role card stays until this member presses "Đã hiểu"; nothing of it is read after that.
        // The guide (first draw only) words the same workflow, read once for both.
        $postSection = $type === 'post' && $section instanceof ContentSection ? $section : null;
        $roleShows = $postSection !== null && ! FirstHints::dismissed(FirstHints::POSTS_ROLE, $user);
        // read only when a post list needs it: the Bước duyệt column is on, the role card shows or the guide is drawn
        $stepOn = $this->governed && in_array('step', $this->cols, true);
        // ... and whenever the section runs one: the pill and the bulk bar offer its moves
        $workflow = $postSection !== null && ($this->governed || $stepOn || $roleShows || $this->guideDue) ? ($this->workflowRead ? $this->mountedWorkflow : app(WorkflowEngine::class)->activeFor($postSection->key)) : null;
        if ($this->governed && $workflow === null) {
            // the section's workflow was switched off while the list stayed open: drop the column and the moves now, not on reload
            $this->governed = false;
            $stepOn = false;
        }
        $flow = $this->governed ? $workflow->loadMissing(self::flowParts()) : null;
        $rowStep = $workflow !== null && $stepOn ? self::rowSteps($workflow, $rows->items()) : [];
        $rowMoves = $flow !== null ? self::rowMoves($flow, $rows->items(), $user) : [];
        $roleNote = $roleShows ? PostRoleNote::with($user, $workflow, $postSection->localizedLabel(), $scoped) : null;

        $canDelete = $deleteAny || $deleteOwn;
        $bulk = $this->bulkPlan($user, $locale, $type, $flow);
        [$sortKey, $sortDir] = $this->sorting();
        $sorts = array_values(array_filter(array_keys(self::SORTS), fn (string $key): bool => $this->sortsHere($key)));

        // The member's guide, on the first draw only: the filters and columns this list offers them,
        // and the role card's own reading. A post list whose card this member has put away prints it
        // once more, hidden, for the guide's tour to open on its first step (the design's role bar).
        $guide = null;
        $tourRoleNote = null;
        if ($this->guideDue && ($postSection !== null || $type === 'page')) {
            $filters = [];
            if ($type === 'page') {
                $filters[] = ['key' => 'tpl', 'label' => Cast::string(__('entries.filter_template'))];
            } elseif ($catOptions !== [] || $this->catF !== []) {
                $filters[] = ['key' => 'cat', 'label' => Cast::string(__('entries.col_category'))];
            }
            if ($multilingual) {
                $filters[] = ['key' => 'trans', 'label' => Cast::string(__('entries.f_trans'))];
                $filters[] = ['key' => 'lang', 'label' => Cast::string(__('entries.col_language'))];
            }
            if (count($read['facets']['creator']) >= 2 || $this->creatorF !== []) {
                $filters[] = ['key' => 'creator', 'label' => Cast::string(__('entries.col_creator'))];
            }
            if ($type === 'post') {
                $filters[] = ['key' => 'when', 'label' => Cast::string(__('entries.f_when'))];
            }
            $cols = $type === 'post' ? [Cast::string(__('entries.col_category'))] : [];
            $cols[] = Cast::string(__('entries.col_path'));
            if ($multilingual) {
                $cols[] = Cast::string(__('entries.col_translations'));
            }
            $cols[] = Cast::string(__('entries.col_creator'));
            if ($type === 'page') {
                $cols[] = Cast::string(__('entries.filter_template'));
            }
            $title = $section instanceof ContentSection ? $section->localizedLabel() : Cast::string(__('entries.section_fallback'));
            if ($postSection !== null && $roleNote === null) {
                $tourRoleNote = PostRoleNote::with($user, $workflow, $title, $scoped);
            }
            $guide = EntryListGuide::for($user, [
                'type' => $type,
                'title' => $title,
                'scoped' => $scoped,
                'roleNote' => $roleNote ?? $tourRoleNote,
                'multilingual' => $multilingual,
                'locales' => count($locales),
                'sorts' => $sorts,
                'cols' => $cols,
                'filters' => $filters,
            ], $workflow)->toArray($user, app(CurrentSite::class)->id());
        }

        return view('admin::livewire.entries.index', [
            'rows' => $rows,
            'bulk' => $bulk['actions'],
            'bulkSkip' => $bulk['skip'],
            'trashDays' => EntryTrash::days(),
            'pager' => $read['pager'],
            'parentPaths' => $read['parents'],
            'pageUlids' => $pageUlids,
            'rowCovered' => $rowCovered,
            'rowEditable' => $rowEditable,
            'rowCan' => $rowCan,
            'scopeRefusal' => $scopeRefusal,
            'addLock' => $addLock,
            'counts' => $counts,
            'facets' => $read['facets'],
            'activeChips' => [...$catChips, ...$this->activeChips($read['facets'])],
            'catOptions' => $catOptions,
            'rowCategory' => $rowCategory,
            'rowCover' => $rowCover,
            'governed' => $this->governed,
            'rowStep' => $rowStep,
            'rowMoves' => $rowMoves,
            'roleNote' => $roleNote,
            'filterCount' => $this->filterCount(),
            'filterCountNarrow' => $this->filterCount() + ($this->tab !== 'all' ? 1 : 0),
            'sortOn' => $sortKey,
            'sortWay' => $sortDir,
            'sortIsDefault' => $sortKey === $this->defaultSortKey() && $sortDir === self::naturalDir($sortKey),
            'sorts' => $sorts,
            'guide' => $guide,
            'tourRoleNote' => $tourRoleNote,
            'multilingual' => $multilingual,
            'type' => $type,
            'matchCount' => $read['total'],
            'baseCount' => $counts[$this->tab] ?? 0,
            'locale' => $locale,
            'locales' => $locales,
            'homeSlug' => PathBuilder::HOME_SLUG,
            'sectionModel' => $section,
            'sectionKey' => $this->section,
            'canPublish' => $user->can('entries.publish'),
            'canCreate' => $canCreate,
            // a sender's bulk «Gửi duyệt» (bulkPublish sends their drafts for review)
            'canSend' => ! $user->can('entries.publish') && $user->can('entries.submit'),
            'canDelete' => $canDelete,
            // whoever may delete (their own or any) reaches the Trash, to restore what they removed
            'canSeeTrash' => $canDelete,
            'siteTime' => app(SiteTime::class),
        ]);
    }

    /**
     * The bulk bar's buttons for the rows ticked: each with the number of them it would really act
     * on («Đăng (3)»), worked out by the action's own rule (the why… methods) over the rows as they
     * are now, and the reasons for the rest. The line under the bar names the first button that
     * leaves rows out, and why («“Đăng” bỏ qua 2 bài viết: 1 bài viết đã đăng · 1 bài viết của
     * người khác.»). Only the buttons this member's role has are offered, in the signed order:
     * Đăng · Gỡ khỏi website · Đưa về nháp · Nhân bản · Chuyển vào Thùng rác (a sender: Gửi duyệt);
     * on the Trash, Khôi phục · Xóa vĩnh viễn. Costs nothing with nothing ticked; with a selection,
     * the ticked rows, their main category and their default-language text: three queries, plus two
     * on pages (the Home page among them, and those with live sub-pages). More than BULK_LIMIT
     * ticked reads no row: every button says the run would refuse them.
     *
     * In a section that runs an approval workflow ($flow) the status buttons are the workflow's
     * moves that need no reason, at most two, among those this member may make on a ticked row
     * («Duyệt đăng (3)»); each runs bulkTransition() with its key (`arg`).
     *
     * @return array{actions: list<array{key: string, label: string, name: string, icon: string, color: string, call: string, arg: ?string, ok: int, title: string, none: ?string, confirm: ?array{title: string, text: string, ok: string}}>, skip: ?string}
     */
    private function bulkPlan(User $user, string $locale, string $type, ?Workflow $flow = null): array
    {
        if ($this->selected === []) {
            return ['actions' => [], 'skip' => null];
        }

        // More ticked than one run takes: every action would refuse (runBulk()), so no row is read and
        // each button says so instead of running. The read below never takes more than BULK_LIMIT + 1.
        $tooMany = count($this->selected) > self::BULK_LIMIT;
        $picked = $tooMany ? [] : array_slice($this->selected, 0, self::BULK_LIMIT + 1);
        $rows = $picked === [] ? new EloquentCollection : Entry::withTrashed()->whereIn('ulid', $picked)->orderBy('id')
            ->with(self::primaryCategoryOnly() + ['translations' => static function (Relation $text) use ($locale): void {
                $text->where('locale', $locale)->select(['id', 'entry_id', 'locale', 'status', 'title']);
            }])
            ->get(['id', 'site_id', 'ulid', 'type', 'status', 'author_id', 'deleted_at', 'workflow_state']);
        $homes = $type === 'page' ? $this->homeIds($picked) : [];
        $parents = $type === 'page' ? $this->parentIds($picked) : [];
        $gone = count($picked) - $rows->count();   // gone meanwhile (purged by a colleague): never acted on
        $noun = Cast::string(__('entries.noun_'.$type));

        $deleter = $user->can('entries.delete.any') || $user->can('entries.delete.own');
        $specs = [];
        if ($this->tab === 'trash') {
            if ($deleter) {
                $specs[] = ['restore', 'entries.action_restore', 'ri-arrow-go-back', 'primary', 'bulkRestore', fn (Entry $e): ?string => $this->whyNotRestore($e, $user)];
                $specs[] = ['destroy', 'entries.action_destroy', 'ri-delete-bin-2', 'danger', 'bulkForceDelete', fn (Entry $e): ?string => $this->whyNotForceDelete($e, $user)];
            }
        } else {
            if ($flow !== null) {
                // the moves the ticked rows offer this member, in the workflow's order; two at most
                $offered = [];
                foreach ($rows as $row) {
                    if (! $row->trashed()) {
                        foreach (app(EntryWorkflowResolver::class)->movesFor($flow, $row, $user) as $move) {
                            $offered[$move->key] = true;
                        }
                    }
                }
                $taken = 0;
                foreach ($flow->transitions as $move) {
                    if ($taken === 2 || $move->requires_note || ! isset($offered[$move->key])) {
                        continue;
                    }
                    $taken++;
                    $to = $flow->states->firstWhere('key', $move->to_state);
                    $key = $move->key;
                    $specs[] = ['tr-'.$key, $move->label, $to instanceof WorkflowState && $to->is_published ? 'ri-check-double' : 'ri-send-plane', 'primary', 'bulkTransition',
                        fn (Entry $e): ?string => $this->whyNotMove($e, $user, $flow, $key), $key];
                }
            } elseif ($user->can('entries.publish')) {
                $specs[] = ['publish', 'entries.bulk_publish', 'ri-send-plane', 'primary', 'bulkPublish', fn (Entry $e): ?string => $this->whyNotPublish($e, $user, $locale)];
                $specs[] = ['unpublish', 'entries.bulk_unpublish', 'ri-eye-off', 'primary', 'bulkUnpublish', fn (Entry $e): ?string => $this->whyNotUnpublish($e, $user, $homes)];
                $specs[] = ['draft', 'entries.bulk_draft', 'ri-draft', 'primary', 'bulkDraft', fn (Entry $e): ?string => $this->whyNotDraft($e, $user, $homes)];
            } elseif ($user->can('entries.submit')) {
                $specs[] = ['send', 'entries.ed.submit', 'ri-inbox-unarchive', 'primary', 'bulkPublish', fn (Entry $e): ?string => $this->whyNotPublish($e, $user, $locale)];
            }
            if ($user->can('entries.create')) {
                $specs[] = ['duplicate', 'entries.action_duplicate', 'ri-file-copy', 'primary', 'bulkDuplicate', fn (Entry $e): ?string => $this->whyNotDuplicate($e, $user)];
            }
            if ($deleter) {
                $specs[] = ['trash', 'entries.action_trash', 'ri-delete-bin', 'danger', 'bulkTrash', fn (Entry $e): ?string => $this->whyNotTrash($e, $user, $homes, $parents)];
            }
        }

        $actions = [];
        $skip = null;
        if ($tooMany) {
            $refusal = Cast::string(__('entries.bulk_too_many', ['max' => self::BULK_LIMIT]));
            foreach ($specs as $spec) {
                [$key, $labelKey, $icon, $color, $call] = $spec;
                $arg = $spec[6] ?? null;
                $name = $arg !== null ? $labelKey : Cast::string(__($labelKey));   // a workflow move's label is the site's own words
                $actions[] = ['key' => $key, 'label' => $name, 'name' => $name, 'icon' => $icon, 'color' => $color, 'call' => $call, 'arg' => $arg,
                    'ok' => 0, 'title' => $refusal, 'none' => $refusal, 'confirm' => null];
            }

            return ['actions' => $actions, 'skip' => $specs === [] ? null : $refusal];
        }
        foreach ($specs as $spec) {
            [$key, $labelKey, $icon, $color, $call, $why] = $spec;
            $arg = $spec[6] ?? null;
            $ok = 0;
            $bad = [];
            $first = null;
            foreach ($rows as $row) {
                $reason = $why($row);
                if ($reason === null) {
                    $ok++;
                    $first ??= $row;
                } else {
                    $bad[$reason] = ($bad[$reason] ?? 0) + 1;
                }
            }
            if ($gone > 0) {
                $bad['entries.skip_gone'] = $gone;
            }
            $name = $arg !== null ? $labelKey : Cast::string(__($labelKey));
            $skipped = count($this->selected) - $ok;
            $reasons = implode(' · ', array_map(
                static fn (string $reason, int $n): string => Cast::string(__('entries.bulk_skip_part', ['count' => $n, 'noun' => $noun, 'why' => __($reason)])),
                array_keys($bad), array_values($bad)));
            $actions[] = [
                'key' => $key,
                'label' => Cast::string(__('entries.bulk_label_n', ['label' => $name, 'count' => $ok])),
                'name' => $name,
                'icon' => $icon,
                'color' => $color,
                'call' => $call,
                'arg' => $arg,
                'ok' => $ok,
                'title' => Cast::string($ok === 0
                    ? __('entries.bulk_title_none', ['noun' => $noun, 'why' => $reasons])
                    : ($bad === [] ? __('entries.bulk_title_do', ['label' => $name, 'count' => $ok, 'noun' => $noun])
                        : __('entries.bulk_title_skip', ['label' => $name, 'count' => $ok, 'noun' => $noun, 'skipped' => $skipped]))),
                // a button with nothing to do says why instead of running
                'none' => $ok === 0 ? Cast::string(__('entries.bulk_none', ['noun' => $noun, 'label' => $name, 'why' => $reasons])) : null,
                'confirm' => $key === 'destroy' ? [
                    'title' => Cast::string($ok === 1 && $first !== null
                        ? __('entries.destroy_ask_title_one', ['name' => Cast::string($first->translations->first()?->title, Cast::string(__('entries.untitled')))])
                        : __('entries.destroy_ask_title', ['count' => $ok, 'noun' => $noun])),
                    'text' => Cast::string(__($ok === 1 ? 'entries.destroy_ask_text_one' : 'entries.destroy_ask_text', ['count' => $ok, 'noun' => $noun])),
                    'ok' => Cast::string($ok === 1 ? __('entries.action_destroy') : __('entries.destroy_ask_ok', ['count' => $ok, 'noun' => $noun])),
                ] : null,
            ];
            if ($skip === null && $bad !== []) {
                $skip = Cast::string(__('entries.bulk_skip_line', ['label' => $name, 'count' => $skipped, 'noun' => $noun, 'why' => $reasons]));
            }
        }

        return ['actions' => $actions, 'skip' => $skip];
    }

    /**
     * A small page list (at most PAGES_IN_MEMORY pages): every row of the group is read once, and
     * the facets, the filters and the tree are made in memory, as the list always has.
     *
     * @param  list<string>  $locales
     * @return array{rows: LengthAwarePaginator<int, array{entry: Entry, depth: int}>, pager: array<string, mixed>, parents: array<string, string>, facets: array{lang: list<array<string, mixed>>, creator: list<array<string, mixed>>, template: list<array<string, mixed>>, trans: list<array<string, mixed>>}, total: int, limited: bool, counts: array<string, int>}
     */
    private function readInMemory(EntryListQuery $list, string $locale, array $locales, User $user, CategoryScopes $scopes): array
    {
        $query = $list->base();
        $scopes->selectHasLimits($query, $user, EntryListQuery::COLUMNS);
        $base = $query->with($this->rowRelations(self::ROW_RELATIONS))->get();

        $limited = $base->isNotEmpty()
            && $scopes->learnHasLimits($user, $base->first()->getAttribute(CategoryScopes::HAS_LIMITS)) && $scopes->isLimited($user);

        [$from, $until] = $this->period();
        $facets = [
            'lang' => $this->langFacet($base, $locales),
            'creator' => $this->creatorFacet($base, $user),
            'template' => $this->templateFacet($base),
            'trans' => count($locales) >= 2 ? $this->transFacet($base, $locales) : [],
        ];

        // OR within a group, AND across groups — applied in memory, on the rows already loaded.
        $filtered = $base->filter(fn (Entry $entry): bool => $this->passesFilters($entry, $locales, $from, $until));
        $rows = $this->paginate($this->buildTree($filtered, $locale));
        $this->cursor = '';

        return ['rows' => $rows, 'pager' => $this->pager($rows->currentPage(), $rows->total(), count($rows->items()), null, null),
            'parents' => [], 'facets' => $facets, 'total' => $filtered->count(), 'limited' => $limited, 'counts' => []];
    }

    /**
     * A list read in the database: one page of rows, in the order chosen, with the facets counted
     * there. Pages keep their tree while nothing narrows the list and they stand in their website
     * order (the top level is paged; each top page brings the pages below it); otherwise they are a
     * flat list, each row naming the pages above it.
     *
     * A search reads its page first: when that page is the whole result, the facets count just
     * those rows, so a search that finds little walks the website once, not twice.
     *
     * @param  bool  $tabs  read the group counts with the facets
     * @param  array<string, int>  $counts  the group counts already read
     * @param  list<string>  $locales
     * @return array{rows: LengthAwarePaginator<int, array{entry: Entry, depth: int}>, pager: array<string, mixed>, parents: array<string, string>, facets: array{lang: list<array<string, mixed>>, creator: list<array<string, mixed>>, template: list<array<string, mixed>>, trans: list<array<string, mixed>>}, total: int, limited: bool, counts: array<string, int>}
     */
    private function readInSql(EntryListQuery $list, bool $tabs, array $counts, string $locale, array $locales, bool $multilingual, User $user, CategoryScopes $scopes): array
    {
        $type = $this->entryType;
        [$sortKey, $sortDir] = $this->sorting();
        $dir = $sortDir === 'desc' ? 'desc' : 'asc';
        $searching = trim($this->search) !== '';
        $filtering = $this->filterCount() > 0;
        $tree = $type === 'page' && $this->tab === 'all' && ! $searching && ! $filtering && $sortKey === 'order';

        $tally = $searching ? null : $list->facets($multilingual, $tabs, null, true, true);
        $counts += $tally['counts'] ?? [];
        // an author with nothing in the group has no run in the author order
        $authors = [];
        $people = $tally['people'] ?? $list->authors();
        foreach ($people as $author) {
            $n = $tally['author'][$author['id']] ?? 0;
            if ($tally === null || ! $tally['exact'] || $n > 0) {
                $authors[] = $author + ['count' => $n];
            }
        }

        $groups = $tree ? $list->treeTopGroups($dir) : $list->groups($sortKey, $dir, $authors);
        $known = $tree || $filtering || $searching ? null : ($counts[$this->tab] ?? 0);
        $countAll = function () use (&$tally, $list, $multilingual, $tabs, $tree, $filtering): int {
            if ($tree) {
                return $list->treeTopCount();
            }
            // a search is counted by the facet walk, which walks its rows anyway
            $tally ??= $list->facets($multilingual, $tabs);

            return $filtering ? $list->count($list->filtered()) : $tally['n'];
        };

        $page = $this->readPage($list, $groups, $known, $countAll);
        $entries = $page['rows'];
        if ($tally === null) {
            // the first page holds the whole search: its facets are those rows' own
            $whole = ! $filtering && $page['current'] === 1 && $page['total'] === $entries->count() && $this->cursor === '';
            $tally = $list->facets($multilingual, $tabs, $whole ? array_values(array_map(Cast::int(...), $entries->modelKeys())) : null);
            $counts += $tally['counts'];
        }
        $facets = $this->sqlFacets($list, $tally, $authors, $locales, $multilingual, $user);

        $items = [];
        $parents = [];
        if ($tree) {
            $below = $list->descendants(array_values(array_map(Cast::int(...), $entries->modelKeys())), self::TREE_CHILDREN);
            $all = $entries->concat($below);
            $this->loadRows($all, $people);
            foreach ($this->hangBelow($entries, $below, $dir) as $item) {
                $items[] = $item;
            }
        } else {
            $this->loadRows($entries, $people);
            $all = $entries;
            foreach ($entries as $entry) {
                $items[] = ['entry' => $entry, 'depth' => 0];
            }
            if ($type === 'page') {
                $parentIds = array_values(array_unique(array_filter($entries->pluck('parent_id')->all(), 'is_int')));
                $paths = $list->ancestorTitles($parentIds, __('entries.untitled'));
                foreach ($entries as $entry) {
                    if ($entry->parent_id !== null && ($paths[$entry->parent_id] ?? []) !== []) {
                        $parents[$entry->ulid] = implode(' › ', $paths[$entry->parent_id]);
                    }
                }
            }
        }

        $limited = $all->isNotEmpty()
            && $scopes->learnHasLimits($user, $all->first()->getAttribute(CategoryScopes::HAS_LIMITS)) && $scopes->isLimited($user);
        if ($type === 'post') {
            // the row's category column, and what the category limits read
            $all->load(self::primaryCategoryOnly());
        }

        $rows = new LengthAwarePaginator(collect($items), max($page['total'], $page['to']), $this->perPage, $page['current'],
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page']);

        return ['rows' => $rows, 'pager' => $page['pager'], 'parents' => $parents, 'facets' => $facets, 'total' => $page['total'], 'limited' => $limited,
            'counts' => $counts];
    }

    /**
     * The filter options of a list read in the database. Facet counts are known for a group (and
     * search) of up to 10,000 rows; a larger one offers its values without a number.
     *
     * @param  array{n: int, exact: bool, counts: array<string, int>, lang: array<string, int>, trans: array<string, int>, author: array<int, int>, template: array<string, int>}  $tally
     * @param  list<array{id: int, ulid: string, name: string, count: int}>  $authors
     * @param  list<string>  $locales
     * @return array{lang: list<array<string, mixed>>, creator: list<array<string, mixed>>, template: list<array<string, mixed>>, trans: list<array<string, mixed>>}
     */
    private function sqlFacets(EntryListQuery $list, array $tally, array $authors, array $locales, bool $multilingual, User $user): array
    {
        $exact = $tally['exact'];
        $count = fn (int $n): array => $exact ? ['count' => $this->countLabel($n)] : [];
        $counted = [];
        foreach ($authors as $author) {
            $n = $tally['author'][$author['id']] ?? 0;
            if (! $exact || $n > 0) {
                $counted[] = ['count' => $n] + $author;
            }
        }
        $templates = [];
        if ($this->entryType === 'page') {
            foreach ($exact ? array_keys($tally['template']) : $list->templateValues() as $value) {
                $templates[] = ['value' => (string) $value, 'count' => $tally['template'][$value] ?? 0];
            }
            usort($templates, fn (array $a, array $b): int => [$a['value'] !== self::TEMPLATE_NONE, $a['value']] <=> [$b['value'] !== self::TEMPLATE_NONE, $b['value']]);
        }

        return [
            'lang' => array_map(fn (string $lc): array => ['value' => $lc, 'label' => __('entries.language_'.$lc).' ('.strtoupper($lc).')']
                + $count($tally['lang'][$lc] ?? 0) + ['active' => in_array($lc, $this->langF, true)], $locales),
            'creator' => $this->creatorOptions($counted, $user, $exact),
            'template' => $this->templateOptions($this->entryType === 'page' ? $templates : null, $exact),
            'trans' => $multilingual ? array_map(fn (string $state): array => ['value' => $state, 'label' => __('entries.trans_'.$state)]
                + $count($tally['trans'][$state] ?? 0) + ['active' => in_array($state, $this->transF, true)], self::TRANS) : [],
        ];
    }

    /**
     * The top pages of a page of the tree, each followed by every page below it (one level of
     * indent, in website order), as the list has always shown them.
     *
     * @param  EloquentCollection<int, Entry>  $tops
     * @param  EloquentCollection<int, Entry>  $below
     * @return list<array{entry: Entry, depth: int}>
     */
    private function hangBelow(EloquentCollection $tops, EloquentCollection $below, string $dir): array
    {
        $byId = [];
        foreach ($tops->concat($below) as $entry) {
            $byId[$entry->id] = $entry;
        }
        $topIds = array_flip($tops->modelKeys());
        $children = [];
        foreach ($below as $entry) {
            $root = $entry;
            for ($guard = 0; ! isset($topIds[$root->id]) && $root->parent_id !== null && isset($byId[$root->parent_id]) && $guard < 64; $guard++) {
                $root = $byId[$root->parent_id];
            }
            if (isset($topIds[$root->id]) && $root->id !== $entry->id) {
                $children[$root->id][] = $entry;
            }
        }

        $way = $dir === 'desc' ? -1 : 1;
        $items = [];
        foreach ($tops as $top) {
            $items[] = ['entry' => $top, 'depth' => 0];
            $group = $children[$top->id] ?? [];
            usort($group, fn (Entry $a, Entry $b): int => (($a->position <=> $b->position) ?: ($a->id <=> $b->id)) * $way);
            foreach ($group as $child) {
                $items[] = ['entry' => $child, 'depth' => 1];
            }
        }

        return $items;
    }

    /**
     * One page of rows from the database: by number while the page starts within the first CAP
     * rows, past that after (or before) the row the cursor names.
     *
     * @param  list<ListGroup>  $groups
     * @param  int|null  $known  the list's size when already counted (the group count, no filter)
     * @param  Closure(): int  $count  counts the list (bounded) when it is not known
     * @return array{rows: EloquentCollection<int, Entry>, total: int, current: int, to: int, pager: array<string, mixed>}
     */
    private function readPage(EntryListQuery $list, array $groups, ?int $known, Closure $count): array
    {
        $size = $this->perPage;
        $note = ListCursor::open($this->cursor, $this->fingerprint());
        // the run is named by its key: when it is gone (an author's last post trashed), start again
        $run = $note === null ? false : array_search($note['k'], array_map(fn (ListGroup $g): string => $g->key, $groups), true);
        if ($run === false) {
            if ($note !== null) {
                $this->page = 1;
            }
            $note = null;
            $this->cursor = '';
        }
        $total = $known;

        if ($note !== null) {
            $current = $note['n'];
            $anchor = ['g' => $run, 'v' => $note['v'], 'id' => $note['id']];
            if ($note['w'] === 'before') {
                // read backwards from the row the next page starts with, then turn the rows round
                $last = count($groups) - 1;
                $back = array_reverse(array_map(fn (ListGroup $g): ListGroup => new ListGroup($g->key, $g->where, $g->order, $g->value, $g->cast,
                    $g->nullable, $g->dir === 'desc' ? 'asc' : 'desc', $g->joinTitle, $g->tie, $g->rare), $groups));
                $anchor['g'] = $last - $anchor['g'];
                $rows = $list->rows($back, 0, $size, $anchor)->reverse()->values();
                foreach ($rows as $row) {
                    $row->setAttribute('__g', $last - Cast::int($row->getAttribute('__g')));
                }
                $more = true;
            } else {
                $rows = $list->rows($groups, 0, $size + 1, $anchor);
                $more = $rows->count() > $size;
                $rows = $rows->take($size)->values();
            }
            $this->page = $current;
        } else {
            $deepest = intdiv(EntryListQuery::CAP, $size) + 1;
            if ($total === null && $this->page > 1) {
                $total = $count();
            }
            $current = max(1, min($this->page, $deepest));
            if ($total !== null && $total <= EntryListQuery::CAP) {
                $current = min($current, max(1, (int) ceil($total / $size)));
            }
            $this->page = $current;
            $rows = $list->rows($groups, ($current - 1) * $size, $size + 1);
            $more = $rows->count() > $size;
            $rows = $rows->take($size)->values();
        }

        if ($total === null) {
            $total = $note === null && $current === 1 && ! $more ? $rows->count() : $count();
        }

        $first = $rows->first();
        $lastRow = $rows->last();
        $fingerprint = $this->fingerprint();
        $mark = fn (Entry $row, string $way, int $n): string => ListCursor::make($fingerprint, $groups[Cast::int($row->getAttribute('__g'))]->key,
            Cast::stringOrNull($row->getAttribute('__sv')), $row->id, $way, $n);

        $offset = ($current - 1) * $size;
        $prev = null;
        if ($current > 1) {
            $prev = ($current - 2) * $size <= EntryListQuery::CAP || $first === null
                ? ['page' => min($current - 1, intdiv(EntryListQuery::CAP, $size) + 1)]
                : ['token' => $mark($first, 'before', $current - 1)];
        }
        $next = null;
        if ($more && $lastRow !== null) {
            $next = $current * $size <= EntryListQuery::CAP ? ['page' => $current + 1] : ['token' => $mark($lastRow, 'after', $current + 1)];
        }

        $to = $offset + $rows->count();

        return ['rows' => $rows, 'total' => $total, 'current' => $current, 'to' => $to,
            'pager' => $this->pager($current, $total, $rows->count(), $prev, $next, $note !== null)];
    }

    /**
     * What the footer shows: the rows shown, the size of the list ("hơn 10.000" past the cap, with no
     * last page then), the page cells and the two arrows (a page number, or a cursor past the cap).
     *
     * @param  array{page: int}|array{token: string}|null  $prev
     * @param  array{page: int}|array{token: string}|null  $next
     * @return array{from: int, to: int, total: int|string, current: int, last: int|null, cells: list<int|null>, prev: array{page: int}|array{token: string}|null, next: array{page: int}|array{token: string}|null}
     */
    private function pager(int $current, int $total, int $shown, ?array $prev, ?array $next, bool $keyset = false): array
    {
        $size = $this->perPage;
        $capped = $total > EntryListQuery::CAP;
        $last = $capped ? null : max(1, (int) ceil($total / $size));
        if ($last !== null && ! $keyset) {
            // a list read in memory, or a short one: the arrows follow the page numbers
            $prev ??= $current > 1 ? ['page' => $current - 1] : null;
            $next ??= $current < $last ? ['page' => $current + 1] : null;
        }

        // first, last and current±1; a gap is one "…" (null). Past the cap there is no last page,
        // and past the numbered pages only the current one is named.
        $deepest = intdiv(EntryListQuery::CAP, $size) + 1;
        $keep = [1, $current];
        if (! $keyset) {
            $keep = [...$keep, $current - 1, $current + 1];
        }
        if ($last !== null) {
            $keep[] = $last;
        }
        $keep = array_values(array_unique(array_filter($keep, fn (int $p): bool => $p >= 1 && ($last === null || $p <= $last)
            && ($p <= $deepest || $p === $current) && ($p <= $current || $next !== null))));
        sort($keep);
        $cells = [];
        $before = null;
        foreach ($keep as $p) {
            if ($before !== null && $p - $before > 1) {
                $cells[] = null;
            }
            $cells[] = $p;
            $before = $p;
        }

        $from = $shown > 0 ? ($current - 1) * $size + 1 : 0;

        return ['from' => $from, 'to' => $shown > 0 ? $from + $shown - 1 : 0, 'total' => $this->countLabel($total), 'current' => $current,
            'last' => $last, 'cells' => $cells, 'prev' => $prev, 'next' => $next];
    }

    /** A count as the list shows it: the number, or "hơn 10.000" past the cap. */
    private function countLabel(int $n): int|string
    {
        return $n > EntryListQuery::CAP ? Cast::string(__('entries.count_over')) : $n;
    }

    /** Names the view of the list a page cursor belongs to: what is filtered, searched, sorted, and the page size. */
    private function fingerprint(): string
    {
        return hash('xxh128', json_encode([$this->entryType, $this->section, $this->tab, trim($this->search), $this->catF, $this->langF, $this->creatorF,
            $this->templateF, $this->transF, $this->when, $this->from, $this->to, $this->sorting(), $this->perPage], JSON_THROW_ON_ERROR));
    }

    /**
     * Load what the rows show. Their authors come from $people, the authors of every row of the
     * tab already read for the Người tạo filter; one missing from it (written meanwhile) is read.
     *
     * @param  EloquentCollection<int, Entry>  $rows
     * @param  list<array{id: int, ulid: string, name: string}>  $people
     */
    private function loadRows(EloquentCollection $rows, array $people): void
    {
        $rows->load($this->rowRelations(self::ROW_RELATIONS_BUT_AUTHOR));
        $byId = [];
        foreach ($people as $person) {
            $byId[$person['id']] = $person;
        }
        foreach ($rows as $entry) {
            $id = $entry->author_id;
            if ($id === null) {
                $entry->setRelation('author', null);
            } elseif (isset($byId[$id])) {
                $entry->setRelation('author', (new User)->newFromBuilder($byId[$id]));
            }
        }
        $rows->loadMissing('author:id,ulid,name');
    }

    /**
     * The Người tạo options from the counted authors: the member first, as "Của tôi (…)".
     *
     * @param  list<array{id: int, ulid: string, name: string, count: int}>  $authors
     * @return list<array{value: string, label: string, count?: int|string, active: bool}>
     */
    private function creatorOptions(array $authors, User $me, bool $counted): array
    {
        usort($authors, fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['ulid']] <=> [mb_strtolower($b['name']), $b['ulid']]);
        $mine = (string) $me->ulid;
        $out = [];
        foreach ($authors as $author) {
            $option = ['value' => $author['ulid'], 'label' => $author['name']] + ($counted ? ['count' => $this->countLabel($author['count'])] : [])
                + ['active' => in_array($author['ulid'], $this->creatorF, true)];
            if ($author['ulid'] === $mine) {
                $option['label'] = __('entries.f_mine', ['name' => $author['name']]);
                array_unshift($out, $option);
            } else {
                $out[] = $option;
            }
        }

        return $out;
    }

    /**
     * The Mẫu trang options: counted for pages; a post list offers no such filter, so only a value
     * already chosen is named (for its chip).
     *
     * @param  list<array{value: string, count: int}>|null  $counted
     * @return list<array{value: string, label: string, count?: int|string, active: bool}>
     */
    private function templateOptions(?array $counted, bool $exact = true): array
    {
        $label = fn (string $value): string => $value === self::TEMPLATE_NONE ? __('entries.template_default') : $value;
        if ($counted === null) {
            return array_map(fn (string $value): array => ['value' => $value, 'label' => $label($value), 'active' => true], $this->templateF);
        }

        return array_map(fn (array $t): array => ['value' => $t['value'], 'label' => $label($t['value'])] + ($exact ? ['count' => $this->countLabel($t['count'])] : [])
            + ['active' => in_array($t['value'], $this->templateF, true)], $counted);
    }

    private function resolveSection(): ?ContentSection
    {
        if ($this->section === null || $this->section === '') {
            return null;
        }

        // asked once per request (mount and render both need it); never kept across requests
        if ($this->sectionFor !== $this->section) {
            $this->sectionFor = $this->section;
            $this->sectionRow = ContentSection::query()->where('key', $this->section)->first();
        }

        return $this->sectionRow;
    }

    /**
     * Content-locale facet: each enabled locale + how many base pages carry that translation.
     *
     * @param  EloquentCollection<int, Entry>  $base
     * @param  list<string>  $locales
     * @return list<array{value: string, label: string, count: int, active: bool}>
     */
    private function langFacet(EloquentCollection $base, array $locales): array
    {
        $out = [];
        foreach ($locales as $lc) {
            $count = $base->filter(fn (Entry $e): bool => $e->translations->contains(fn (EntryTranslation $t): bool => $t->locale === $lc))->count();
            // Localized by the ADMIN UI locale, e.g. a vi-admin sees "Tiếng Việt (VI)".
            $label = __('entries.language_'.$lc).' ('.strtoupper($lc).')';
            $out[] = ['value' => $lc, 'label' => $label, 'count' => $count, 'active' => in_array($lc, $this->langF, true)];
        }

        return $out;
    }

    /**
     * Author facet: distinct authors (by ulid, never the raw id) + their page counts.
     *
     * @param  EloquentCollection<int, Entry>  $base
     * @return list<array{value: string, label: string, count: int, active: bool}>
     */
    private function creatorFacet(EloquentCollection $base, User $me): array
    {
        $byUlid = [];
        foreach ($base as $entry) {
            $author = $entry->author;
            if ($author === null) {
                continue;
            }
            $ulid = (string) $author->ulid;
            if (! isset($byUlid[$ulid])) {
                $byUlid[$ulid] = ['value' => $ulid, 'label' => (string) $author->name, 'count' => 0, 'active' => in_array($ulid, $this->creatorF, true)];
            }
            $byUlid[$ulid]['count']++;
        }

        // The member themself comes first, named "Của tôi (…)", so their own work is one tap away.
        $mine = (string) $me->ulid;
        if (isset($byUlid[$mine])) {
            $byUlid[$mine]['label'] = __('entries.f_mine', ['name' => $byUlid[$mine]['label']]);
            $byUlid = [$mine => $byUlid[$mine]] + $byUlid;
        }

        return array_values($byUlid);
    }

    /**
     * Translation facet: how many rows have every site language, some, or only one.
     *
     * @param  EloquentCollection<int, Entry>  $base
     * @param  list<string>  $locales
     * @return list<array{value: string, label: string, count: int, active: bool}>
     */
    private function transFacet(EloquentCollection $base, array $locales): array
    {
        $count = array_fill_keys(self::TRANS, 0);
        foreach ($base as $entry) {
            $count[self::transState($entry, $locales)]++;
        }

        $out = [];
        foreach (self::TRANS as $state) {
            $out[] = ['value' => $state, 'label' => __('entries.trans_'.$state), 'count' => $count[$state], 'active' => in_array($state, $this->transF, true)];
        }

        return $out;
    }

    /**
     * Template facet: distinct template_override values (null → the "Mặc định" bucket) + counts.
     *
     * @param  EloquentCollection<int, Entry>  $base
     * @return list<array{value: string, label: string, count: int, active: bool}>
     */
    private function templateFacet(EloquentCollection $base): array
    {
        $byTemplate = [];
        foreach ($base as $entry) {
            $template = $entry->template_override ?? self::TEMPLATE_NONE;
            if (! isset($byTemplate[$template])) {
                $label = $template === self::TEMPLATE_NONE ? __('entries.template_default') : $template;
                $byTemplate[$template] = ['value' => $template, 'label' => (string) $label, 'count' => 0, 'active' => in_array($template, $this->templateF, true)];
            }
            $byTemplate[$template]['count']++;
        }

        return array_values($byTemplate);
    }

    /**
     * OR within each group, AND across groups (an empty group adds no constraint).
     *
     * @param  list<string>  $locales
     */
    private function passesFilters(Entry $entry, array $locales, ?CarbonImmutable $from, ?CarbonImmutable $until): bool
    {
        if ($this->transF !== [] && ! in_array(self::transState($entry, $locales), $this->transF, true)) {
            return false;
        }

        if ($from !== null || $until !== null) {
            // By publish date: a draft has none, so a time span lists only published or scheduled posts.
            $at = $entry->published_at;
            if ($at === null || ($from !== null && $at->lt($from)) || ($until !== null && $at->gte($until))) {
                return false;
            }
        }

        if ($this->langF !== [] && ! $entry->translations->contains(fn (EntryTranslation $t): bool => in_array($t->locale, $this->langF, true))) {
            return false;
        }

        if ($this->creatorF !== []) {
            $author = $entry->author;
            if ($author === null || ! in_array($author->ulid, $this->creatorF, true)) {
                return false;
            }
        }

        if ($this->templateF !== [] && ! in_array($entry->template_override ?? self::TEMPLATE_NONE, $this->templateF, true)) {
            return false;
        }

        return true;
    }

    /**
     * Active filter chips, in group order, labelled from the facets.
     *
     * @param  array{lang: list<array<string, mixed>>, creator: list<array<string, mixed>>, template: list<array<string, mixed>>, trans: list<array<string, mixed>>}  $facets
     * @return list<array{group: string, value: string, label: string}>
     */
    private function activeChips(array $facets): array
    {
        $chips = [];
        foreach (['template', 'trans', 'creator', 'lang'] as $group) {
            foreach ($facets[$group] as $option) {
                if (($option['active'] ?? false) === true) {
                    $chips[] = ['group' => $group, 'value' => Cast::string($option['value'] ?? ''), 'label' => Cast::string($option['label'] ?? '')];
                }
            }
        }

        return $chips;
    }

    /**
     * Group children directly under their (matched) parent; promote a child whose parent is
     * filtered out to the top level. Only top-level rows are sorted; children follow their parent.
     *
     * @param  EloquentCollection<int, Entry>  $entries
     * @return Collection<int, array{entry: Entry, depth: int}>
     */
    private function buildTree(EloquentCollection $entries, string $locale): Collection
    {
        $matched = [];
        $byId = [];
        foreach ($entries as $entry) {
            $matched[$entry->id] = true;
            $byId[$entry->id] = $entry;
        }
        $comparator = $this->comparator($locale);

        /** @var array<int, Entry> $tops */
        $tops = [];
        /** @var array<int, list<Entry>> $children */
        $children = [];

        foreach ($entries as $entry) {
            $root = $entry;
            while ($root->parent_id !== null && isset($matched[$root->parent_id])) {
                $root = $byId[$root->parent_id];
            }

            if ($root->id === $entry->id) {
                $tops[] = $entry;
            } else {
                $children[$root->id][] = $entry;
            }
        }

        usort($tops, $comparator);

        /** @var list<array{entry: Entry, depth: int}> $ordered */
        $ordered = [];
        foreach ($tops as $top) {
            $ordered[] = ['entry' => $top, 'depth' => 0];
            $group = $children[$top->id] ?? [];
            usort($group, $comparator);
            foreach ($group as $child) {
                $ordered[] = ['entry' => $child, 'depth' => 1];
            }
        }

        return collect($ordered);
    }

    /**
     * A stable comparator for the active sort key/direction, tie-broken by id.
     *
     * @return callable(Entry, Entry): int
     */
    private function comparator(string $locale): callable
    {
        [$key, $dir] = $this->sorting();
        $direction = $dir === 'asc' ? 1 : -1;
        $rank = ['published' => 0, 'scheduled' => 1, 'pending' => 2, 'draft' => 3, 'private' => 4, 'archived' => 5];

        $value = function (Entry $entry) use ($locale, $rank, $key): string|int {
            return match ($key) {
                'name' => $this->sortableTitle($entry, $locale),
                'status' => $rank[$entry->status] ?? 99,
                'creator' => $this->sortableCreator($entry),
                'order' => $entry->position,
                'views' => $entry->view_count,
                // a post never published sorts as the oldest
                'pub' => $entry->published_at?->getTimestamp() ?? 0,
                default => $entry->updated_at?->getTimestamp() ?? 0,
            };
        };

        return function (Entry $a, Entry $b) use ($value, $direction): int {
            $result = $value($a) <=> $value($b);

            return $result !== 0 ? $result * $direction : $a->id <=> $b->id;
        };
    }

    /** The default-locale title for name sorting; '' when the locale has no translation. */
    private function sortableTitle(Entry $entry, string $locale): string
    {
        $translation = $entry->translations->firstWhere('locale', $locale);

        return $translation !== null ? mb_strtolower($translation->title) : '';
    }

    /** The author's name for creator sorting; '' when the entry has no author. */
    private function sortableCreator(Entry $entry): string
    {
        $author = $entry->author;

        return $author !== null ? mb_strtolower($author->name) : '';
    }

    /**
     * @param  Collection<int, array{entry: Entry, depth: int}>  $ordered
     * @return LengthAwarePaginator<int, array{entry: Entry, depth: int}>
     */
    private function paginate(Collection $ordered): LengthAwarePaginator
    {
        $total = $ordered->count();
        $lastPage = max(1, (int) ceil($total / $this->perPage));
        $current = max(1, min($this->page, $lastPage));

        $paginator = new LengthAwarePaginator(
            $ordered->forPage($current, $this->perPage)->values(),
            $total,
            $this->perPage,
            $current,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page'],
        );

        return $paginator->appends(array_filter([
            'tab' => $this->tab !== 'all' ? $this->tab : null,
            'q' => trim($this->search) !== '' ? trim($this->search) : null,
            'sort' => $this->sortKey !== '' ? $this->sortKey : null,
            'dir' => $this->sortDir !== '' ? $this->sortDir : null,
            'lang' => $this->langF !== [] ? $this->langF : null,
            'author' => $this->creatorF !== [] ? $this->creatorF : null,
            'tpl' => $this->templateF !== [] ? $this->templateF : null,
            'tr' => $this->transF !== [] ? $this->transF : null,
            'when' => $this->when !== '' && $this->when !== self::RANGE ? $this->when : null,
            'from' => $this->from !== '' ? $this->from : null,
            'to' => $this->to !== '' ? $this->to : null,
            'per_page' => $this->perPage !== 20 ? $this->perPage : null,
        ]));
    }
}
