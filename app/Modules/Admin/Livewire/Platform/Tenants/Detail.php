<?php

namespace App\Modules\Admin\Livewire\Platform\Tenants;

use App\Modules\Admin\Support\Customers\CustomerHistory;
use App\Modules\Admin\Support\Customers\CustomerPermissions;
use App\Modules\Admin\Support\Customers\PlanChangePreview;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\HtmlString;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * One customer on the platform (design 03, detail): who owns it and its code, its plan and what
 * the plan gives, its websites, and its history (CustomerHistory, newest first). Addressed by ulid (D2 — never the raw bigint id, which is why the component keeps
 * only the ulid and reads the customer on each render). Gated by platform.tenants.view, re-checked
 * in boot() (livewire/update is auth-less). "Đổi gói" is the shared ChangePlanDialog.
 *
 * An unknown ulid answers 404 with the design's "not found" card inside the console.
 */
#[Layout('admin::layouts.platform')]
final class Detail extends Component
{
    #[Locked]
    public string $ulid = '';

    /**
     * The oldest history row shown, as CustomerHistory's encrypted cursor ('' = the first page).
     * Not locked: a cursor the browser altered no longer decrypts, and one of another customer is
     * refused (the cursor names its customer); either reads as the first page.
     */
    public string $historyUntil = '';

    public function boot(): void
    {
        abort_unless(app(CustomerPermissions::class)->canView(auth()->user()), 403);
    }

    public function mount(string $ulid): void
    {
        if (! Tenant::query()->where('ulid', $ulid)->exists()) {
            throw new HttpResponseException(response()->view('admin::layouts.platform', [
                'slot' => new HtmlString(view('admin::livewire.platform.tenants.missing')->render()),
                'title' => __('admin.customers_notfound_title'),
            ], 404));
        }
        $this->ulid = $ulid;
    }

    /** "Xem thay đổi cũ hơn": the next rows below the oldest one shown. */
    public function olderHistory(string $cursor): void
    {
        $tenant = Tenant::query()->where('ulid', $this->ulid)->firstOrFail(['id']);
        $this->historyUntil = app(CustomerHistory::class)->older($tenant->id, $cursor);
    }

    /** "Về thay đổi mới nhất": the first page again, after "older" went past the rows one render shows. */
    public function newestHistory(): void
    {
        $this->historyUntil = '';
    }

    /** A customer's plan changed in the dialog: the next render reads it again. */
    #[On('customer-plan-changed')]
    public function planChanged(): void {}

    public function render(): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $permissions = app(CustomerPermissions::class);

        $tenant = Tenant::query()->where('ulid', $this->ulid)->firstOrFail();
        $owner = $tenant->getAttribute('owner_user_id') === null ? null
            : User::query()->whereKey($tenant->getAttribute('owner_user_id'))->first(['id', 'name', 'email']);
        $plan = $tenant->plan_id === null ? null : Plan::query()->whereKey($tenant->plan_id)->first(['id', 'key', 'name', 'is_active']);
        $values = app(PlanChangePreview::class)->values([$plan?->id])[$plan === null ? '' : $plan->id];
        $sites = $tenant->sites()->with('domains:id,site_id,hostname,is_primary')->orderBy('id')->get(['id', 'ulid', 'tenant_id', 'name', 'status']);
        $reason = Cast::string(Cast::arr($tenant->settings)['suspension_reason'] ?? null);
        $history = app(CustomerHistory::class)->read($tenant->id, $this->historyUntil);

        $view = view('admin::livewire.platform.tenants.detail', [
            'tenant' => $tenant,
            'owner' => $owner,
            'plan' => $plan,
            'values' => $values,
            'sites' => $sites,
            'history' => $history['rows'],
            'historyOlder' => $history['more'] ? $history['oldest'] : null,
            'historyCut' => $history['cut'],
            'suspensionReason' => $tenant->status === 'suspended' ? trim($reason) : '',
            'siteTime' => app(SiteTime::class),
            'canChangePlan' => $permissions->canChangePlan($user),
            'canOpenSites' => $permissions->canOpenSites($user),
            'roleStrip' => $permissions->roleStrip($user),
            'lockedReason' => $permissions->lockedReason($user),
        ]);
        $view->title($tenant->name);

        return $view;
    }
}
