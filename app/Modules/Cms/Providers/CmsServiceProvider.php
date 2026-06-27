<?php

namespace App\Modules\Cms\Providers;

use App\Modules\Cms\Components\EntryGridComponent;
use App\Modules\Cms\Components\LatestPostsComponent;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Policies\EntryPolicy;
use App\Modules\Theme\Components\ComponentRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Cms's data-bound page components into the shared Section Library, so a
 * page can compose them alongside the theme's static components, and binds the
 * content policies (defense layer #2 — every entry action routes through EntryPolicy).
 */
final class CmsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Stable morph aliases for the menu_items.routable target (D3) → 'entry'/'taxonomy'
        // in storage instead of a FQCN. Non-enforcing: other polymorphic users (audit,
        // spatie) keep their default class-name behaviour rather than being forced into a map.
        Relation::morphMap([
            'entry' => Entry::class,
            'taxonomy' => Taxonomy::class,
        ]);

        $registry = $this->app->make(ComponentRegistry::class);
        $registry->register('latest_posts', new LatestPostsComponent);
        $registry->register('entry_grid', $this->app->make(EntryGridComponent::class));

        Gate::policy(Entry::class, EntryPolicy::class);
    }
}
