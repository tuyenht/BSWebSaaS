<?php

namespace App\Modules\Cms\Providers;

use App\Modules\Cms\Components\LatestPostsComponent;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Policies\EntryPolicy;
use App\Modules\Theme\Components\ComponentRegistry;
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
        $this->app->make(ComponentRegistry::class)->register('latest_posts', new LatestPostsComponent);

        Gate::policy(Entry::class, EntryPolicy::class);
    }
}
