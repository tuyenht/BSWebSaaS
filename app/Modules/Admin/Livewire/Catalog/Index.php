<?php

namespace App\Modules\Admin\Livewire\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Lists the site's catalog products (site-scoped by BelongsToSite + RLS) with a Trash view
 * (?trash=1 — soft-deleted rows, D4b). Read access is gated by CatalogPolicy::viewAny; every
 * mutation (trash/restore/force-delete) re-authorizes through the policy because the
 * livewire/update endpoint bypasses route middleware. A trashed product keeps its sku/slug
 * reserved until permanently deleted — force-delete cascades translations/images and frees
 * them. Only the lightweight translation columns are eager-loaded (flat query budget); the
 * create/edit links only render for users who pass CatalogPolicy::create (no dead-end 403s).
 */
#[Layout('admin::layouts.app')]
final class Index extends Component
{
    use AuthorizesRequests;

    /** Trash view toggle (from ?trash=1) — lists soft-deleted products instead. */
    public bool $showTrash = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Product::class);
        $this->showTrash = request()->boolean('trash');
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
            ->with('translations:id,product_id,locale,name,slug')
            ->orderByDesc('id');

        if ($this->showTrash) {
            $query->onlyTrashed();
        }

        return view('admin::livewire.catalog.index', [
            'products' => $query->get(),
            'locale' => app(SiteLocale::class)->default(),
            'canManage' => Gate::allows('create', Product::class),
            'trashCount' => Product::onlyTrashed()->count(),
        ]);
    }
}
