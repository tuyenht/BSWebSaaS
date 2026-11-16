<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\TracksSavedState;
use App\Modules\Admin\Support\ProductsTabSwitch;
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
 * The page also holds the switch for the phone menu's products tab (storefront.header,
 * key drawer_products), saved with the same Lưu button.
 */
#[Layout('admin::layouts.app')]
final class CatalogSettings extends Component
{
    use TracksSavedState;

    public string $mode = CatalogMode::BROCHURE;

    public bool $drawerProducts = true;

    public function boot(): void
    {
        // Defense-in-depth: the livewire/update endpoint bypasses route middleware, so
        // re-check the site-settings permission on every request (mount + every action).
        abort_unless(Gate::allows('settings.manage'), 403);
    }

    public function mount(): void
    {
        $this->load();
    }

    public function save(): void
    {
        $this->validate([
            'mode' => ['required', Rule::in(CatalogMode::MODES)],
            'drawerProducts' => ['required', 'boolean'],
        ]);

        app(SettingsRepository::class)->put('catalog', 'mode', ['value' => $this->mode]);
        // A site with no products tab to switch (no header setting) keeps its settings untouched.
        app(ProductsTabSwitch::class)->set($this->drawerProducts);
        $this->rememberSaved();

        session()->flash('status', __('admin.saved'));
    }

    /** Back to the saved choice (the save bar's "Bỏ thay đổi"). */
    public function resetChanges(): void
    {
        $this->load();
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $view = view('admin::livewire.catalog-settings', [
            'productsTabAvailable' => app(ProductsTabSwitch::class)->available(),
            'changes' => $this->unsavedCount(),
        ]);
        $view->title(__('admin.catalog_settings'));

        return $view;
    }

    /** @return array{mode: string, drawerProducts: bool} */
    protected function trackedState(): array
    {
        return ['mode' => $this->mode, 'drawerProducts' => $this->drawerProducts];
    }

    private function load(): void
    {
        $this->mode = app(CatalogMode::class)->current();
        $this->drawerProducts = app(ProductsTabSwitch::class)->isOn();
        $this->rememberSaved();
    }
}
