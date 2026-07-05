<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Policies\CatalogPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the catalog authorization policy (defense layer #2). Every catalog write surface
 * (admin CRUD, later commerce) authorizes through it.
 */
final class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Product::class, CatalogPolicy::class);
    }
}
