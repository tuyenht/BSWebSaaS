<?php

namespace App\Modules\Admin\Livewire\Platform\Tenants;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Operator Tenant list (platform plane, read-only). `tenants` is GLOBAL (D6 — no RLS) so it is
 * queried directly without tenant context. Gated by platform.tenants.view; super_admin passes
 * via isSuperAdmin (independent of perm materialization). Re-checked in boot() because the
 * livewire/update endpoint bypasses route middleware (EnsurePlatformAccess only guards the GET).
 * Site count uses withCount (no N+1).
 */
#[Layout('admin::layouts.platform')]
final class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    public function boot(): void
    {
        $user = auth()->user();
        $platform = app(PlatformAccess::class);

        abort_unless(
            $user instanceof User && ($platform->isSuperAdmin($user) || $platform->can($user, 'platform.tenants.view')),
            403,
        );
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $query = Tenant::query()->withCount('sites');

        if ($this->search !== '') {
            $query->where('name', 'ilike', '%'.$this->search.'%');
        }

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        $tenants = $query->orderBy('id')->paginate(20);

        $view = view('admin::livewire.platform.tenants.index', ['tenants' => $tenants]);
        $view->title(__('admin.platform_tenants'));

        return $view;
    }
}
