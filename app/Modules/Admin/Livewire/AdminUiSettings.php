<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\TracksSavedState;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Theme\AdminChromeResolver;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Site-default look of the admin panel (Settings → Giao diện trang quản trị): the per-site tier of the admin theme,
 * stored as site_settings group `admin_theme` (NEVER the public `theme` group). Each axis
 * may also stay '' = inherit the app default — only explicitly chosen axes are persisted,
 * matching the resolver's per-field merge. Members' own Customizer picks still win over
 * whatever is set here.
 */
#[Layout('admin::layouts.app')]
final class AdminUiSettings extends Component
{
    use TracksSavedState;

    public string $scheme = '';

    public string $sidebar = '';

    public string $topbar = '';

    public string $position = '';

    public string $layout = '';

    public string $layout_width = '';

    public string $sidebar_size = '';

    public string $sidebar_image = '';

    public string $text_size = '';

    public string $motion = '';

    public bool $saved = false;

    public function boot(): void
    {
        // Defense-in-depth: the livewire/update endpoint bypasses route middleware, so
        // re-check the baseline site-settings permission on every request.
        abort_unless(Gate::allows('settings.manage'), 403);
    }

    public function mount(): void
    {
        $this->load();
    }

    public function save(): void
    {
        $rules = [];
        foreach (AdminChromeResolver::FIELDS as $field => $allowed) {
            $rules[$field] = ['nullable', 'string', Rule::in(['', ...$allowed])];
        }
        $this->validate($rules);

        // Persist only the pinned axes; '' (inherit) drops the key entirely.
        $config = [];
        foreach (array_keys(AdminChromeResolver::FIELDS) as $field) {
            if ($this->{$field} !== '') {
                $config[$field] = $this->{$field};
            }
        }

        app(SettingsRepository::class)->put('admin_theme', 'config', $config);

        $this->saved = true;
        $this->rememberSaved();

        // Let the shell repaint immediately for THIS admin too (their own override, if
        // any, still wins inside the resolver).
        $user = Auth::user();
        $this->dispatch(
            'admin-chrome-changed',
            attributes: app(AdminChromeResolver::class)->attributes($user instanceof User ? $user : null),
        );
    }

    /** Back to the saved look (the save bar's "Bỏ thay đổi"). */
    public function resetChanges(): void
    {
        $this->load();
        $this->saved = false;
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $view = view('admin::livewire.admin-ui-settings', ['changes' => $this->unsavedCount()]);
        $view->title(__('admin.admin_ui'));

        return $view;
    }

    /** @return array<string, string> */
    protected function trackedState(): array
    {
        $state = [];
        foreach (array_keys(AdminChromeResolver::FIELDS) as $field) {
            $state[$field] = (string) $this->{$field};
        }

        return $state;
    }

    private function load(): void
    {
        $config = app(SettingsRepository::class)->get('admin_theme') ?? [];

        foreach (array_keys(AdminChromeResolver::FIELDS) as $field) {
            $value = $config[$field] ?? null;
            $this->{$field} = is_string($value) && in_array($value, AdminChromeResolver::FIELDS[$field], true)
                ? $value
                : '';
        }
        $this->rememberSaved();
    }
}
