<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Components\ProductGridComponent;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Catalog\Policies\CatalogPolicy;
use App\Modules\Catalog\Support\ProductRoutes;
use App\Modules\Cms\Navigation\MenuTargets;
use App\Modules\Cms\Routing\CategoryDependents;
use App\Modules\Shared\Support\RichTextSanitizer;
use App\Modules\Theme\Components\ComponentRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the catalog authorization policy (defense layer #2) and the rich-text sanitizer.
 * Every catalog write surface (admin CRUD, later commerce, the ETL) authorizes through the
 * policy and passes editor HTML through the sanitizer.
 */
final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stateless (config-only) — safe to share across Octane workers, and building
        // HTMLPurifier once per worker instead of per save is the point.
        $this->app->singleton(RichTextSanitizer::class);
    }

    public function boot(): void
    {
        Gate::policy(Product::class, CatalogPolicy::class);

        // Register the catalog's data-bound grid into the shared Section Library, so a page (e.g. the
        // An Vinh home) can compose a `product_grid` block alongside Cms's `entry_grid`.
        $this->app->make(ComponentRegistry::class)->register('product_grid', $this->app->make(ProductGridComponent::class));

        // Menus can link to a product (design.md §13). The morph alias keeps menu_items storing
        // 'product' instead of a class name; MenuTargets tells Cms's menu which product routes a
        // visitor can open (published, not trashed) and what an unlabelled item is called — so
        // the dependency stays Catalog → Cms, never the other way round.
        Relation::morphMap(['product' => Product::class]);

        $this->app->make(MenuTargets::class)->register(
            'product',
            'product_id',
            static fn (Builder $routes): Builder => $routes->whereIn(
                'product_id',
                Product::query()->select('products.id')->where('status', 'published'),
            ),
            static function (array $ids, string $locale): array {
                $titles = [];
                foreach (ProductTranslation::query()->whereIn('product_id', $ids)->where('locale', $locale)->get(['product_id', 'name']) as $row) {
                    $titles[$row->product_id] = $row->name;
                }

                return $titles;
            },
        );

        // A product's address can carry its primary category (the `products` permalink preset), so a
        // category renamed, moved or deleted in Cms must re-file and re-route products too. Cms calls
        // this in the same transaction; the closure resolves ProductRoutes per call (Octane-safe).
        $this->app->make(CategoryDependents::class)->register(
            'product',
            'product_taxonomies',
            'product_id',
            'products',
            /** @param list<int> $ids */
            static fn (array $ids): int => app(ProductRoutes::class)->resync($ids),
        );
    }
}
