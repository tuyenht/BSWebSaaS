<?php

namespace App\Modules\Admin\Livewire\Platform\Sites;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Models\Site;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Operator Site detail (platform plane, read-only): the site + its tenant, type and domains.
 * Addressed by ulid (D2). Gated by platform.sites.view, re-checked in boot(). Module-level
 * entitlement (site_modules) is RLS-scoped and surfaces with S3 (entitlement) — not shown here.
 */
#[Layout('admin::layouts.platform')]
final class Detail extends Component
{
    public Site $site;

    public function boot(): void
    {
        $user = auth()->user();
        $platform = app(PlatformAccess::class);

        abort_unless(
            $user instanceof User && ($platform->isSuperAdmin($user) || $platform->can($user, 'platform.sites.view')),
            403,
        );
    }

    public function mount(string $ulid): void
    {
        $this->site = Site::query()->where('ulid', $ulid)->firstOrFail();
    }

    public function render(): View
    {
        $this->site->loadMissing(['domains', 'tenant', 'siteType']);

        $view = view('admin::livewire.platform.sites.detail');
        $view->title($this->site->name);

        return $view;
    }
}
