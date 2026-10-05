<?php

namespace App\Modules\Admin\Livewire\Platform\Audit;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\SiteTime;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Operator Audit viewer (platform plane, read-only). `audit_logs` is GLOBAL (D6 — no RLS; nullable
 * site_id for context) so operators get cross-site visibility directly. Gated by platform.audit.view,
 * re-checked in boot(). Newest first; filterable by action. Append-only log, so no detail/mutation.
 */
#[Layout('admin::layouts.platform')]
final class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $action = '';

    public function boot(): void
    {
        $user = auth()->user();
        $platform = app(PlatformAccess::class);

        abort_unless(
            $user instanceof User && ($platform->isSuperAdmin($user) || $platform->can($user, 'platform.audit.view')),
            403,
        );
    }

    public function updatingAction(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $query = AuditLog::query();

        if ($this->action !== '') {
            $query->where('action', 'ilike', '%'.$this->action.'%');
        }

        $entries = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(50);

        // This is the platform plane: no site is resolved here (route group carries no
        // SetCurrentSite middleware), so SiteTime's own fail-safe already gives the platform
        // default zone (GMT+7) instead of a site's zone — there is no single "right" site for a
        // cross-site log. Reusing that fallback keeps one time funnel instead of a second rule.
        $view = view('admin::livewire.platform.audit.index', [
            'entries' => $entries,
            'siteTime' => app(SiteTime::class),
        ]);
        $view->title(__('admin.platform_audit'));

        return $view;
    }
}
