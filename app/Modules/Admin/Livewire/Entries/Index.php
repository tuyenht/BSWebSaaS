<?php

namespace App\Modules\Admin\Livewire\Entries;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Models\ContentSection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

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

    /** Rows per page (the design's per-page selector is a later step). */
    public int $perPage = 20;

    /** Tabs that map to a concrete status; the rest: all = every non-trashed, trash = deleted. */
    private const array STATUS_TABS = ['published', 'draft', 'scheduled', 'private'];

    public function mount(?string $section = null): void
    {
        $this->section = $section ?? request()->query('section');
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

    public function render(): View
    {
        $section = $this->resolveSection();
        $type = $section instanceof ContentSection ? ($section->entry_type ?? 'page') : 'page';
        $siteLocale = app(SiteLocale::class);
        $locale = $siteLocale->default();

        $query = Entry::query()->where('type', $type);

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

        $entries = $query->with([
            'translations:id,entry_id,locale,title,slug,status',
            'routes:id,entry_id,locale,path',
            'author:id,name',
        ])->get();

        $ordered = $type === 'page'
            ? $this->buildTree($entries, $locale)
            : $this->sortFlat($entries, $locale);

        return view('admin::livewire.entries.index', [
            'rows' => $this->paginate($ordered),
            'counts' => $this->counts($type),
            'locale' => $locale,
            'locales' => $siteLocale->enabled(),
            'homeSlug' => PathBuilder::HOME_SLUG,
            'sectionModel' => $section,
            'sectionKey' => $this->section,
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
     * Tab counts. Cheap COUNTs on the (site_id, type, status) index — a small per-site set;
     * flat (not per-row), so the render's query budget stays constant as rows grow.
     *
     * @return array{all: int, published: int, draft: int, scheduled: int, private: int, trash: int}
     */
    private function counts(string $type): array
    {
        $of = fn (string $status): int => Entry::query()->where('type', $type)->where('status', $status)->count();

        return [
            'all' => Entry::query()->where('type', $type)->count(),
            'published' => $of('published'),
            'draft' => $of('draft'),
            'scheduled' => $of('scheduled'),
            'private' => $of('private'),
            'trash' => Entry::onlyTrashed()->where('type', $type)->count(),
        ];
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
        ]));
    }
}
