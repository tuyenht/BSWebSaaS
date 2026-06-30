<?php

namespace App\Modules\Admin\Livewire\Platform\Sites;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Models\Site;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Operator Site directory (platform plane, read-only). `sites`/`domains` are GLOBAL (D6 — no RLS)
 * so they are queried directly without tenant context. Gated by platform.sites.view; re-checked
 * in boot() (livewire/update bypasses route middleware). Eager-loads domain/tenant/site_type so
 * the list never N+1s.
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
            $user instanceof User && ($platform->isSuperAdmin($user) || $platform->can($user, 'platform.sites.view')),
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
        $query = Site::query()->with(['domains', 'tenant', 'siteType']);

        if ($this->search !== '') {
            $query->where('name', 'ilike', '%'.$this->search.'%');
        }

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        $sites = $query->orderBy('id')->paginate(20);

        $view = view('admin::livewire.platform.sites.index', ['sites' => $sites]);
        $view->title(__('admin.platform_sites'));

        return $view;
    }
}
