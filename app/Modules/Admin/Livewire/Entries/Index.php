<?php

namespace App\Modules\Admin\Livewire\Entries;

use App\Modules\Admin\Support\Entries\EntryListFilter;
use App\Modules\Admin\Support\Entries\EntryListQuery;
use App\Modules\Admin\Support\Entries\ListCursor;
use App\Modules\Admin\Support\Entries\ListGroup;
use App\Modules\Cms\Actions\DuplicateEntry;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Exceptions\WorkflowGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Support\EntryBlockMediaReconciler;
use App\Modules\Cms\Validation\EntryContentRules;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\ConcurrencyError;
use App\Modules\Shared\Support\Slugger;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\ContentSection;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
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

    private ?string $sectionFor = null;

    private ?ContentSection $sectionRow = null;

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

    /** Optional columns of a wide list ("Cột"), and the ones on until the member says otherwise. */
    private const array COLS = ['path', 'langs', 'creator', 'tpl'];

    private const array COLS_DEFAULT = ['path', 'langs', 'creator'];

    /** What a phone card may add ("Hiện trên thẻ"), all off until the member turns them on. */
    private const array CARD = ['tpl', 'path', 'langs'];

    /** Sentinel value for the "no template" (null template_override) filter option. */
    private const string TEMPLATE_NONE = '__default__';

    /** The most rows one bulk action takes: every one is locked and worked through in a single request. */
    private const int BULK_LIMIT = 200;

    /** A page list this size or smaller is read whole and sorted, filtered and treed in memory. */
    private const int PAGES_IN_MEMORY = 1000;

    /** At most this many pages ride below the top pages of one page of the tree. */
    private const int TREE_CHILDREN = 1000;

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
        $this->cols = isset($view['cols']) ? self::known(Cast::arr($view['cols']), self::COLS) : self::COLS_DEFAULT;
        $this->card = self::known(Cast::arr($view['card'] ?? null), self::CARD);
        $this->clean();
    }

    public function updatedSearch(): void
    {
        $this->restart(); // a new term must not strand the user on a stale page number
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
        if (in_array($name, ['tab', 'transF', 'langF', 'creatorF', 'templateF', 'when', 'from', 'to', 'sortKey', 'sortDir'], true)) {
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
        $this->cols = self::known($this->cols, self::COLS);
        $this->saveView();
    }

    /** "Hiện trên thẻ" changed: kept for this member only. */
    public function updatedCard(): void
    {
        $this->card = self::known($this->card, self::CARD);
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

    /** Footer "Dòng/trang" control — cycles 10 → 20 → 50 → 10, then returns to page 1. */
    public function cyclePerPage(): void
    {
        $options = [10, 20, 50];
        $index = array_search($this->perPage, $options, true);
        $this->perPage = $options[$index === false ? 0 : ($index + 1) % count($options)];
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
        if ($group === 'lang') {
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
        if ($group === 'lang') {
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
     * Row status menu → published (via PublishEntry, route-synced) or draft/private (via the
     * engine). A post whose section runs an approval workflow cannot be published from here —
     * that is the editor's approval block — so the refusal comes back as a plain toast.
     */
    public function changeStatus(string $ulid, string $status): void
    {
        $entry = $this->findLiveEntry($ulid);
        if ($entry === null) {
            $this->toastStaleRow();

            return;
        }
        $user = $this->actingUser();

        try {
            if ($status === EntryStatus::Published->value) {
                app(PublishEntry::class)->handle($entry, app(SiteLocale::class)->default(), $user);
            } elseif ($status === EntryStatus::Draft->value || $status === EntryStatus::Private->value) {
                app(TransitionEntryStatus::class)->handle($entry, EntryStatus::from($status), $user);
            } else {
                return; // scheduled has its own datetime path; unknown values are ignored
            }
        } catch (WorkflowGovernedException) {
            $this->toast(__('entries.workflow_governed'));

            return;
        }

        $this->toast(__('entries.status_changed'));
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
        $this->authorize('update', $entry);

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
        $this->toast(__('entries.title_saved'));
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
        $this->authorize('update', $entry);

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
        } catch (RouteConflictException) {
            $this->toast(__('entries.slug_conflict'));

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
        $copy = app(DuplicateEntry::class)->handle($entry, $this->actingUser());
        // Materialize the copied block images into the usage index, exactly as the editor does on save.
        app(EntryBlockMediaReconciler::class)->reconcile($copy);

        $this->toast(__('entries.duplicated'));
    }

    public function trash(string $ulid): void
    {
        $entry = $this->findLiveEntry($ulid);   // trashing it again would restart its purge clock
        if ($entry === null) {
            $this->toastStaleRow();

            return;
        }
        $this->authorize('delete', $entry);
        $entry->delete();

        $this->toast(__('entries.trashed'));
    }

    public function restore(string $ulid): void
    {
        $entry = $this->findTrashedEntry($ulid);
        if ($entry === null) {
            $this->toastStaleRow();

            return;
        }
        $this->authorize('restore', $entry);
        $entry->restore();

        $this->toast(__('entries.restored'));
    }

    public function forceDelete(string $ulid): void
    {
        $entry = $this->findTrashedEntry($ulid);   // only what is in the Trash is ever purged
        if ($entry === null) {
            $this->toastStaleRow();

            return;
        }
        $this->authorize('forceDelete', $entry);
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

        $this->runBulk(
            $user->can('entries.publish') ? 'entries.bulk_published' : 'entries.bulk_sent',
            'entries.bulk_skipped',
            fn (Entry $entry): bool => $this->mayPublishOrSend($entry, $user, $locale),
            fn (Entry $entry): bool => $publish->attempt($entry, $locale, $user)['moved'],
        );
    }

    public function bulkDraft(): void
    {
        $user = $this->actingUser();
        $transition = app(TransitionEntryStatus::class);

        $this->runBulk(
            'entries.bulk_drafted',
            'entries.bulk_skipped',
            fn (Entry $entry): bool => $this->mayDraft($entry, $user),
            function (Entry $entry) use ($transition, $user): bool {
                $transition->handle($entry, EntryStatus::Draft, $user);

                return true;
            },
        );
    }

    public function bulkDuplicate(): void
    {
        $user = $this->actingUser();
        $duplicate = app(DuplicateEntry::class);
        $reconciler = app(EntryBlockMediaReconciler::class);

        $this->runBulk(
            'entries.bulk_duplicated',
            'entries.bulk_skipped',
            fn (Entry $entry): bool => $this->mayDuplicate($entry, $user),
            function (Entry $entry) use ($duplicate, $reconciler, $user): bool {
                $reconciler->reconcile($duplicate->handle($entry, $user));

                return true;
            },
        );
    }

    public function bulkTrash(): void
    {
        $user = $this->actingUser();

        $this->runBulk(
            'entries.bulk_trashed',
            'entries.bulk_skipped',
            fn (Entry $entry): bool => $this->mayTrash($entry, $user),
            function (Entry $entry): bool {
                $this->authorize('delete', $entry);

                return (bool) $entry->delete();
            },
        );
    }

    public function bulkRestore(): void
    {
        $user = $this->actingUser();

        $this->runBulk(
            'entries.bulk_restored',
            'entries.bulk_skipped_trash',
            fn (Entry $entry): bool => $this->mayRestore($entry, $user),
            function (Entry $entry): bool {
                $this->authorize('restore', $entry);

                return $entry->restore();
            },
        );
    }

    public function bulkForceDelete(): void
    {
        $user = $this->actingUser();

        $this->runBulk(
            'entries.bulk_destroyed',
            'entries.bulk_skipped_trash',
            fn (Entry $entry): bool => $this->mayForceDelete($entry, $user),
            function (Entry $entry): bool {
                $this->authorize('forceDelete', $entry);

                return (bool) $entry->forceDelete();
            },
        );
    }

    /*
     * Which rows each bulk action is for: the row's kind (in the Trash or not), its state, and
     * this person's right to change it. One rule per action, so a count shown on a button before
     * the click can use the very same rule. Restore and Xóa vĩnh viễn are for the Trash only;
     * everything else is for live content only ("không đụng bài trong Thùng rác"). The actions
     * still check the right themselves — these rules only keep the wrong rows out.
     */

    private function mayTrash(Entry $entry, User $user): bool
    {
        return ! $entry->trashed() && $user->can('delete', $entry);
    }

    private function mayRestore(Entry $entry, User $user): bool
    {
        return $entry->trashed() && $user->can('restore', $entry);
    }

    private function mayForceDelete(Entry $entry, User $user): bool
    {
        return $entry->trashed() && $user->can('forceDelete', $entry);
    }

    /** A draft is already a draft: "moving" it again would only write a misleading audit row. */
    private function mayDraft(Entry $entry, User $user): bool
    {
        return ! $entry->trashed() && $entry->status !== EntryStatus::Draft->value && $user->can('publish', $entry);
    }

    private function mayDuplicate(Entry $entry, User $user): bool
    {
        return ! $entry->trashed() && $user->can('create', Entry::class)
            && app(CategoryScopes::class)->coversEntry($user, $entry);
    }

    /**
     * A publisher's Đăng is for anything not yet fully live. Fully live means the entry AND its
     * default-language text are published: the default language can change, so an entry can be
     * live while the text visitors now get by default is still a draft — Đăng has work to do
     * there. Skipping a live row here, before PublishEntry, keeps a re-click from writing another
     * Publish version, audit row and route sync for nothing. A sender's Gửi duyệt is for their
     * own drafts only (EntryPolicy::submit).
     */
    private function mayPublishOrSend(Entry $entry, User $user, string $locale): bool
    {
        if ($entry->trashed()) {
            return false;
        }

        if ($user->can('publish', $entry)) {
            return $entry->status !== EntryStatus::Published->value
                || $entry->translationFor($locale)?->status !== EntryStatus::Published->value;
        }

        return $entry->status === EntryStatus::Draft->value && $user->can('submit', $entry);
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
     * - A deadlock or serialization failure is different. PostgreSQL has aborted the transaction,
     *   and Laravel rethrows it out of a nested transaction WITHOUT rolling back to the savepoint,
     *   so not one more statement could run. The whole run is rolled back to the savepoint taken
     *   before it — the request transaction is usable again (the list still renders) — the
     *   selection is kept, and the person is asked to try again.
     *
     * @param  callable(Entry): bool  $eligible  the row is one this action is for
     * @param  callable(Entry): bool  $action  runs the action; true when the row really changed
     */
    private function runBulk(string $doneKey, string $skippedKey, callable $eligible, callable $action): void
    {
        if (count($this->selected) > self::BULK_LIMIT) {
            $this->toast(__('entries.bulk_too_many', ['max' => self::BULK_LIMIT]));

            return;
        }

        $tally = [$doneKey => 0, $skippedKey => 0, 'entries.bulk_governed' => 0, 'entries.bulk_failed' => 0];

        DB::beginTransaction();
        try {
            foreach ($this->selectedEntries() as $entry) {
                $tally[$this->bulkRow($entry, $eligible, $action, $doneKey, $skippedKey)]++;
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            if (! ConcurrencyError::is($e)) {
                throw $e;
            }
            $this->toast(__('entries.bulk_conflict'));

            return;
        }

        $this->selected = [];

        $parts = [__($doneKey, ['count' => $tally[$doneKey]])];
        foreach ([$skippedKey, 'entries.bulk_governed', 'entries.bulk_failed'] as $key) {
            if ($tally[$key] > 0) {
                $parts[] = __($key, ['count' => $tally[$key]]);
            }
        }
        $this->toast(implode(' ', $parts));
    }

    /**
     * One row of a bulk run, in its own savepoint.
     *
     * @param  callable(Entry): bool  $eligible
     * @param  callable(Entry): bool  $action
     * @return string the tally key the row counts under
     */
    private function bulkRow(Entry $entry, callable $eligible, callable $action, string $doneKey, string $skippedKey): string
    {
        if (! $eligible($entry)) {
            return $skippedKey;
        }

        try {
            return DB::transaction(fn (): bool => $action($entry)) ? $doneKey : $skippedKey;
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
     * The selection, locked in id order (see runBulk()). Trashed rows are loaded too, so each
     * rule can tell a row of the wrong kind (skipped) from one that no longer exists (left out).
     *
     * @return EloquentCollection<int, Entry>
     */
    private function selectedEntries(): EloquentCollection
    {
        // For a member limited to some categories every rule reads the row's primary category:
        // load it with the rows rather than once per row.
        $limited = app(CategoryScopes::class)->isLimited($this->actingUser());

        return Entry::withTrashed()->whereIn('ulid', $this->selected)->orderBy('id')->lockForUpdate()
            ->when($limited, fn (Builder $rows) => $rows->with(self::primaryCategoryOnly()))
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
        $this->dispatch('bs-toast', text: $message);
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
            [$this->viewKey, json_encode(['cols' => $this->cols, 'card' => $this->card], JSON_THROW_ON_ERROR), $user->getKey()],
        );
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
        return count($this->templateF) + count($this->transF) + count($this->creatorF) + count($this->langF)
            + ($this->when !== '' ? 1 : 0);
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
        $filter = new EntryListFilter($type === 'post' ? 'post' : 'page', $this->tab, $section?->taxonomy_id, $this->search, $this->langF, $this->creatorF,
            $this->templateF, $this->transF, $locales, $locale, $from, $until);
        // Whether this member has category limits here rides along on the rows' own query, so a
        // member without limits costs the list nothing more.
        $list = new EntryListQuery($filter, app(CurrentSite::class)->id(),
            fn (Builder $rows) => $scopes->selectHasLimits($rows, $user, EntryListQuery::COLUMNS));

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
        $pageUlids = [];
        $rowCovered = [];
        $rowEditable = [];
        foreach ($rows->items() as $item) {
            $pageUlids[] = $item['entry']->ulid;
            $rowCovered[$item['entry']->ulid] = ! $limited || $scopes->coversEntry($user, $item['entry']);
            $rowEditable[$item['entry']->ulid] = $user->can('update', $item['entry']);
        }

        $canDelete = $user->can('entries.delete.any') || $user->can('entries.delete.own');
        [$sortKey, $sortDir] = $this->sorting();

        return view('admin::livewire.entries.index', [
            'rows' => $rows,
            'pager' => $read['pager'],
            'parentPaths' => $read['parents'],
            'pageUlids' => $pageUlids,
            'rowCovered' => $rowCovered,
            'rowEditable' => $rowEditable,
            'scopeRefusal' => $limited ? $scopes->refusal($user) : null,
            'counts' => $counts,
            'facets' => $read['facets'],
            'activeChips' => $this->activeChips($read['facets']),
            'filterCount' => $this->filterCount(),
            'filterCountNarrow' => $this->filterCount() + ($this->tab !== 'all' ? 1 : 0),
            'sortOn' => $sortKey,
            'sortWay' => $sortDir,
            'sortIsDefault' => $sortKey === $this->defaultSortKey() && $sortDir === self::naturalDir($sortKey),
            'sorts' => array_values(array_filter(array_keys(self::SORTS), fn (string $key): bool => $this->sortsHere($key))),
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
            'canCreate' => $user->can('entries.create'),
            'canDelete' => $canDelete,
            // whoever may delete (their own or any) reaches the Trash, to restore what they removed
            'canSeeTrash' => $canDelete,
            'siteTime' => app(SiteTime::class),
        ]);
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
        $base = $query->with(self::ROW_RELATIONS)->get();

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
        if ($limited && $type === 'post') {
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
        if ($note === null) {
            $this->cursor = '';
        }
        $total = $known;

        if ($note !== null) {
            $current = $note['n'];
            $anchor = ['g' => $note['g'], 'v' => $note['v'], 'id' => $note['id']];
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
        $mark = fn (Entry $row, string $way, int $n): string => ListCursor::make($fingerprint, Cast::int($row->getAttribute('__g')),
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
        return hash('xxh128', json_encode([$this->entryType, $this->section, $this->tab, trim($this->search), $this->langF, $this->creatorF,
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
        $rows->load(self::ROW_RELATIONS_BUT_AUTHOR);
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
