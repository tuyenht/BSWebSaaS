<?php

namespace App\Modules\Admin\Livewire\Entries;

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
use App\Modules\Cms\Support\EntryBlockMediaReconciler;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\ContentSection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * The Pages list (design_handoff_pages_admin §3a). Lists a content_section's entries
 * (site-scoped by BelongsToSite + RLS), resolved from ?section=… — pages by default. Status
 * tabs + counts, name/slug search, and sortable columns all run server-side. The page tree is
 * kept intact: only parents are sorted, children ride directly under their parent, and a child
 * whose parent is filtered out is promoted to the top level (never orphaned under a wrong
 * parent). Pages are a small per-site set, so the tree is grouped + paged in memory at a flat
 * query budget (enforced by a Query-Budget test); other types stay flat. Read access is gated
 * by EntryPolicy::viewAny; the entry is addressed by ulid, never its raw id.
 */
#[Layout('admin::layouts.app')]
final class Index extends Component
{
    use AuthorizesRequests;

    public ?string $section = null;

    #[Url(as: 'tab', except: 'all')]
    public string $tab = 'all';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'sort', except: 'updated')]
    public string $sortKey = 'updated';

    #[Url(as: 'dir', except: 'desc')]
    public string $sortDir = 'desc';

    #[Url(as: 'page', except: 1)]
    public int $page = 1;

    /** Rows per page — cycled 10/20/50 by the footer's "Dòng/trang" control (§3a). */
    #[Url(as: 'per_page', except: 20)]
    public int $perPage = 20;

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

    /** Tabs that map to a concrete status; the rest: all = every non-trashed, trash = deleted. */
    private const array STATUS_TABS = ['published', 'draft', 'scheduled', 'private'];

    /** Sentinel value for the "no template" (null template_override) filter option. */
    private const string TEMPLATE_NONE = '__default__';

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
        $this->authorize('viewAny', Entry::class);
    }

    public function updatedSearch(): void
    {
        $this->page = 1; // a new term must not strand the user on a stale page number
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->page = 1;
    }

    public function sortBy(string $key): void
    {
        if (! in_array($key, ['name', 'status', 'creator', 'updated'], true)) {
            return;
        }

        if ($this->sortKey === $key) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortKey = $key;
            $this->sortDir = in_array($key, ['name', 'creator'], true) ? 'asc' : 'desc';
        }

        $this->page = 1;
    }

    /** Footer "Dòng/trang" control — cycles 10 → 20 → 50 → 10, then returns to page 1. */
    public function cyclePerPage(): void
    {
        $options = [10, 20, 50];
        $index = array_search($this->perPage, $options, true);
        $this->perPage = $options[$index === false ? 0 : ($index + 1) % count($options)];
        $this->page = 1;
    }

    /** Guards a live property change (e.g. a forged $wire.set) — mount()'s own clamp covers the URL. */
    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 20, 50], true)) {
            $this->perPage = 20;
        }
        $this->page = 1;
    }

    /** Footer pager — page-pill/arrow clicks; paginate() clamps an out-of-range target below. */
    public function gotoPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function toggleFilterValue(string $group, string $value): void
    {
        if ($group === 'lang') {
            $this->langF = $this->toggleIn($this->langF, $value);
        } elseif ($group === 'creator') {
            $this->creatorF = $this->toggleIn($this->creatorF, $value);
        } elseif ($group === 'template') {
            $this->templateF = $this->toggleIn($this->templateF, $value);
        }

        $this->page = 1;
    }

    public function resetFilterGroup(string $group): void
    {
        if ($group === 'lang') {
            $this->langF = [];
        } elseif ($group === 'creator') {
            $this->creatorF = [];
        } elseif ($group === 'template') {
            $this->templateF = [];
        }

        $this->page = 1;
    }

    public function clearAllFilters(): void
    {
        $this->langF = [];
        $this->creatorF = [];
        $this->templateF = [];
        $this->search = '';
        $this->page = 1;
    }

    /**
     * Row status menu → published (via PublishEntry, route-synced) or draft/private (via the
     * engine). A post whose section runs an approval workflow cannot be published from here —
     * that is the editor's approval block — so the refusal comes back as a plain toast.
     */
    public function changeStatus(string $ulid, string $status): void
    {
        $entry = $this->findEntry($ulid);
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

        try {
            app(TransitionEntryStatus::class)->handle($this->findEntry($ulid), EntryStatus::Scheduled, $this->actingUser(), $when);
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

    /** Inline rename — updates the default-locale translation title. */
    public function saveTitle(string $ulid, string $value): void
    {
        $entry = $this->findEntry($ulid);
        $this->authorize('update', $entry);

        $value = trim($value);
        $translation = $entry->translationFor(app(SiteLocale::class)->default());
        if ($value === '' || $translation === null || $translation->title === $value) {
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
     */
    public function saveSlug(string $ulid, string $value): void
    {
        $entry = $this->findEntry($ulid);
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

        $slug = Str::slug($value);
        if ($slug === '' || $slug === $translation->slug) {
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

    /** An inline rename of live content changes what visitors see — keep that version, like an editor save. */
    private function recordLiveEdit(Entry $entry, EntryTranslation $translation): void
    {
        if ($entry->status === 'published') {
            app(RecordEntryRevision::class)->forLocale($entry, $translation, RevisionKind::Edit, $this->actingUser());
        }
    }

    public function duplicate(string $ulid): void
    {
        $copy = app(DuplicateEntry::class)->handle($this->findEntry($ulid), $this->actingUser());
        // Materialize the copied block images into the usage index, exactly as the editor does on save.
        app(EntryBlockMediaReconciler::class)->reconcile($copy);

        $this->toast(__('entries.duplicated'));
    }

    public function trash(string $ulid): void
    {
        $entry = $this->findEntry($ulid);
        $this->authorize('delete', $entry);
        $entry->delete();

        $this->toast(__('entries.trashed'));
    }

    public function restore(string $ulid): void
    {
        $entry = $this->findEntry($ulid);
        $this->authorize('restore', $entry);
        $entry->restore();

        $this->toast(__('entries.restored'));
    }

    public function forceDelete(string $ulid): void
    {
        $entry = $this->findEntry($ulid);
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

    public function bulkPublish(): void
    {
        $user = $this->actingUser();
        $locale = app(SiteLocale::class)->default();
        $publish = app(PublishEntry::class);
        // Runs its own loop rather than bulkApply(): the outcome is two numbers, and a second
        // toast would simply replace the first, so what did publish would never be read.
        $entries = $this->selectedEntries();
        $done = 0;
        $governed = 0;
        $failed = 0;
        foreach ($entries as $entry) {
            try {
                $publish->handle($entry, $locale, $user);
                $done++;
            } catch (WorkflowGovernedException) {
                $governed++;   // left as is: only the editor's approval block may move it
            } catch (Throwable $e) {
                // A slug clash, or a row this user may neither publish nor submit: report it and
                // keep going. Aborting here would leave the selection half-applied, the checkbox
                // state stale and no toast at all — the user could not tell what had happened.
                report($e);
                $failed++;
            }
        }
        $this->selected = [];

        $this->toast(match (true) {
            $governed > 0 => __('entries.bulk_done_governed', ['count' => $done, 'governed' => $governed]),
            $failed > 0 => __('entries.bulk_done_failed', ['count' => $done, 'failed' => $failed]),
            default => __('entries.bulk_done', ['count' => $done]),
        });
    }

    public function bulkDraft(): void
    {
        $user = $this->actingUser();
        $transition = app(TransitionEntryStatus::class);
        $this->bulkApply(fn (Entry $entry) => $transition->handle($entry, EntryStatus::Draft, $user));
    }

    public function bulkDuplicate(): void
    {
        $user = $this->actingUser();
        $duplicate = app(DuplicateEntry::class);
        $reconciler = app(EntryBlockMediaReconciler::class);
        $this->bulkApply(function (Entry $entry) use ($duplicate, $reconciler, $user): void {
            $reconciler->reconcile($duplicate->handle($entry, $user));
        });
    }

    public function bulkTrash(): void
    {
        $this->bulkApply(function (Entry $entry): void {
            $this->authorize('delete', $entry);
            $entry->delete();
        });
    }

    public function bulkRestore(): void
    {
        $this->bulkApply(function (Entry $entry): void {
            $this->authorize('restore', $entry);
            $entry->restore();
        });
    }

    public function bulkForceDelete(): void
    {
        $this->bulkApply(function (Entry $entry): void {
            $this->authorize('forceDelete', $entry);
            $entry->forceDelete();
        });
    }

    /**
     * Apply an action to every selected entry, then clear the selection and toast the count.
     *
     * @param  callable(Entry): void  $action
     */
    private function bulkApply(callable $action): void
    {
        $entries = $this->selectedEntries();
        foreach ($entries as $entry) {
            $action($entry);
        }
        $this->selected = [];
        $this->toast(__('entries.bulk_done', ['count' => $entries->count()]));
    }

    /** @return EloquentCollection<int, Entry> */
    private function selectedEntries(): EloquentCollection
    {
        return Entry::withTrashed()->whereIn('ulid', $this->selected)->get();
    }

    private function findEntry(string $ulid): Entry
    {
        return Entry::withTrashed()->where('ulid', $ulid)->firstOrFail();
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

    public function render(): View
    {
        $section = $this->resolveSection();
        $type = $section instanceof ContentSection ? ($section->entry_type ?? 'page') : 'page';
        $siteLocale = app(SiteLocale::class);
        $locale = $siteLocale->default();
        $locales = $siteLocale->enabled();

        $query = Entry::query()->where('type', $type);
        $this->scopeToSection($query, $section);

        if ($this->tab === 'trash') {
            $query->onlyTrashed();
        } elseif (in_array($this->tab, self::STATUS_TABS, true)) {
            $query->where('status', $this->tab);
        }

        $term = trim($this->search);
        if ($term !== '') {
            $query->whereHas('translations', fn (Builder $t) => $t
                ->where('title', 'ilike', "%{$term}%")
                ->orWhere('slug', 'ilike', "%{$term}%"));
        }

        $base = $query->with([
            'translations:id,entry_id,locale,title,slug,status',
            'routes:id,entry_id,locale,path',
            'author:id,ulid,name',
        ])->get();

        $facets = [
            'lang' => $this->langFacet($base, $locales),
            'creator' => $this->creatorFacet($base),
            'template' => $this->templateFacet($base),
        ];

        // OR within a group, AND across groups — applied in memory (pages are a small per-site set).
        $filtered = $base->filter(fn (Entry $entry): bool => $this->passesFilters($entry));

        $ordered = $type === 'page'
            ? $this->buildTree($filtered, $locale)
            : $this->sortFlat($filtered, $locale);

        $user = $this->actingUser();
        $rows = $this->paginate($ordered);

        $pageUlids = [];
        foreach ($rows->items() as $item) {
            $pageUlids[] = $item['entry']->ulid;
        }

        return view('admin::livewire.entries.index', [
            'rows' => $rows,
            'pageUlids' => $pageUlids,
            'counts' => $this->counts($type, $section),
            'facets' => $facets,
            'activeChips' => $this->activeChips($facets),
            'matchCount' => $filtered->count(),
            'baseCount' => $base->count(),
            'locale' => $locale,
            'locales' => $locales,
            'homeSlug' => PathBuilder::HOME_SLUG,
            'sectionModel' => $section,
            'sectionKey' => $this->section,
            'canPublish' => $user->can('entries.publish'),
            'canCreate' => $user->can('entries.create'),
            'canDelete' => $user->can('entries.delete.any') || $user->can('entries.delete.own'),
            'canEdit' => $user->can('entries.edit.any') || $user->can('entries.edit.own'),
            'siteTime' => app(SiteTime::class),
        ]);
    }

    private function resolveSection(): ?ContentSection
    {
        if ($this->section === null || $this->section === '') {
            return null;
        }

        return ContentSection::query()->where('key', $this->section)->first();
    }

    /**
     * Scope a post query to the section it belongs to: entries whose PRIMARY category (pivot
     * position 0) is the section's bound taxonomy — so each collection section (Tin tức, Dịch vụ,
     * Dự án…) lists only its own posts instead of every post of the type. A section with no bound
     * taxonomy (Pages) or an unresolved key adds no constraint. Mirrors the storefront category
     * listing (exact primary match); a post filed under a sub-category is a later, ltree step.
     *
     * @param  Builder<Entry>  $query
     */
    private function scopeToSection(Builder $query, ?ContentSection $section): void
    {
        if ($section === null || $section->taxonomy_id === null) {
            return;
        }

        $taxonomyId = $section->taxonomy_id;
        $query->whereHas('taxonomies', fn (Builder $taxonomy): Builder => $taxonomy
            ->where('taxonomies.id', $taxonomyId)
            ->where('entry_taxonomies.position', 0));
    }

    /**
     * Tab counts in ONE grouped query on the (site_id, type, status) index (+ a trash count) —
     * flat, not per-row, so the render's query budget stays constant as rows grow (§6). `all`
     * sums every non-trashed status (so pending/archived, which have no tab, still count there).
     *
     * @return array{all: int, published: int, draft: int, scheduled: int, private: int, trash: int}
     */
    private function counts(string $type, ?ContentSection $section): array
    {
        $tally = ['all' => 0, 'published' => 0, 'draft' => 0, 'scheduled' => 0, 'private' => 0, 'trash' => 0];

        // One grouped query for every tab + the trash count, split by deleted_at via FILTER.
        // Scoped to the section (like the list) so a collection's tab counts match its own rows.
        $query = Entry::query()->withTrashed()->where('type', $type);
        $this->scopeToSection($query, $section);

        $rows = $query
            ->selectRaw('status, count(*) filter (where deleted_at is null) as live, count(*) filter (where deleted_at is not null) as trashed')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $status = $row->status;
            $live = is_numeric($row->getAttribute('live')) ? (int) $row->getAttribute('live') : 0;
            $trashed = is_numeric($row->getAttribute('trashed')) ? (int) $row->getAttribute('trashed') : 0;
            $tally['all'] += $live;      // includes pending/archived (real states without a tab)
            $tally['trash'] += $trashed;
            if (in_array($status, self::STATUS_TABS, true)) {
                $tally[$status] += $live;
            }
        }

        return $tally;
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
    private function creatorFacet(EloquentCollection $base): array
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

        return array_values($byUlid);
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

    /** OR within each group, AND across groups (an empty group adds no constraint). */
    private function passesFilters(Entry $entry): bool
    {
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
     * @param  array{lang: list<array{value: string, label: string, count: int, active: bool}>, creator: list<array{value: string, label: string, count: int, active: bool}>, template: list<array{value: string, label: string, count: int, active: bool}>}  $facets
     * @return list<array{group: string, value: string, label: string}>
     */
    private function activeChips(array $facets): array
    {
        $chips = [];
        foreach (['lang', 'creator', 'template'] as $group) {
            foreach ($facets[$group] as $option) {
                if ($option['active']) {
                    $chips[] = ['group' => $group, 'value' => $option['value'], 'label' => $option['label']];
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
     * @param  EloquentCollection<int, Entry>  $entries
     * @return Collection<int, array{entry: Entry, depth: int}>
     */
    private function sortFlat(EloquentCollection $entries, string $locale): Collection
    {
        /** @var list<array{entry: Entry, depth: int}> $rows */
        $rows = [];
        foreach ($entries->sort($this->comparator($locale))->values() as $entry) {
            $rows[] = ['entry' => $entry, 'depth' => 0];
        }

        return collect($rows);
    }

    /**
     * A stable comparator for the active sort key/direction, tie-broken by id.
     *
     * @return callable(Entry, Entry): int
     */
    private function comparator(string $locale): callable
    {
        $direction = $this->sortDir === 'asc' ? 1 : -1;
        $rank = ['published' => 0, 'scheduled' => 1, 'pending' => 2, 'draft' => 3, 'private' => 4, 'archived' => 5];

        $value = function (Entry $entry) use ($locale, $rank): string|int {
            return match ($this->sortKey) {
                'name' => $this->sortableTitle($entry, $locale),
                'status' => $rank[$entry->status] ?? 99,
                'creator' => $this->sortableCreator($entry),
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
            'sort' => $this->sortKey !== 'updated' ? $this->sortKey : null,
            'dir' => $this->sortDir !== 'desc' ? $this->sortDir : null,
            'lang' => $this->langF !== [] ? $this->langF : null,
            'author' => $this->creatorF !== [] ? $this->creatorF : null,
            'tpl' => $this->templateF !== [] ? $this->templateF : null,
            'per_page' => $this->perPage !== 20 ? $this->perPage : null,
        ]));
    }
}
