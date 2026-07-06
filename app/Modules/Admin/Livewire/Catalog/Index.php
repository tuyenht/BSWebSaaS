<?php

namespace App\Modules\Admin\Livewire\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lists the site's catalog products (site-scoped by BelongsToSite + RLS) as a Velzon-style
 * data table — thumbnail, name+sku, category, VND price, localized status badge, updated
 * time — paginated and searchable by name/sku. A Trash view (?trash=1, D4b) lists
 * soft-deleted rows. Read access is gated by CatalogPolicy::viewAny; every mutation
 * (trash/restore/force-delete) re-authorizes through the policy because the livewire/update
 * endpoint bypasses route middleware. A trashed product keeps its sku/slug reserved until
 * permanently deleted. Everything the rows show is eager-loaded (flat query budget,
 * enforced by AdminListQueryBudgetTest).
 */
#[Layout('admin::layouts.app')]
final class Index extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    /** Trash view toggle (from ?trash=1) — lists soft-deleted products instead. */
    public bool $showTrash = false;

    /** Filters by translation name or sku (debounced in the view; ?q= survives navigation). */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Product::class);
        $this->showTrash = request()->boolean('trash');
    }

    public function updatedSearch(): void
    {
        $this->resetPage(); // a new term must not strand the user on a stale page number
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
        $query = Product::query()
            ->with([
                'translations:id,product_id,locale,name,slug',
                'images:id,product_id,media_id,position', // relation itself orders by position
                'images.media:id,storage_key,mime',
                'taxonomies.translations:id,taxonomy_id,locale,name,slug',
            ])
            ->orderByDesc('id');

        if ($this->showTrash) {
            $query->onlyTrashed();
        }

        $term = trim($this->search);
        if ($term !== '') {
            $query->where(fn ($q) => $q
                ->where('sku', 'ilike', "%{$term}%")
                ->orWhereHas('translations', fn ($t) => $t->where('name', 'ilike', "%{$term}%")));
        }

        // Pager links are plain hrefs (wire:navigate remounts the component), so the active
        // trash/search state must ride the URL or page 2 would silently drop the filter.
        $pageQuery = array_filter(['trash' => $this->showTrash ? '1' : null, 'q' => $term !== '' ? $term : null]);

        return view('admin::livewire.catalog.index', [
            'products' => $query->paginate(20)->appends($pageQuery),
            'locale' => app(SiteLocale::class)->default(),
            'canManage' => Gate::allows('create', Product::class),
            'trashCount' => Product::onlyTrashed()->count(),
            'urls' => app(MediaUrlResolver::class),
        ]);
    }
}
