<?php

namespace App\Modules\Admin\Livewire\Catalog;

use App\Modules\Admin\Support\Catalog\ProductListQuery;
use App\Modules\Admin\Support\Entries\ListCursor;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Support\CatalogMode;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The products list (site-scoped by BelongsToSite + RLS): status groups with counts and the Trash,
 * a name / SKU search, a sort menu and numbered pages, all read in SQL by ProductListQuery — one
 * page of rows at a time, at a flat query budget (enforced by a Query-Budget test). Numbered pages
 * reach the first 10,000 rows; past them the pager goes on with an encrypted cursor, and counts stop
 * at "hơn 10.000". Read access is gated by CatalogPolicy::viewAny; every mutation (trash, restore,
 * force-delete) re-authorizes through the policy because the livewire/update endpoint bypasses route
 * middleware. A trashed product keeps its sku/slug reserved until permanently deleted. The Trash is
 * a status group (?tab=trash); the old ?trash=1 link opens it too.
 *
 * What each member chooses to show on the list (Cột on a wide list, Hiện trên thẻ on phone cards) is
 * kept in their own users.preferences, under the list's key.
 */
#[Layout('admin::layouts.app')]
final class Index extends Component
{
    use AuthorizesRequests;

    /** Every status group a member may pick. */
    private const array TABS = ['all', ...ProductListQuery::STATUS_TABS, 'trash'];

    /** Sorts every mode has; the others (price, stock) exist only where products are sold. */
    private const array SORTS_ALWAYS = ['updated', 'name'];

    private const array SORTS_SELLING = ['price_asc', 'price_desc', 'stock_asc'];

    /** Optional columns of a wide list ("Cột"), and the ones on until the member says otherwise. */
    private const array COLS = ['cat', 'price', 'stock', 'badge'];

    /** What a phone card may add ("Hiện trên thẻ"), all off until the member turns them on. */
    private const array CARD = ['sku', 'cat', 'slug', 'tr'];

    /** Where this list's view choices live in the member's preferences (preferences.lists.catalog). */
    private const string VIEW_KEY = 'catalog';

    #[Url(as: 'tab', except: 'all')]
    public string $tab = 'all';

    /** Filters by translation name or sku (debounced in the view; ?q= survives navigation). */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'sort', except: '')]
    public string $sortKey = '';

    #[Url(as: 'page', except: 1)]
    public int $page = 1;

    #[Url(as: 'per_page', except: 20)]
    public int $perPage = 20;

    /** The page cursor past the numbered pages: encrypted, and bound to the view it was made for. */
    public string $cursor = '';

    /** @var list<string> what this member shows on a wide list ("Cột"); kept in their own preferences. */
    public array $cols = self::COLS;

    /** @var list<string> what this member adds to a phone card ("Hiện trên thẻ"); kept in their own preferences. */
    public array $card = [];

    private ?bool $brochure = null;

    /** Every request of this screen is refused once the member may no longer see products. */
    public function boot(): void
    {
        $this->authorize('viewAny', Product::class);
    }

    public function mount(): void
    {
        // the Trash used to be ?trash=1: old links open its group
        if (request()->boolean('trash')) {
            $this->tab = 'trash';
        }
        // #[Url] hydrates perPage straight from the query string, past updatedPerPage()
        if (! in_array($this->perPage, [10, 20, 50], true)) {
            $this->perPage = 20;
        }

        $lists = Cast::arr(Cast::arr($this->actingUser()->preferences)['lists'] ?? null);
        $view = Cast::arr($lists[self::VIEW_KEY] ?? null);
        $this->cols = isset($view['cols']) ? self::known(Cast::arr($view['cols']), self::COLS) : self::COLS;
        $this->card = self::known(Cast::arr($view['card'] ?? null), self::CARD);
        $this->clean();
    }

    public function updatedSearch(): void
    {
        $this->restart(); // a new term must not strand the user on a stale page number
    }

    /** A property changed from the browser: keep only values the screen knows, then return to page 1. */
    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'sortKey'], true)) {
            $this->clean();
            $this->restart();
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
        $this->clean();
        $this->restart();
    }

    /** The Sort menu: one choice. A sort this mode does not offer is ignored. */
    public function setSort(string $key): void
    {
        if (! in_array($key, $this->sortKeys(), true)) {
            return;
        }

        $this->sortKey = $key === 'updated' ? '' : $key;
        $this->restart();
    }

    /** A column header: the same key again goes back to the default order. */
    public function sortBy(string $key): void
    {
        $this->setSort($this->sorting() === $key ? 'updated' : $key);
    }

    /** "Bỏ lọc": the search and the status group, back to all; the sort stays. */
    public function clearAllFilters(): void
    {
        $this->search = '';
        $this->tab = 'all';
        $this->restart();
    }

    /** Back to the default sort (the sort chip's ✕). */
    public function resetSort(): void
    {
        $this->sortKey = '';
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
     * Footer pager past the numbered pages: the next or previous page, after or before a row the last
     * render named. A note that does not open, or one made for another view of the list, goes back to
     * the first page.
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

    /** Footer pager — page-pill/arrow clicks; the read clamps an out-of-range target. */
    public function gotoPage(int $page): void
    {
        $this->page = max(1, $page);
        $this->cursor = '';
    }

    /** Soft-delete: the product moves to the Trash, keeping its translations/images/sku. */
    public function trash(string $ulid): void
    {
        $product = Product::query()->where('ulid', $ulid)->firstOrFail();
        $this->authorize('delete', $product);
        $product->delete();
    }

    public function restore(string $ulid): void
    {
        $product = Product::withTrashed()->where('ulid', $ulid)->firstOrFail();
        $this->authorize('restore', $product);
        $product->restore();
    }

    public function forceDelete(string $ulid): void
    {
        $product = Product::withTrashed()->where('ulid', $ulid)->firstOrFail();
        $this->authorize('forceDelete', $product);
        $product->forceDelete(); // FK cascade removes translations/images; frees sku + slug
    }

    public function render(): View
    {
        $this->clean();
        // The list's statements are planned for this website's own numbers: PgBouncer keeps prepared
        // statements, and a plan made once for every website assumes a website of average size.
        // SET LOCAL ends with the request's tenancy transaction, so it never reaches another request.
        if (DB::transactionLevel() > 0) {
            DB::statement('SET LOCAL plan_cache_mode = force_custom_plan');
        }

        $locale = app(SiteLocale::class)->default();
        $cap = max(1, Cast::int(config('admin.catalog_list_cap', ProductListQuery::CAP)));
        $sortOn = $this->sorting();
        $canManage = Gate::allows('create', Product::class);

        try {
            // a savepoint inside the request's tenancy transaction: a failed read leaves it usable for the page
            $read = DB::transaction(function () use ($locale, $cap, $sortOn): array {
                $list = new ProductListQuery(app(CurrentSite::class)->id(), $this->tab, $this->search, $locale, $cap);
                $tally = $list->tabCounts();
                $counts = [];
                foreach (self::TABS as $tab) {
                    $counts[$tab] = $tally[$tab] ?? 0;
                }
                $page = $this->readPage($list, $sortOn, trim($this->search) === '' ? $counts[$this->tab] : null, $cap);
                $this->loadRows($page['rows']);

                return $page + ['counts' => $counts];
            });
        } catch (QueryException $e) {
            report($e);
            $read = ['rows' => new EloquentCollection, 'total' => 0, 'pager' => $this->pager(1, 0, 0, null, null, $cap, false),
                'counts' => array_fill_keys(self::TABS, 0), 'failed' => true];
        }

        return view('admin::livewire.catalog.index', [
            'rows' => $read['rows'],
            'pager' => $read['pager'],
            'matchCount' => $read['total'],
            'counts' => $read['counts'],
            'failed' => $read['failed'] ?? false,
            'cap' => $cap,
            'locale' => $locale,
            'canManage' => $canManage,
            'sortOn' => $sortOn,
            'sortIsDefault' => $sortOn === 'updated',
            'sorts' => $this->sortKeys(),
            'urls' => app(MediaUrlResolver::class),
            'siteTime' => app(SiteTime::class),
        ]);
    }

    /**
     * The sorts this site offers: price and stock only where products are sold (a brochure site shows neither).
     *
     * @return list<string>
     */
    private function sortKeys(): array
    {
        return $this->brochure() ? self::SORTS_ALWAYS : [...self::SORTS_ALWAYS, ...self::SORTS_SELLING];
    }

    /** Whether this site shows no price (read once per request: the sort checks ask for it again and again). */
    private function brochure(): bool
    {
        return $this->brochure ??= app(CatalogMode::class)->isBrochure();
    }

    /** The sort in force. */
    private function sorting(): string
    {
        return in_array($this->sortKey, $this->sortKeys(), true) ? $this->sortKey : 'updated';
    }

    /** Public properties come from the browser: keep only values the screen knows. */
    private function clean(): void
    {
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'all';
        // the Trash is for who may manage products
        if ($this->tab === 'trash' && ! Gate::allows('create', Product::class)) {
            $this->tab = 'all';
        }
        if ($this->sortKey !== '' && ! in_array($this->sortKey, $this->sortKeys(), true)) {
            $this->sortKey = '';
        }
    }

    /**
     * One page of rows. A numbered page is an offset while it stays within the first $cap rows; past
     * that the page follows the row an encrypted cursor names.
     *
     * @return array{rows: EloquentCollection<int, Product>, total: int, pager: array<string, mixed>}
     */
    private function readPage(ProductListQuery $list, string $sort, ?int $known, int $cap): array
    {
        $size = $this->perPage;
        $note = ListCursor::open($this->cursor, $this->fingerprint());
        if ($note === null) {
            $this->cursor = '';
        }
        $total = $known;
        $count = fn (): int => $list->count($list->filtered());
        $deepest = intdiv($cap, $size) + 1;

        if ($note !== null) {
            $current = $note['n'];
            $anchor = ['v' => $note['v'], 'id' => $note['id']];
            if ($note['w'] === 'before') {
                $rows = $list->rows($sort, 0, $size, $anchor, before: true);
                $more = true;
            } else {
                $rows = $list->rows($sort, 0, $size + 1, $anchor);
                $more = $rows->count() > $size;
                $rows = $rows->take($size)->values();
            }
            $this->page = $current;
        } else {
            if ($total === null && $this->page > 1) {
                $total = $count();
            }
            $current = max(1, min($this->page, $deepest));
            if ($total !== null && $total <= $cap) {
                $current = min($current, max(1, (int) ceil($total / $size)));
            }
            $this->page = $current;
            $rows = $list->rows($sort, ($current - 1) * $size, $size + 1);
            $more = $rows->count() > $size;
            $rows = $rows->take($size)->values();
        }

        if ($total === null) {
            $total = $note === null && $current === 1 && ! $more ? $rows->count() : $count();
        }

        $first = $rows->first();
        $last = $rows->last();
        $fingerprint = $this->fingerprint();
        $mark = fn (Product $row, string $way, int $n): string => ListCursor::make($fingerprint, '',
            Cast::stringOrNull($row->getAttribute('__sv')), $row->id, $way, $n);

        $prev = null;
        if ($current > 1) {
            $prev = ($current - 2) * $size <= $cap || $first === null
                ? ['page' => min($current - 1, $deepest)]
                : ['token' => $mark($first, 'before', $current - 1)];
        }
        $next = null;
        if ($more && $last !== null) {
            $next = $current * $size <= $cap ? ['page' => $current + 1] : ['token' => $mark($last, 'after', $current + 1)];
        }

        return ['rows' => $rows, 'total' => $total,
            'pager' => $this->pager($current, $total, $rows->count(), $prev, $next, $cap, $note !== null)];
    }

    /**
     * What the footer shows: the rows shown, the size of the list ("hơn 10.000" past the cap, with no
     * last page then), the page cells and the two arrows (a page number, or a cursor past the cap).
     *
     * @param  array{page: int}|array{token: string}|null  $prev
     * @param  array{page: int}|array{token: string}|null  $next
     * @return array{from: int, to: int, total: int|string, current: int, last: int|null, cells: list<int|null>, prev: array{page: int}|array{token: string}|null, next: array{page: int}|array{token: string}|null}
     */
    private function pager(int $current, int $total, int $shown, ?array $prev, ?array $next, int $cap, bool $keyset): array
    {
        $size = $this->perPage;
        $capped = $total > $cap;
        $last = $capped ? null : max(1, (int) ceil($total / $size));
        if ($last !== null && ! $keyset) {
            $prev ??= $current > 1 ? ['page' => $current - 1] : null;
            $next ??= $current < $last ? ['page' => $current + 1] : null;
        }

        // first, last and current±1; a gap is one "…" (null). Past the cap there is no last page,
        // and past the numbered pages only the current one is named.
        $deepest = intdiv($cap, $size) + 1;
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

        return ['from' => $from, 'to' => $shown > 0 ? $from + $shown - 1 : 0, 'total' => $capped ? Cast::string(__('catalog.count_over')) : $total,
            'current' => $current, 'last' => $last, 'cells' => $cells, 'prev' => $prev, 'next' => $next];
    }

    /** Names the view of the list a page cursor belongs to: the group, the search, the sort and the page size. */
    private function fingerprint(): string
    {
        return hash('xxh128', json_encode([$this->tab, trim($this->search), $this->sorting(), $this->perPage], JSON_THROW_ON_ERROR));
    }

    /**
     * Load what the rows show, for the page's ids only: the names, the first picture of each product
     * and its main category (with its name), each in one query.
     *
     * @param  EloquentCollection<int, Product>  $rows
     */
    private function loadRows(EloquentCollection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $rows->load([
            'translations:id,product_id,locale,name,slug',
            ...self::primaryCategoryOnly(),
            'taxonomies.translations:id,taxonomy_id,locale,name,slug',
        ]);

        // only the first picture of each product (by position), not its whole gallery
        $firsts = ProductImage::query()
            ->selectRaw('distinct on (product_id) id, product_id, media_id, position')
            ->whereIn('product_id', $rows->modelKeys())
            ->orderBy('product_id')->orderBy('position')->orderBy('id')
            ->get()->keyBy('product_id');
        $media = $firsts->isEmpty() ? collect() : Media::query()
            ->whereIn('id', $firsts->pluck('media_id')->all())
            ->get(['id', 'storage_key', 'mime'])->keyBy('id');
        foreach ($rows as $product) {
            $image = $firsts->get($product->id);
            $image?->setRelation('media', $media->get($image->media_id));
            $product->setRelation('images', new EloquentCollection($image === null ? [] : [$image]));
        }
    }

    /**
     * The main category only (pivot position 0), with the one column its name needs.
     *
     * @return array<string, Closure(Relation<*, *, *>): void>
     */
    private static function primaryCategoryOnly(): array
    {
        return ['taxonomies' => static function (Relation $primary): void {
            if ($primary instanceof BelongsToMany) {
                $primary->wherePivot('position', 0)->select(['taxonomies.id']);
            }
        }];
    }

    /**
     * Keep this member's Cột / Hiện trên thẻ choice in their own preferences, nobody else's. One UPDATE
     * writes just this list's entry inside preferences.lists, so whatever else was saved since the screen
     * opened (the theme, another list's choice, from another tab) is kept rather than overwritten.
     */
    private function saveView(): void
    {
        $user = $this->actingUser();

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
            [self::VIEW_KEY, json_encode(['cols' => $this->cols, 'card' => $this->card], JSON_THROW_ON_ERROR), $user->getKey()],
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

    private function actingUser(): User
    {
        $user = Auth::user();
        assert($user instanceof User);

        return $user;
    }
}
