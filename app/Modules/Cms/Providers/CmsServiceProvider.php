<?php

namespace App\Modules\Cms\Providers;

use App\Modules\Cms\Components\EntryGridComponent;
use App\Modules\Cms\Components\LatestPostsComponent;
use App\Modules\Cms\Console\FlushEntryViews;
use App\Modules\Cms\Console\PruneEntryRevisions;
use App\Modules\Cms\Console\PublishDueEntries;
use App\Modules\Cms\Console\RebuildTaxonomyPaths;
use App\Modules\Cms\Console\SnapshotPublishedEntries;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Navigation\MenuTargets;
use App\Modules\Cms\Observers\EntryObserver;
use App\Modules\Cms\Policies\EntryPolicy;
use App\Modules\Cms\Routing\CategoryDependents;
use App\Modules\Cms\Routing\SectionResolver;
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
    #[\Override]
    public function register(): void
    {
        // SectionResolver memoizes per-site the section a post belongs to (derived from its primary
        // category); scoped so the memo is flushed between Octane operations and never leaks across
        // requests, yet is shared within a request so bulk route-sync doesn't re-resolve per entry.
        $this->app->scoped(SectionResolver::class);

        // Menu link kinds other modules register at boot (Catalog → `product`). Boot-time closures
        // only, no request state — shared across Octane requests like the ComponentRegistry.
        $this->app->singleton(MenuTargets::class);

        // Item kinds other modules file under categories (Catalog → `product`), re-routed when a
        // category changes. Boot-time closures only, no request state — same as MenuTargets.
        $this->app->singleton(CategoryDependents::class);
    }

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

        // Purging an entry drops its block image usage rows (polymorphic, no FK cascade).
        Entry::observe(EntryObserver::class);

        // Module commands aren't auto-discovered like app/Console/Commands.
        if ($this->app->runningInConsole()) {
            $this->commands([
                PublishDueEntries::class,
                FlushEntryViews::class,
                RebuildTaxonomyPaths::class,
                SnapshotPublishedEntries::class,
                PruneEntryRevisions::class,
            ]);
        }
    }
}
