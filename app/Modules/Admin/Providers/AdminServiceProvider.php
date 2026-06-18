<?php

namespace App\Modules\Admin\Providers;

use App\Modules\Admin\Livewire\Dashboard;
use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index as EntriesIndex;
use App\Modules\Admin\Livewire\Login;
use App\Modules\Admin\Navigation\AdminNav;
use App\Modules\Tenancy\Http\Middleware\SetCurrentSite;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Tenant admin shell (Livewire). Registers the admin view namespace + Livewire
 * components, supplies the dynamic nav to the layout, and re-homes Livewire's AJAX
 * update endpoint under the tenant middleware. The admin runs on the tenant's own
 * domain under /admin (P0.8 plane).
 */
final class AdminServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        View::addNamespace('admin', $this->app->basePath('app/Modules/Admin/resources/views'));

        // Admin UI primitives as anonymous Blade components: <x-admin::icon />, etc.
        Blade::anonymousComponentPath($this->app->basePath('app/Modules/Admin/resources/views/components'), 'admin');

        Livewire::component('admin.login', Login::class);
        Livewire::component('admin.dashboard', Dashboard::class);
        Livewire::component('admin.entries.index', EntriesIndex::class);
        Livewire::component('admin.entries.editor', Editor::class);

        // The dynamic menu (content_sections ∩ permissions) for every admin layout render.
        View::composer('admin::layouts.app', function (ViewContract $view): void {
            $view->with('navItems', app(AdminNav::class)->items(Auth::user()));
        });

        // Livewire's AJAX update endpoint must run inside the tenant context so component
        // interactions stay RLS-isolated (SetCurrentSite resolves the site by host —
        // the admin is on the tenant's own domain). Auth stays at the component/page
        // level: the guest login and the authed admin share this single route.
        Livewire::setUpdateRoute(fn (array $handle) => Route::post('/livewire/update', $handle)
            ->middleware(['web', SetCurrentSite::class, 'site.active']));
    }
}
