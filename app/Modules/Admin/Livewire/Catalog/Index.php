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
 * Lists the site's catalog products (site-scoped by BelongsToSite + RLS). Read access is gated by
 * CatalogPolicy::viewAny; brochure hides price at the storefront, not in this admin list. Only the
 * lightweight translation columns are eager-loaded to keep the list at a flat, cheap query budget.
 * The create/edit links only render for users who pass CatalogPolicy::create (no dead-end 403s
 * for view-only roles).
 */
#[Layout('admin::layouts.app')]
final class Index extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('viewAny', Product::class);
    }

    public function render(): View
    {
        $products = Product::query()
            ->with('translations:id,product_id,locale,name,slug')
            ->orderByDesc('id')
            ->get();

        return view('admin::livewire.catalog.index', [
            'products' => $products,
            'locale' => app(SiteLocale::class)->default(),
            'canManage' => Gate::allows('create', Product::class),
        ]);
    }
}
