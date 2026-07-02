<?php

namespace App\Modules\Admin\Livewire;

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
 * Site-default appearance (Settings → Giao diện): the per-site tier of the admin theme,
 * stored as site_settings group `admin_theme` (NEVER the public `theme` group). Each axis
 * may also stay '' = inherit the app default — only explicitly chosen axes are persisted,
 * matching the resolver's per-field merge. Members' own Customizer picks still win over
 * whatever is set here.
 */
#[Layout('admin::layouts.app')]
final class AppearanceSettings extends Component
{
    public string $scheme = '';

    public string $sidebar = '';

    public string $topbar = '';

    public string $position = '';

    public bool $saved = false;

    public function boot(): void
    {
        // Defense-in-depth: the livewire/update endpoint bypasses route middleware, so
        // re-check the baseline site-settings permission on every request.
        abort_unless(Gate::allows('settings.manage'), 403);
    }

    public function mount(): void
    {
        $config = app(SettingsRepository::class)->get('admin_theme') ?? [];

        foreach (array_keys(AdminChromeResolver::FIELDS) as $field) {
            $value = $config[$field] ?? null;
            $this->{$field} = is_string($value) && in_array($value, AdminChromeResolver::FIELDS[$field], true)
                ? $value
                : '';
        }
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

        // Let the shell repaint immediately for THIS admin too (their own override, if
        // any, still wins inside the resolver).
        $user = Auth::user();
        $this->dispatch(
            'admin-chrome-changed',
            attributes: app(AdminChromeResolver::class)->attributes($user instanceof User ? $user : null),
        );
    }

    public function render(): View
    {
        $view = view('admin::livewire.appearance-settings', [
            'axes' => AdminChromeResolver::FIELDS,
        ]);
        $view->title(__('admin.appearance'));

        return $view;
    }
}
