<?php

namespace App\Modules\Admin\Livewire\Platform\Tenants;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Operator Tenant detail (platform plane, read-only): the tenant + the sites it owns. Addressed
 * by ulid (D2 — never the raw bigint id). Gated by platform.tenants.view, re-checked in boot()
 * (livewire/update is auth-less). Sites eager-load their domains (no N+1).
 */
#[Layout('admin::layouts.platform')]
final class Detail extends Component
{
    public Tenant $tenant;

    public function boot(): void
    {
        $user = auth()->user();
        $platform = app(PlatformAccess::class);

        abort_unless(
            $user instanceof User && ($platform->isSuperAdmin($user) || $platform->can($user, 'platform.tenants.view')),
            403,
        );
    }

    public function mount(string $ulid): void
    {
        $this->tenant = Tenant::query()->where('ulid', $ulid)->firstOrFail();
    }

    public function render(): View
    {
        $sites = $this->tenant->sites()->with('domains')->orderBy('id')->get();

        $view = view('admin::livewire.platform.tenants.detail', ['sites' => $sites]);
        $view->title($this->tenant->name);

        return $view;
    }
}
