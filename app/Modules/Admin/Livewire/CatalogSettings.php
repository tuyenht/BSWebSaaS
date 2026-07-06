<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Catalog\Support\CatalogMode;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Per-site catalog mode (site_settings group `catalog`, key `mode`): brochure (show the
 * catalog, hide price & ordering — An Vinh's target state) vs commerce (phase-2 selling).
 * The storefront and admin both read the flag through CatalogMode, so the decision lives
 * in exactly one place. Writes are settings.manage-gated like every site-wide settings tab.
 */
#[Layout('admin::layouts.app')]
final class CatalogSettings extends Component
{
    public string $mode = CatalogMode::BROCHURE;

    public function boot(): void
    {
        // Defense-in-depth: the livewire/update endpoint bypasses route middleware, so
        // re-check the site-settings permission on every request (mount + every action).
        abort_unless(Gate::allows('settings.manage'), 403);
    }

    public function mount(): void
    {
        $this->mode = app(CatalogMode::class)->current();
    }

    public function save(): void
    {
        $this->validate([
            'mode' => ['required', Rule::in(CatalogMode::MODES)],
        ]);

        app(SettingsRepository::class)->put('catalog', 'mode', ['value' => $this->mode]);

        session()->flash('status', __('admin.saved'));
    }

    public function render(): View
    {
        $view = view('admin::livewire.catalog-settings');
        $view->title(__('admin.catalog_settings'));

        return $view;
    }
}
