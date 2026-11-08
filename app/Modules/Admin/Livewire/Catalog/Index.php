<?php

namespace App\Modules\Admin\Livewire\Catalog;

use App\Modules\Admin\Support\Catalog\ProductCategoryTree;
use App\Modules\Admin\Support\Catalog\ProductListFilter;
use App\Modules\Admin\Support\Catalog\ProductListQuery;
use App\Modules\Admin\Support\Entries\ListCursor;
use App\Modules\Admin\Support\SeoFields;
use App\Modules\Catalog\Actions\ChangeProductMainCategory;
use App\Modules\Catalog\Actions\ChangeProductSlug;
use App\Modules\Catalog\Actions\ChangeProductStatus;
use App\Modules\Catalog\Actions\RenameProduct;
use App\Modules\Catalog\Exceptions\ProductEditRefused;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Catalog\Support\CatalogMode;
use App\Modules\Catalog\Support\UniqueViolation;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Support\CategoryItemCounts;
use App\Modules\Cms\Taxonomy\TaxonomyRules;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\ConcurrencyError;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * The products list (site-scoped by BelongsToSite + RLS): status groups with counts and the Trash,
 * a name / SKU search, a sort menu and numbered pages, all read in SQL by ProductListQuery — one
 * page of rows at a time, at a flat query budget (enforced by a Query-Budget test). Numbered pages
 * reach the first 10,000 rows; past them the pager goes on with an encrypted cursor, and counts stop
 * at "hơn 10.000". A site whose plan has no catalog gets the plan card; a member without catalog.view
 * (CatalogPolicy::viewAny) gets the no-permission card; neither ever sees rows. The livewire/update
 * endpoint skips the admin route's middleware, so boot() asks again on every request for a signed-in
 * member of this site, and every write (trash, restore, force-delete, the view choices) checks the
 * plan and then the policy at class level before it looks a product up — refused the same way
 * whether that product exists or not. A trashed product keeps its sku/slug reserved until
 * permanently deleted. The Trash is a status group (?tab=trash); the old ?trash=1 link opens it too.
 *
 * Filters: Chuyên mục (a category and everything under it, by the product's main category), Bản dịch
 * (only on a site with two or more languages), Nhãn, and — where products are sold — Tồn kho and
 * Khuyến mãi. Values inside one filter are alternatives, the filters together all have to hold. The
 * numbers beside the values are the general ones (not changed by the other filters); a brochure site
 * has no stock or sale filter and refuses their values. Filter values travel in the URL (`cat`, `tr`,
 * `tag`, `stock`, `sale`); a visit with none of them applies the member's last choice.
 *
 * What each member chooses to show on the list (Cột on a wide list, Hiện trên thẻ on phone cards) and
 * the filters they last used (except the sale one) are kept in their own users.preferences, under the
 * list's key.
 */
#[Layout('admin::layouts.app')]
final class Index extends Component
{
    /** Every status group a member may pick. */
    private const array TABS = ['all', ...ProductListQuery::STATUS_TABS, 'trash'];

    /** Sorts every mode has; the others (price, stock) exist only where products are sold. */
    private const array SORTS_ALWAYS = ['updated', 'name'];

    private const array SORTS_SELLING = ['price_asc', 'price_desc', 'stock_asc'];

    /** Optional columns of a wide list ("Cột"), and the ones on until the member says otherwise. */
    private const array COLS = ['cat', 'price', 'stock', 'badge'];

    /** What the "Cột" menu offers; a brochure website has no price or stock, so no such entry either. */
    private const array COL_OPTIONS = ['cat', 'price', 'stock', 'badge'];

    /** What the "Hiện trên thẻ" menu offers; the translation chips only on a website with two or more languages. */
    private const array CARD_OPTIONS = ['sku', 'cat', 'slug', 'tr'];

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

    /** @var list<string> product categories (their ulids), a parent standing for everything under it */
    #[Url(as: 'cat')]
    public array $catF = [];

    /** @var list<string> translation states: full / partial / none */
    #[Url(as: 'tr')]
    public array $transF = [];

    /** @var list<string> labels: featured / best */
    #[Url(as: 'tag')]
    public array $tagF = [];

    /** @var list<string> stock kinds: in / out / none (selling sites only) */
    #[Url(as: 'stock')]
    public array $stockF = [];

    /** @var list<string> ['on'] while only products on sale show (selling sites only; not remembered) */
    #[Url(as: 'sale')]
    public array $saleF = [];

    /** @var array<string, bool> the filters whose whole list the member opened ("Xem thêm …") */
    public array $moreF = [];

    /** @var array<string, string> the words typed in a long filter's own search box */
    public array $qF = [];

    /** The filter properties, with the group name the view and the chips use for each. */
    private const array FILTERS = ['cat' => 'catF', 'trans' => 'transF', 'tag' => 'tagF', 'stock' => 'stockF', 'sale' => 'saleF'];

    /** The URL parameters of the filters: a visit with none of them applies the remembered ones. */
    private const array FILTER_PARAMS = ['cat', 'tr', 'tag', 'stock', 'sale'];

    /** The label columns a row can switch, by the name the menu sends. */
    private const array FLAGS = ['featured' => 'is_featured', 'best' => 'is_bestseller'];

    /** The most rows one bulk action takes: the largest page. */
    private const int SELECT_MAX = 50;

    /**
     * @var list<string> the products ticked on this page (their ulids). Only the server writes it; every bulk
     *                   action looks the rows up again, in this website and in the group on screen, and drops what is not there.
     */
    #[Locked]
    public array $selected = [];

    /** Whether the "Đổi chuyên mục chính" dialog is open (the dialog's own close sets it back). */
    public bool $catOpen = false;

    /** @var list<string> the products the open dialog changes the category of; only the server writes it */
    #[Locked]
    public array $catTargets = [];

    /** The message at the bottom of the screen (empty: none). Only the server writes it. */
    #[Locked]
    public string $toastMsg = '';

    #[Locked]
    public string $toastTone = 'success';

    /** Counts every message, so the same words twice are two messages and a stale timer never closes the newer one. */
    #[Locked]
    public int $toastSeq = 0;

    /**
     * What "Hoàn tác" does for the message on screen: the kind of change and the value to put back.
     * Locked, so the browser cannot make it say anything else; the undo is still an ordinary
     * authorized write that checks the product again.
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $undoOp = null;

    private ?bool $brochure = null;

    private ?ProductCategoryTree $tree = null;

    /** @var list<string> the category choice before a change from the browser (to tell what was ticked or unticked) */
    private array $catBefore = [];

    /**
     * Every request, the first load and each livewire/update alike: only a signed-in member of this
     * site (or a platform operator) gets past here. The update endpoint carries no auth or
     * tenant.member middleware, so a replayed snapshot from a signed-out browser stops at 403.
     */
    public function boot(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && ($user->roles->isNotEmpty() || app(PlatformAccess::class)->isSuperAdmin($user)), 403);
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
        // the last filters, unless this visit brings its own (the link wins)
        if (collect(self::FILTER_PARAMS)->every(fn (string $key): bool => request()->query($key) === null)) {
            $this->catF = Cast::strings($view['cat'] ?? null);
            $this->transF = Cast::strings($view['tr'] ?? null);
            $this->tagF = Cast::strings($view['tag'] ?? null);
            $this->stockF = Cast::strings($view['stock'] ?? null);
        }
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

    /** A property changed from the browser: keep only values the screen knows, then return to page 1. */
    public function updated(string $property): void
    {
        $name = Str::before($property, '.');
        if (in_array($name, ['tab', 'sortKey'], true)) {
            $this->clean();
            $this->restart();
        }
        if ($name === 'tab') {
            $this->selected = []; // a new status group starts with nothing ticked
        }
        if (in_array($name, self::FILTERS, true)) {
            $this->guardWrite('viewAny');
            if ($name === 'catF') {
                $this->settleCategories($this->catBefore);
            }
            $this->clean();
            $this->restart();
            $this->saveView();
        }
        if ($name === 'qF') {
            $this->clean();
        }
    }

    /** A filter value ticked or unticked from a chip's ✕ (fixed names from the view, any other value is ignored). */
    public function toggleFilterValue(string $group, string $value): void
    {
        $this->guardWrite('viewAny');
        if (! array_key_exists($group, self::FILTERS)) {
            return;
        }
        $property = self::FILTERS[$group];
        /** @var list<string> $now */
        $now = $this->{$property};
        if ($group === 'cat') {
            $before = $now;
            $this->catF = in_array($value, $now, true) ? array_values(array_diff($now, [$value])) : [...$now, $value];
            $this->settleCategories($before);
        } elseif (in_array($value, $this->knownValues($group), true)) {
            $this->{$property} = in_array($value, $now, true) ? array_values(array_diff($now, [$value])) : [...$now, $value];
        }
        $this->clean();
        $this->restart();
        $this->saveView();
    }

    /** "Bỏ chọn" of one filter. */
    public function resetFilterGroup(string $group): void
    {
        $this->guardWrite('viewAny');
        if (array_key_exists($group, self::FILTERS)) {
            $this->{self::FILTERS[$group]} = [];
            $this->restart();
            $this->saveView();
        }
    }

    /** "Xem thêm …" of a long filter: its whole list. */
    public function showMore(string $group): void
    {
        if (array_key_exists($group, self::FILTERS)) {
            $this->moreF[$group] = true;
        }
    }

    /** "Cột" changed: kept for this member only. */
    public function updatedCols(): void
    {
        $this->guardWrite('viewAny');
        $this->cols = self::known($this->cols, self::COLS);
        $this->saveView();
    }

    /** "Hiện trên thẻ" changed: kept for this member only. */
    public function updatedCard(): void
    {
        $this->guardWrite('viewAny');
        $this->card = self::known($this->card, self::CARD);
        $this->saveView();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'all';
        $this->selected = [];
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

    /** "Bỏ lọc": every filter, the search and the status group (back to all); the sort stays. */
    public function clearAllFilters(): void
    {
        $this->guardWrite('viewAny');
        $this->search = '';
        $this->tab = 'all';
        foreach (self::FILTERS as $property) {
            $this->{$property} = [];
        }
        $this->moreF = $this->qF = [];
        $this->restart();
        $this->saveView();
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
        $this->guardWrite('delete');
        $product = $this->live($ulid);
        if ($product === null) {
            return;
        }
        $product->delete();
        $this->say(__('catalog.toast_trashed', ['name' => $this->nameOf($product)]), 'success', ['kind' => 'untrash', 'ulid' => $ulid]);
    }

    /** Back from the Trash as a draft (the product is not on the website until someone publishes it again). */
    public function restore(string $ulid): void
    {
        $this->guardWrite('restore');
        $product = Product::onlyTrashed()->where('ulid', $ulid)->first();
        if ($product === null) {
            $this->say(__('catalog.row_gone'), 'error');

            return;
        }
        $before = $product->status;
        $product->restore();
        $product->forceFill(['status' => 'draft'])->save();
        $this->say(__('catalog.toast_restored', ['name' => $this->nameOf($product)]), 'success', ['kind' => 'retrash', 'ulid' => $ulid, 'status' => $before]);
    }

    /** The status menu on a row: published, draft or archived. A product in the Trash is refused. */
    public function setStatus(string $ulid, string $status): void
    {
        $this->guardWrite('update');
        abort_unless(in_array($status, ChangeProductStatus::STATUSES, true), 422);
        $product = $this->live($ulid);
        if ($product === null) {
            return;
        }
        if ($product->status === $status) {
            $this->say(__('catalog.status_unchanged', ['name' => $this->nameOf($product)]), 'info');

            return;
        }
        $before = $product->status;
        try {
            app(ChangeProductStatus::class)->handle($product, $status);
        } catch (RouteConflictException) {
            $this->say(__('catalog.status_route_conflict'), 'error');

            return;
        } catch (UniqueConstraintViolationException $e) {
            UniqueViolation::refusal($e);
            $this->say(__('catalog.status_route_conflict'), 'error');

            return;
        }
        $this->say(__('catalog.toast_status_'.$status, ['name' => $this->nameOf($product)]), 'success', ['kind' => 'status', 'ulid' => $ulid, 'status' => $before]);
    }

    /**
     * Quick rename from the row: the default language's name, under the editor's name rule. The row
     * shows the answer: `ok`, the stored `name`, and on a refusal its plain `error` and whether the
     * field `keep`s open with what was typed (an empty name only says so and puts the old name back).
     *
     * @return array{ok: bool, keep: bool, name: string, error: string}
     */
    public function saveName(string $ulid, string $value): array
    {
        $this->guardWrite('update');
        $product = $this->live($ulid);
        if ($product === null) {
            return ['ok' => false, 'keep' => false, 'name' => '', 'error' => ''];
        }
        $locale = app(SiteLocale::class)->default();
        $before = $this->nameOf($product);
        $value = mb_substr($value, 0, 5000);

        try {
            $name = app(RenameProduct::class)->handle($product, $value, $locale);
        } catch (ProductEditRefused $refused) {
            if (RenameProduct::tidy($value) === '') {
                $this->say($refused->getMessage(), 'info');

                return ['ok' => false, 'keep' => false, 'name' => $before, 'error' => ''];
            }

            return ['ok' => false, 'keep' => true, 'name' => $before, 'error' => $refused->getMessage()];
        }

        if ($name !== $before) {
            $this->say(__('catalog.toast_renamed'), 'success', ['kind' => 'name', 'ulid' => $ulid, 'name' => $before]);
        }

        return ['ok' => true, 'keep' => false, 'name' => $name, 'error' => ''];
    }

    /**
     * Quick address change from the row, through the same Action as every other screen. The answer
     * is `ok`, the stored `slug`, and on a refusal its plain `error` (the field stays open with what
     * was typed).
     *
     * @return array{ok: bool, keep: bool, slug: string, error: string}
     */
    public function saveSlug(string $ulid, string $value): array
    {
        $this->guardWrite('update');
        $product = $this->live($ulid);
        if ($product === null) {
            return ['ok' => false, 'keep' => false, 'slug' => '', 'error' => ''];
        }
        $locale = app(SiteLocale::class)->default();
        $value = mb_substr($value, 0, 5000);

        try {
            $change = app(ChangeProductSlug::class)->handle($product, $value, $locale);
        } catch (ProductEditRefused $refused) {
            // the field shows the address as it was cleaned (what the member typed, made plain), not the raw text
            try {
                $cleaned = app(ChangeProductSlug::class)->clean($value, $locale);
            } catch (ProductEditRefused) {
                $cleaned = '';
            }

            return ['ok' => false, 'keep' => true, 'slug' => $cleaned, 'error' => $refused->getMessage()];
        }

        if ($change['changed']) {
            $path = app(PathBuilder::class)->forProduct($product->primaryCategory(), $change['slug'], $locale);
            $this->say(
                __('catalog.toast_slug_changed', ['path' => $path]).($change['redirected'] ? ' '.__('catalog.toast_slug_redirect') : ''),
                'success',
                ['kind' => 'slug', 'ulid' => $ulid, 'slug' => $change['old']],
            );
        }

        return ['ok' => true, 'keep' => false, 'slug' => $change['slug'], 'error' => ''];
    }

    /** Put a label ("Nổi bật" / "Bán chạy") on a product or take it off: `$on` is the state the member asked for, so a second click on a stale menu changes nothing. */
    public function toggleFlag(string $ulid, string $flag, bool $on): void
    {
        $this->guardWrite('update');
        abort_unless(isset(self::FLAGS[$flag]), 422);
        $product = $this->live($ulid);
        if ($product === null) {
            return;
        }
        $column = self::FLAGS[$flag];
        $was = (bool) $product->getAttribute($column);
        $message = __($on ? 'catalog.toast_flag_on' : 'catalog.toast_flag_off', ['label' => __('catalog.tag_'.$flag), 'name' => $this->nameOf($product)]);
        if ($was === $on) {
            $this->say($message);

            return;
        }
        $product->forceFill([$column => $on])->save();
        $this->say($message, 'success', ['kind' => 'flag', 'ulid' => $ulid, 'flag' => $flag, 'was' => $was]);
    }

    /** "Hoàn tác" on the message: the opposite write, authorized and looked up again; a reason on screen when it cannot be done. */
    public function undoLast(): void
    {
        $op = $this->undoOp;
        $this->undoOp = null;
        $this->toastMsg = '';
        if ($op === null) {
            return;
        }
        $ulid = Cast::string($op['ulid'] ?? '');

        switch ($op['kind'] ?? null) {
            case 'untrash':
                $this->guardWrite('restore');
                $product = Product::onlyTrashed()->where('ulid', $ulid)->first();
                $product?->restore();
                break;
            case 'retrash':
                $this->guardWrite('delete');
                $product = $this->live($ulid, quiet: true);
                if ($product !== null) {
                    $status = Cast::string($op['status'] ?? '');
                    abort_unless(in_array($status, ChangeProductStatus::STATUSES, true), 422);
                    $product->forceFill(['status' => $status])->save();
                    $product->delete();
                }
                break;
            case 'status':
                $this->guardWrite('update');
                $status = Cast::string($op['status'] ?? '');
                abort_unless(in_array($status, ChangeProductStatus::STATUSES, true), 422);
                $product = $this->live($ulid, quiet: true);
                try {
                    if ($product !== null) {
                        app(ChangeProductStatus::class)->handle($product, $status);
                    }
                } catch (RouteConflictException) {
                    $this->say(__('catalog.undo_conflict'), 'error');

                    return;
                } catch (UniqueConstraintViolationException $e) {
                    UniqueViolation::refusal($e);
                    $this->say(__('catalog.undo_conflict'), 'error');

                    return;
                }
                break;
            case 'name':
                $this->guardWrite('update');
                $product = $this->live($ulid, quiet: true);
                try {
                    if ($product !== null) {
                        app(RenameProduct::class)->handle($product, Cast::string($op['name'] ?? ''), app(SiteLocale::class)->default());
                    }
                } catch (ProductEditRefused) {
                    $this->say(__('catalog.undo_gone'), 'error');

                    return;
                }
                break;
            case 'slug':
                $this->guardWrite('update');
                $product = $this->live($ulid, quiet: true);
                try {
                    if ($product !== null) {
                        app(ChangeProductSlug::class)->handle($product, Cast::string($op['slug'] ?? ''), app(SiteLocale::class)->default());
                    }
                } catch (ProductEditRefused) {
                    $this->say(__('catalog.undo_slug_taken'), 'error');

                    return;
                }
                break;
            case 'flag':
                $this->guardWrite('update');
                $flag = Cast::string($op['flag'] ?? '');
                abort_unless(isset(self::FLAGS[$flag]), 422);
                $product = $this->live($ulid, quiet: true);
                $product?->forceFill([self::FLAGS[$flag] => (bool) ($op['was'] ?? false)])->save();
                break;
            case 'bulk_status':
            case 'bulk_untrash':
            case 'bulk_retrash':
            case 'bulk_category':
                $this->undoBulk($op);

                return;
            default:
                return;
        }

        $this->say($product === null ? __('catalog.undo_gone') : __('catalog.toast_undone'), $product === null ? 'error' : 'success');
    }

    /** Close the message the member (or its timer) names; a newer message stays. */
    public function closeToast(int $seq): void
    {
        if ($seq === $this->toastSeq) {
            $this->toastMsg = '';
            $this->undoOp = null;
        }
    }

    /** A product in the Trash cannot be opened for editing: say what to do. */
    public function trashedNote(): void
    {
        $this->guardWrite('viewAny');
        $this->say(__('catalog.trash_note'), 'info');
    }

    /**
     * @param  array<string, mixed>|null  $undo
     */
    private function say(string $message, string $tone = 'success', ?array $undo = null): void
    {
        $this->toastMsg = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
        $this->undoOp = $undo;
    }

    /** A product that is not in the Trash, by its ulid; a message says so when it is gone (unless asked to stay quiet). */
    private function live(string $ulid, bool $quiet = false): ?Product
    {
        $product = Product::query()->where('ulid', $ulid)->first();
        if ($product === null && ! $quiet) {
            $this->say(__('catalog.row_gone'), 'error');
        }

        return $product;
    }

    private function nameOf(Product $product): string
    {
        $translation = $product->translations()->where('locale', app(SiteLocale::class)->default())->first();

        return $translation instanceof ProductTranslation ? $translation->name : __('catalog.untitled');
    }

    /**
     * The check boxes: a row's own box, the header box and the phone's "Chọn cả trang" all send the
     * ulids they stand for. All of them already ticked → they are unticked, else they are added.
     * Capped at the largest page; nothing is trusted until a bulk action looks the rows up again.
     *
     * @param  list<string>  $ulids
     */
    public function toggleSelect(array $ulids): void
    {
        $this->guardWrite('create');
        $ulids = array_slice(array_values(array_unique(array_filter($ulids, 'is_string'))), 0, self::SELECT_MAX);
        $allIn = $ulids !== [] && array_diff($ulids, $this->selected) === [];
        $next = $allIn ? array_diff($this->selected, $ulids) : [...$this->selected, ...$ulids];
        $this->selected = array_slice(array_values(array_unique($next)), 0, self::SELECT_MAX);
    }

    /** "Bỏ chọn". */
    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /** "Đăng (N)": the ticked products that are not published go live. */
    public function bulkPublish(): void
    {
        $this->bulkStatus('published');
    }

    /** "Gỡ khỏi website (N)": the ticked products that are published are taken off the website (archived). */
    public function bulkArchive(): void
    {
        $this->bulkStatus('archived');
    }

    /** "Chuyển vào Thùng rác (N)". */
    public function bulkTrash(): void
    {
        $this->guardWrite('delete');
        $done = 0;
        $undo = [];
        $last = null;
        if (! $this->atomic(function () use (&$done, &$undo, &$last): void {
            foreach ($this->pickRows($this->selected, false) as $product) {
                $product->delete();
                $undo[] = $product->ulid;
                $last = $product;
                $done++;
            }
        })) {
            return;
        }
        if ($done === 0) {
            $this->staleSelection();

            return;
        }
        $this->selected = [];
        $this->say(
            $done === 1 && $last !== null ? __('catalog.toast_trashed', ['name' => $this->loadedName($last)]) : __('catalog.bulk_trashed', ['count' => $done]),
            'success',
            ['kind' => 'bulk_untrash', 'items' => $undo],
        );
    }

    /** "Khôi phục (N)" on the Trash: back as drafts. */
    public function bulkRestore(): void
    {
        $this->guardWrite('restore');
        $done = 0;
        $undo = [];
        if (! $this->atomic(function () use (&$done, &$undo): void {
            foreach ($this->pickRows($this->selected, true) as $product) {
                $undo[$product->ulid] = $product->status;
                $product->restore();
                $product->forceFill(['status' => 'draft'])->save();
                $done++;
            }
        })) {
            return;
        }
        if ($done === 0) {
            $this->staleSelection();

            return;
        }
        $this->selected = [];
        $this->say(
            __('catalog.bulk_restored', ['count' => $done]),
            'success',
            ['kind' => 'bulk_retrash', 'items' => $undo],
        );
    }

    /** "Xóa vĩnh viễn (N)" on the Trash, after the member confirmed; no undo. */
    public function bulkForceDelete(): void
    {
        $this->guardWrite('forceDelete');
        $done = 0;
        $name = '';
        if (! $this->atomic(function () use (&$done, &$name): void {
            // FK cascade removes translations, images and routes; the sku and the address are free again
            foreach ($this->pickRows($this->selected, true, 'none') as $product) {
                $name = $this->loadedName($product);
                $product->forceDelete();
                $done++;
            }
        })) {
            return;
        }
        if ($done === 0) {
            $this->staleSelection();

            return;
        }
        $this->selected = [];
        $this->say($done === 1 ? __('catalog.bulk_purged_one', ['name' => $name]) : __('catalog.bulk_purged', ['count' => $done]));
    }

    /**
     * The "Đổi chuyên mục…" dialog: for one row (its ⋯ menu) or for the ticked ones. Opens only for
     * live products of this website that are still there.
     */
    public function openCategory(?string $ulid = null): void
    {
        $this->guardWrite('update');
        $wanted = $ulid !== null ? [$ulid] : $this->selected;
        $found = $this->pickRows($wanted, false, 'none')->pluck('ulid')->all();
        if ($found === []) {
            $this->catOpen = false;
            $this->catTargets = [];
            $this->staleSelection();

            return;
        }
        $this->catTargets = Cast::strings($found);
        $this->catOpen = true;
    }

    /** The dialog was closed (✕, Hủy, Esc or the backdrop). */
    public function updatedCatOpen(): void
    {
        if (! $this->catOpen) {
            $this->catTargets = [];
        }
    }

    /** "Đổi chuyên mục": the main category of the dialog's products becomes the one picked. */
    public function changeCategory(string $categoryUlid): void
    {
        $this->guardWrite('update');
        $targets = $this->catTargets;
        $this->catOpen = false;
        $this->catTargets = [];
        $category = Taxonomy::query()->where('vocabulary', 'product_cat')->where('ulid', $categoryUlid)->with('translations')->first();
        if ($category === null) {
            $this->say(__('catalog.bulk_cat_gone'), 'error');

            return;
        }

        $result = null;
        if (! $this->atomic(function () use ($targets, $category, &$result): void {
            $rows = $this->pickRows($targets, false, 'all');
            $result = app(ChangeProductMainCategory::class)->handle($rows, $category);
        })) {
            return;
        }
        if ($result === null || $result['moved'] + $result['same'] + $result['failed'] === 0) {
            $this->staleSelection();

            return;
        }

        $name = Cast::string($category->translations->where('locale', app(SiteLocale::class)->default())->pluck('name')->first(), __('catalog.unnamed'));
        if ($result['moved'] === 0 && $result['failed'] === 0) {
            $this->say(__('catalog.bulk_cat_same', ['category' => $name]), 'info');

            return;
        }
        $parts = [];
        if ($result['moved'] > 0) {
            $parts[] = __('catalog.bulk_cat_done', ['count' => $result['moved'], 'category' => $name]);
            if ($result['redirected'] > 0) {
                $parts[] = __('catalog.bulk_cat_redirect', ['count' => $result['redirected']]);
            }
        }
        if ($result['failed'] > 0) {
            $parts[] = __('catalog.bulk_cat_failed', ['count' => $result['failed']]);
        }
        $this->say(implode(' ', $parts), $result['moved'] > 0 ? 'success' : 'error', $result['moved'] > 0 ? ['kind' => 'bulk_category', 'items' => $result['before']] : null);
    }

    /** One status change for the ticked products: those it applies to are changed, the others are counted and left as they are. */
    private function bulkStatus(string $to): void
    {
        $this->guardWrite('update');
        $label = __($to === 'published' ? 'catalog.bulk_publish' : 'catalog.bulk_archive');
        $done = $skipped = $failed = 0;
        $undo = [];
        $last = null;
        if (! $this->atomic(function () use ($to, &$done, &$skipped, &$failed, &$undo, &$last): void {
            foreach ($this->pickRows($this->selected, false) as $product) {
                // "Đăng" is for what is not live yet; "Gỡ khỏi website" only for what is live
                if (($to === 'published') === ($product->status === 'published')) {
                    $skipped++;

                    continue;
                }
                $before = $product->status;
                try {
                    app(ChangeProductStatus::class)->handle($product, $to);
                } catch (RouteConflictException) {
                    $failed++;

                    continue;
                } catch (UniqueConstraintViolationException $e) {
                    UniqueViolation::refusal($e);
                    $failed++;

                    continue;
                }
                $undo[$product->ulid] = $before;
                $last = $product;
                $done++;
            }
        })) {
            return;
        }

        $why = __($to === 'published' ? 'catalog.bulk_why_published' : 'catalog.bulk_why_unpublished', ['count' => $skipped]);
        if ($done === 0) {
            if ($failed > 0) {
                $this->say(__('catalog.bulk_failed_route', ['count' => $failed]), 'error');
            } elseif ($skipped > 0) {
                $this->say(__('catalog.bulk_none_can', ['label' => $label, 'why' => $why]), 'info');
            } else {
                $this->staleSelection();
            }

            return;
        }
        $parts = [$done === 1 && $last !== null
            ? __('catalog.toast_status_'.$to, ['name' => $this->loadedName($last)])
            : __('catalog.bulk_status_'.$to, ['count' => $done])];
        if ($failed > 0) {
            $parts[] = __('catalog.bulk_failed_route', ['count' => $failed]);
        }
        $this->say(implode(' ', $parts), 'success', ['kind' => 'bulk_status', 'items' => $undo]);
    }

    /**
     * Put the bulk undo back: the same Actions as the change itself, each product looked up again and
     * the member's right asked again. A product that is gone, or whose address is now taken, stays as it is.
     *
     * @param  array<string, mixed>  $op
     */
    private function undoBulk(array $op): void
    {
        $items = Cast::arr($op['items'] ?? null);
        $kind = Cast::string($op['kind'] ?? '');
        $back = 0;
        $blocked = 0;
        $ulids = array_values(array_map(static fn (mixed $ulid): string => Cast::string($ulid), $kind === 'bulk_untrash' ? $items : array_keys($items)));

        if (! $this->atomic(function () use ($kind, $items, $ulids, &$back, &$blocked): void {
            switch ($kind) {
                case 'bulk_status':
                    $this->guardWrite('update');
                    foreach ($this->pickRows($ulids, false, inView: false) as $product) {
                        $status = Cast::string($items[$product->ulid] ?? '');
                        abort_unless(in_array($status, ChangeProductStatus::STATUSES, true), 422);
                        try {
                            app(ChangeProductStatus::class)->handle($product, $status);
                            $back++;
                        } catch (RouteConflictException) {
                            $blocked++;
                        } catch (UniqueConstraintViolationException $e) {
                            UniqueViolation::refusal($e);
                            $blocked++;
                        }
                    }
                    break;
                case 'bulk_untrash':
                    $this->guardWrite('restore');
                    foreach ($this->pickRows($ulids, true, inView: false) as $product) {
                        $product->restore();
                        $back++;
                    }
                    break;
                case 'bulk_retrash':
                    $this->guardWrite('delete');
                    foreach ($this->pickRows($ulids, false, inView: false) as $product) {
                        $status = Cast::string($items[$product->ulid] ?? '');
                        abort_unless(in_array($status, ChangeProductStatus::STATUSES, true), 422);
                        $product->forceFill(['status' => $status])->save();
                        $product->delete();
                        $back++;
                    }
                    break;
                case 'bulk_category':
                    $this->guardWrite('update');
                    $this->undoCategories($items, $ulids, $back, $blocked);
                    break;
            }
        })) {
            return;
        }

        if ($back === 0) {
            $this->say(__($blocked > 0 ? 'catalog.undo_conflict' : 'catalog.undo_gone'), 'error');

            return;
        }
        $left = count($ulids) - $back;
        $this->say($left > 0 ? __('catalog.undo_partial', ['count' => $back, 'left' => $left]) : __('catalog.toast_undone'), 'success');
    }

    /**
     * @param  array<string, mixed>  $items  product ulid → its category ulids, main first
     * @param  list<string>  $ulids
     */
    private function undoCategories(array $items, array $ulids, int &$back, int &$blocked): void
    {
        $wanted = [];
        foreach ($items as $list) {
            array_push($wanted, ...Cast::strings($list));
        }
        $taxonomies = $wanted === [] ? collect() : Taxonomy::query()->where('vocabulary', 'product_cat')->whereIn('ulid', array_values(array_unique($wanted)))
            ->with('translations')->get()->keyBy('ulid');
        $rows = $this->pickRows($ulids, false, 'all', inView: false);
        $action = app(ChangeProductMainCategory::class);
        $routes = $action->routePaths(array_values($rows->modelKeys()));
        $counts = app(CategoryItemCounts::class);
        foreach ($rows as $product) {
            $ids = [];
            foreach (Cast::strings($items[$product->ulid] ?? null) as $key) {
                $found = $taxonomies->get($key);
                if ($found instanceof Taxonomy) {
                    $ids[] = $found->id;
                }
            }
            $main = $ids === [] ? null : $taxonomies->firstWhere('id', $ids[0]);
            try {
                $counts->batch(fn (): bool => DB::transaction(fn (): bool => $action->file($product, $ids, $main instanceof Taxonomy ? $main : null, $routes[$product->id] ?? [])));
                $back++;
            } catch (RouteConflictException) {
                $blocked++;
            } catch (UniqueConstraintViolationException $e) {
                UniqueViolation::refusal($e);
                $blocked++;
            }
        }
    }

    /**
     * Run a bulk change as one unit inside the request's transaction, with the category totals it moves
     * written once per category at the end. A deadlock or serialization failure rolls the whole unit back
     * (the transaction is aborted, nothing more could run in it), keeps the selection and asks the member
     * to try again; any other failure goes on up. Returns whether the unit ran.
     *
     * @param  Closure(): void  $work
     */
    private function atomic(Closure $work): bool
    {
        DB::beginTransaction();
        try {
            app(CategoryItemCounts::class)->batch($work);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            if (! ConcurrencyError::is($e)) {
                throw $e;
            }
            $this->say(__('catalog.bulk_conflict'), 'error');

            return false;
        }

        return true;
    }

    /**
     * The products named by $ulids that are really there: this website's, live (or in the Trash when
     * $trashed), at most one page of them (`$inView`: and in what the screen shows now), locked in id order so two overlapping runs queue instead of
     * deadlocking. What is not there is dropped without a word. `$with`: the main category (the totals and
     * the address read it), every category, or nothing.
     *
     * @param  list<string>  $ulids
     * @return EloquentCollection<int, Product>
     */
    private function pickRows(array $ulids, bool $trashed, string $with = 'primary', bool $inView = true): EloquentCollection
    {
        $ulids = array_slice(array_values(array_unique(array_filter($ulids, 'is_string'))), 0, self::SELECT_MAX);
        if ($ulids === []) {
            return new EloquentCollection;
        }

        $relations = ['translations:id,product_id,locale,name,slug'];
        if ($with === 'primary') {
            $relations = [...$relations, ...self::categoryRelations(app(PermalinkConfig::class)->pattern('products', 'products') === 'category_path')];
        } elseif ($with === 'all') {
            $relations['taxonomies'] = static function (Relation $categories): void {
                if ($categories instanceof BelongsToMany) {
                    $categories->select(['taxonomies.id', 'taxonomies.ulid']);
                }
            };
        }

        if ($inView) {
            // only what the screen shows now: the status group, the filters and the search all hold
            if (($this->tab === 'trash') !== $trashed) {
                return new EloquentCollection;
            }
            $tree = $this->categoryTree();
            $this->catF = $tree->known($this->catF);
            $base = $this->listQuery($this->listFilter($tree))->filtered();
        } else {
            $base = $trashed ? Product::onlyTrashed() : Product::query();
        }

        return $base->whereIn('products.ulid', $ulids)->orderBy('products.id')->lockForUpdate()
            ->with($relations)->get();
    }

    /** The ticked products are gone or were changed by someone else: say so (the selection is dropped). */
    private function staleSelection(): void
    {
        $this->selected = [];
        $this->say(__('catalog.row_gone'), 'error');
    }

    /** The default-language name of a product whose translations are loaded (no query). */
    private function loadedName(Product $product): string
    {
        $translation = $product->translations->firstWhere('locale', app(SiteLocale::class)->default());

        return $translation instanceof ProductTranslation ? $translation->name : __('catalog.untitled');
    }

    public function forceDelete(string $ulid): void
    {
        $this->guardWrite('forceDelete');
        // FK cascade removes translations/images; frees sku + slug
        Product::withTrashed()->where('ulid', $ulid)->firstOrFail()->forceDelete();
        $this->toastMsg = '';
        $this->undoOp = null;
    }

    public function render(): View
    {
        // The plan first, then the permission (as the design orders them): either card, and nothing below is read.
        if (! $this->entitled()) {
            return view('admin::livewire.catalog.plan', ['supportMail' => Cast::string(config('support.mailbox')) ?: Cast::string(config('mail.from.address'))]);
        }
        if (! Gate::allows('viewAny', Product::class)) {
            return view('admin::livewire.catalog.noperm', ['role' => RoleLabel::topOf($this->actingUser())]);
        }

        $this->clean();
        // The list's statements are planned for this website's own numbers: PgBouncer keeps prepared
        // statements, and a plan made once for every website assumes a website of average size.
        // SET LOCAL ends with the request's tenancy transaction, so it never reaches another request.
        if (DB::transactionLevel() > 0) {
            DB::statement('SET LOCAL plan_cache_mode = force_custom_plan');
        }

        $siteLocale = app(SiteLocale::class);
        $locale = $siteLocale->default();
        $locales = $siteLocale->enabled();
        $selling = ! $this->brochure();
        $cap = max(1, Cast::int(config('admin.catalog_list_cap', ProductListQuery::CAP)));
        $sortOn = $this->sorting();
        $canManage = Gate::allows('create', Product::class);

        try {
            // a savepoint inside the request's tenancy transaction: a failed read leaves it usable for the page
            $read = DB::transaction(function () use ($locales, $selling, $cap, $sortOn): array {
                $tree = $this->categoryTree();
                $this->catF = $tree->known($this->catF);
                $filter = $this->listFilter($tree);
                $list = $this->listQuery($filter);
                $tally = $list->tabCounts();
                $counts = [];
                foreach (self::TABS as $tab) {
                    $counts[$tab] = $tally[$tab] ?? 0;
                }
                // a website past the cap shows its values with no number: a count per value of a list that large would walk it again and again
                $facets = $this->facets($tree, ($tally['all'] ?? 0) <= $cap ? $list->valueCounts($locales, $selling) : null, count($locales) >= 2, $selling, $cap);
                $page = $this->readPage($list, $sortOn, trim($this->search) === '' && ! $filter->any() ? $counts[$this->tab] : null, $cap);
                $this->loadRows($page['rows']);
                $picked = $this->pruneSelection($list);

                return $page + ['picked' => $picked, 'tree' => $tree, 'counts' => $counts, 'facets' => $facets, 'chips' => $this->filterChips($tree, $facets), 'filterCount' => $this->filterCount($tree)];
            });
        } catch (QueryException $e) {
            report($e);
            $read = ['rows' => new EloquentCollection, 'total' => 0, 'pager' => $this->pager(1, 0, 0, null, null, $cap, false),
                'counts' => array_fill_keys(self::TABS, 0), 'facets' => [], 'chips' => [], 'filterCount' => 0, 'failed' => true];
        }

        $dialog = $this->catOpen && $this->catTargets !== [] && isset($read['tree']) ? $this->categoryDialog($read['tree']) : null;

        return view('admin::livewire.catalog.index', [
            'rows' => $read['rows'],
            'selected' => array_keys($read['picked'] ?? []),
            'selectedStatus' => $read['picked'] ?? [],
            'may' => $canManage ? ['update' => Gate::allows('update', Product::class), 'delete' => Gate::allows('delete', Product::class),
                'restore' => Gate::allows('restore', Product::class), 'forceDelete' => Gate::allows('forceDelete', Product::class)] : [],
            'catDialog' => $dialog,
            'pager' => $read['pager'],
            'matchCount' => $read['total'],
            'counts' => $read['counts'],
            'facets' => $read['facets'],
            'filterChips' => $read['chips'],
            'filterCount' => $read['filterCount'],
            'filterCountNarrow' => $read['filterCount'] + ($this->tab !== 'all' ? 1 : 0),
            'moreF' => $this->moreF,
            'qF' => $this->qF,
            'catF' => $this->catF,
            'transF' => $this->transF,
            'tagF' => $this->tagF,
            'stockF' => $this->stockF,
            'saleF' => $this->saleF,
            'failed' => $read['failed'] ?? false,
            'cap' => $cap,
            'locale' => $locale,
            'canManage' => $canManage,
            'sortOn' => $sortOn,
            'sortIsDefault' => $sortOn === 'updated',
            'colOptions' => $selling ? self::COL_OPTIONS : array_values(array_diff(self::COL_OPTIONS, ['price', 'stock'])),
            'cardOptions' => count($locales) >= 2 ? self::CARD_OPTIONS : array_values(array_diff(self::CARD_OPTIONS, ['tr'])),
            'selling' => $selling,
            'locales' => $locales,
            'paths' => app(PathBuilder::class),
            'publicHost' => SeoFields::publicHost(),
            'tree' => $read['tree'] ?? null,
            'sorts' => $this->sortKeys(),
            'urls' => app(MediaUrlResolver::class),
            'siteTime' => app(SiteTime::class),
        ]);
    }

    /**
     * What the "Đổi chuyên mục chính" dialog shows: how many products, the name and the current main category
     * of a single one, how many are published (their address may move), and the categories to choose from.
     * Read only while the dialog is open.
     *
     * @return array{count: int, name: string|null, pick: string|null, published: int, moves: bool, choices: list<array{ulid: string, label: string, depth: int, count: int, path: string}>}
     */
    private function categoryDialog(ProductCategoryTree $tree): array
    {
        $products = Product::query()->whereIn('ulid', $this->catTargets)->orderBy('id')
            ->with(['translations:id,product_id,locale,name', 'taxonomies' => static function (BelongsToMany $primary): void {
                $primary->wherePivot('position', 0)->select(['taxonomies.id']);
            }])->get(['id', 'ulid', 'status']);
        $one = $products->count() === 1 ? $products->first() : null;
        $mainId = $one?->primaryCategory()?->id;

        return [
            'count' => $products->count(),
            'name' => $one !== null ? $this->loadedName($one) : null,
            'pick' => $mainId !== null ? $tree->ulidOf($mainId) : null,
            'published' => $products->where('status', 'published')->count(),
            'moves' => in_array(app(PermalinkConfig::class)->pattern('products', 'products'), ['category', 'category_path'], true),
            'choices' => $tree->choices(),
        ];
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

        // filters: only values this screen knows, once each; the categories are checked against the tree when it is read.
        // A site with one language has no translation filter, a brochure site no stock or sale filter.
        $this->catF = array_values(array_unique(array_filter($this->catF, 'is_string')));
        $this->transF = count(app(SiteLocale::class)->enabled()) >= 2 ? self::known($this->transF, ProductListFilter::TRANS) : [];
        $this->tagF = self::known($this->tagF, ProductListFilter::TAGS);
        $selling = ! $this->brochure();
        $this->stockF = $selling ? self::known($this->stockF, ProductListFilter::STOCK) : [];
        $this->saleF = $selling && in_array('on', $this->saleF, true) ? ['on'] : [];
        $this->moreF = array_filter(array_intersect_key($this->moreF, self::FILTERS), fn (mixed $open): bool => $open === true);
        $this->qF = array_map(fn (mixed $words): string => mb_substr(Cast::string($words), 0, 100), array_intersect_key($this->qF, self::FILTERS));
    }

    private function listFilter(ProductCategoryTree $tree): ProductListFilter
    {
        return new ProductListFilter($tree->idsUnder($this->catF), $this->transF, app(SiteLocale::class)->enabled(), $this->tagF, $this->stockF, $this->saleF !== []);
    }

    private function listQuery(ProductListFilter $filter): ProductListQuery
    {
        $cap = max(1, Cast::int(config('admin.catalog_list_cap', ProductListQuery::CAP)));

        return new ProductListQuery(app(CurrentSite::class)->id(), $this->tab, $this->search, app(SiteLocale::class)->default(), $cap, $filter);
    }

    /**
     * The ticked products that are still in what the screen shows (status group, filters and search):
     * the selection is cut down to them, and their status is returned (ulid => status, in ticked order).
     * The bar's counts and every bulk action read this same set.
     *
     * @return array<string, string>
     */
    private function pruneSelection(ProductListQuery $list): array
    {
        if ($this->selected === []) {
            return [];
        }
        $found = $list->filtered()->whereIn('products.ulid', $this->selected)->pluck('products.status', 'products.ulid')->all();
        $kept = [];
        foreach ($this->selected as $ulid) {
            if (isset($found[$ulid])) {
                $kept[$ulid] = Cast::string($found[$ulid]);
            }
        }
        $this->selected = array_keys($kept);

        return $kept;
    }

    /** The product categories, read once per request. */
    private function categoryTree(): ProductCategoryTree
    {
        return $this->tree ??= ProductCategoryTree::load(app(CurrentSite::class)->id(), app(SiteLocale::class)->default());
    }

    /**
     * A category ticked brings everything under it; one unticked takes everything under it and above it
     * (a parent cannot stay ticked with a child gone). Compares the choice with what it was.
     *
     * @param  list<string>  $before
     */
    private function settleCategories(array $before): void
    {
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

    /**
     * The values a plain filter group knows.
     *
     * @return list<string>
     */
    private function knownValues(string $group): array
    {
        return match ($group) {
            'trans' => ProductListFilter::TRANS,
            'tag' => ProductListFilter::TAGS,
            'stock' => ProductListFilter::STOCK,
            'sale' => ['on'],
            default => [],
        };
    }

    /**
     * Each filter's options with the general number beside it (not changed by the other filters or the
     * group), and which are on. A filter nothing can be chosen in is left out unless it is on.
     *
     * @param  array{tags: array<string, int>, stock: array<string, int>, trans: array<string, int>, sale: int}|null  $values  null: the website is past the cap, the values carry no number
     * @return array<string, list<array{value: string, label: string, count?: int|string, depth?: int, active: bool}>>
     */
    private function facets(ProductCategoryTree $tree, ?array $values, bool $multilingual, bool $selling, int $cap): array
    {
        $count = fn (int $n): int|string => $n > $cap ? Cast::string(__('catalog.count_over')) : $n;
        $number = fn (int $n): array => $values === null ? [] : ['count' => $count($n)];
        $facets = ['cat' => [], 'trans' => [], 'tag' => [], 'stock' => [], 'sale' => []];
        foreach ($tree->options() as $option) {
            $facets['cat'][] = ['value' => $option['ulid'], 'label' => $option['label'], 'count' => $count($option['count']), 'depth' => $option['depth'],
                'active' => in_array($option['ulid'], $this->catF, true)];
        }
        if ($multilingual) {
            foreach (ProductListFilter::TRANS as $state) {
                $facets['trans'][] = ['value' => $state, 'label' => __('entries.trans_'.$state), ...$number($values['trans'][$state] ?? 0), 'active' => in_array($state, $this->transF, true)];
            }
        }
        foreach (ProductListFilter::TAGS as $tag) {
            $facets['tag'][] = ['value' => $tag, 'label' => __('catalog.tag_'.$tag), ...$number($values['tags'][$tag] ?? 0), 'active' => in_array($tag, $this->tagF, true)];
        }
        if ($selling) {
            foreach (ProductListFilter::STOCK as $kind) {
                $facets['stock'][] = ['value' => $kind, 'label' => __('catalog.stock_'.$kind), ...$number($values['stock'][$kind] ?? 0), 'active' => in_array($kind, $this->stockF, true)];
            }
            // offered only while some product is on sale, or the filter is on already; past the cap there are no numbers
            // to tell (finding out would walk the list), so it is always offered
            if ($values === null || $values['sale'] > 0 || $this->saleF !== []) {
                $facets['sale'][] = ['value' => 'on', 'label' => __('catalog.sale_on'), ...$number($values['sale'] ?? 0), 'active' => $this->saleF !== []];
            }
        }

        return $facets;
    }

    /**
     * One chip per chosen value (a chosen category with its parent also chosen is covered by the parent's chip).
     * Each removes itself through toggleFilterValue / resetFilterGroup with fixed group names and a quoted value.
     *
     * @param  array<string, list<array{value: string, label: string, active: bool}>>  $facets
     * @return list<array{label: string, icon: string, aria: string, remove: string}>
     */
    private function filterChips(ProductCategoryTree $tree, array $facets): array
    {
        $chips = [];
        $make = function (string $group, string $nameKey, string $icon, string $ariaKey, string $value, string $name) use (&$chips): void {
            $chips[] = ['label' => __('catalog.chip_f', ['group' => __($nameKey), 'value' => $name]), 'icon' => $icon, 'aria' => __($ariaKey, ['name' => $name]),
                'remove' => 'toggleFilterValue('.Js::from($group).', '.Js::from($value).')'];
        };
        foreach ($tree->topmost($this->catF) as $category) {
            $make('cat', 'catalog.f_cat', 'ri-folder-3', 'catalog.chip_cat_aria', $category['ulid'], $category['label']);
        }
        foreach (['trans' => ['entries.f_trans', 'ri-translate-2', 'catalog.chip_trans_aria'], 'tag' => ['catalog.f_tag', 'ri-price-tag-3', 'catalog.chip_tag_aria'],
            'stock' => ['catalog.f_stock', 'ri-stack', 'catalog.chip_stock_aria']] as $group => [$nameKey, $icon, $ariaKey]) {
            foreach ($facets[$group] ?? [] as $option) {
                if ($option['active']) {
                    $make($group, $nameKey, $icon, $ariaKey, $option['value'], $option['label']);
                }
            }
        }
        if ($this->saleF !== []) {
            $chips[] = ['label' => __('catalog.chip_f', ['group' => __('catalog.f_sale'), 'value' => __('catalog.sale_on')]), 'icon' => 'ri-price-tag-2',
                'aria' => __('catalog.chip_sale_aria'), 'remove' => 'resetFilterGroup('.Js::from('sale').')'];
        }

        return $chips;
    }

    /** How many conditions are on (the Lọc badge): each chosen value, a chosen category counted by its chip. */
    private function filterCount(ProductCategoryTree $tree): int
    {
        return count($tree->topmost($this->catF)) + count($this->transF) + count($this->tagF) + count($this->stockF) + ($this->saleF !== [] ? 1 : 0);
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

    /** Names the view of the list a page cursor belongs to: the group, the search, the filters, the sort and the page size. */
    private function fingerprint(): string
    {
        return hash('xxh128', json_encode([$this->tab, trim($this->search), $this->sorting(), $this->perPage, $this->catF, $this->transF, $this->tagF, $this->stockF, $this->saleF], JSON_THROW_ON_ERROR));
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
            ...self::categoryRelations(app(PermalinkConfig::class)->pattern('products', 'products') === 'category_path'),
        ]);

        // only the first picture of each product (by position), not its whole gallery
        $firsts = ProductImage::query()
            ->selectRaw('distinct on (product_id) id, product_id, media_id, position, count(*) over (partition by product_id) as n')
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
            $product->setAttribute('images_n', $image?->getAttribute('n') ?? 0);
        }
    }

    /**
     * The main category (pivot position 0) with the names and slugs the address reads, one query each for the
     * whole page. The title of the category cell comes from the category tree already in memory. The chain
     * of parents is read only when the address is the full category path, and then with the columns it needs.
     *
     * @return array<int|string, string|Closure(Relation<*, *, *>): void>
     */
    private static function categoryRelations(bool $withChain): array
    {
        $names = ':id,taxonomy_id,locale,name,slug';
        $relations = ['taxonomies' => static function (Relation $primary) use ($withChain): void {
            if ($primary instanceof BelongsToMany) {
                $primary->wherePivot('position', 0)->select($withChain ? ['taxonomies.id', 'taxonomies.parent_id'] : ['taxonomies.id']);
            }
        }, 'taxonomies.translations'.$names];

        if ($withChain) {
            $chain = 'taxonomies';
            for ($level = 2; $level <= TaxonomyRules::MAX_DEPTH; $level++) {
                $chain .= '.parent';
                $relations[$chain] = static function (Relation $parent): void {
                    $parent->select(['taxonomies.id', 'taxonomies.parent_id']);
                };
                $relations[] = $chain.'.translations'.$names;
            }
        }

        return $relations;
    }

    /**
     * Keep this member's Cột / Hiện trên thẻ choice and their last filters (not the sale one) in their own preferences, nobody else's. One UPDATE
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
            [self::VIEW_KEY, json_encode(['cols' => $this->cols, 'card' => $this->card, 'cat' => $this->catF, 'tr' => $this->transF, 'tag' => $this->tagF, 'stock' => $this->stockF], JSON_THROW_ON_ERROR), $user->getKey()],
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

    /** Whether this site's plan includes the catalog (read once per request). */
    private function entitled(): bool
    {
        return in_array('catalog', app(Entitlement::class)->entitledModuleKeys(), true);
    }

    /**
     * A write from the browser: refused unless the plan has the catalog and the policy allows
     * $ability on products at all. Asked before any product is looked up, so the answer (403) is
     * the same for a product that exists and one that does not.
     */
    private function guardWrite(string $ability): void
    {
        abort_unless($this->entitled(), 403);
        Gate::authorize($ability, Product::class);
    }

    private function actingUser(): User
    {
        $user = Auth::user();
        assert($user instanceof User);

        return $user;
    }
}
