<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\RestoresSettingsSection;
use App\Modules\Admin\Livewire\Concerns\ShowsSettingsToast;
use App\Modules\Admin\Livewire\Concerns\TracksSavedState;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Theme\AdminChromeResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
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
 * whatever is set here. Above the fields, when the section was last saved and «Khôi phục mặc
 * định» (every axis back to the platform's default, with «Hoàn tác»).
 */
#[Layout('admin::layouts.app')]
final class AdminUiSettings extends Component
{
    use RestoresSettingsSection;
    use ShowsSettingsToast;
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

    public function save(): bool
    {
        $rules = [];
        foreach (AdminChromeResolver::siteFields() as $field => $allowed) {
            $rules[$field] = ['nullable', 'string', Rule::in(['', ...$allowed])];
        }
        $this->validate($rules);

        // Persist only the pinned axes; '' (inherit) drops the key entirely.
        $config = [];
        foreach (array_keys(AdminChromeResolver::siteFields()) as $field) {
            if ($this->{$field} !== '') {
                $config[$field] = $this->{$field};
            }
        }

        app(SettingsRepository::class)->put('admin_theme', 'config', $config);

        $this->saved = true;
        $this->rememberSaved();
        $this->repaintChrome();

        return true;
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
        $view = view('admin::livewire.admin-ui-settings', ['changes' => $this->unsavedCount(), 'stamp' => $this->stampText()]);
        $view->title(__('admin.admin_ui'));

        return $view;
    }

    /** @return array<string, string> */
    protected function trackedState(): array
    {
        $state = [];
        foreach (array_keys(AdminChromeResolver::siteFields()) as $field) {
            $state[$field] = Cast::string($this->{$field});
        }

        return $state;
    }

    protected function settingsSection(): string
    {
        return 'admin_theme';
    }

    /** After «Khôi phục mặc định» or «Hoàn tác»: the stored look, painted at once. */
    protected function reloadSaved(): void
    {
        $this->load();
        $this->resetErrorBag();
        $this->saved = false;
        $this->repaintChrome();
    }

    /** Let the shell repaint immediately for THIS admin too (their own override, if any, still wins inside the resolver). */
    private function repaintChrome(): void
    {
        $user = Auth::user();
        $this->dispatch(
            'admin-chrome-changed',
            attributes: app(AdminChromeResolver::class)->attributes($user instanceof User ? $user : null),
        );
    }

    private function load(): void
    {
        $config = app(SettingsRepository::class)->get('admin_theme') ?? [];

        foreach (array_keys(AdminChromeResolver::siteFields()) as $field) {
            $value = $config[$field] ?? null;
            $this->{$field} = is_string($value) && in_array($value, AdminChromeResolver::siteFields()[$field], true)
                ? $value
                : '';
        }
        $this->rememberSaved();
    }
}
