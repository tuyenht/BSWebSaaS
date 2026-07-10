<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Components\ProductGridComponent;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Policies\CatalogPolicy;
use App\Modules\Shared\Support\RichTextSanitizer;
use App\Modules\Theme\Components\ComponentRegistry;
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
    }
}
