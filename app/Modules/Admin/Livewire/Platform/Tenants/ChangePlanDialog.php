<?php

namespace App\Modules\Admin\Livewire\Platform\Tenants;

use App\Modules\Admin\Support\Customers\CustomerPermissions;
use App\Modules\Admin\Support\Customers\PlanChangePreview;
use App\Modules\Admin\Support\PlatformPlans;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Exceptions\PlanChangeRejectedException;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Đổi gói" (design 03): move one customer to another plan, with the reason on record. One
 * component on both customer pages, opened from a list row's ⋮ or the detail's button (the page
 * dispatches `open-change-plan` with the customer's ulid; the dialog listens on the window).
 *
 * Every action re-checks `platform.billing.manage` and the change itself goes through
 * PlatformPlans::changeCustomerPlan (which checks it again, writes the plan with its history row,
 * then the audit entry with the reason). Only plans on sale, other than the current one, can be
 * chosen, and save() checks that again. A plan taken off sale meanwhile comes back as
 * `plan_not_sold`: the dialog says so and keeps the reason.
 *
 * What the customer's websites already use is read once, when the first plan is chosen, and kept
 * for the dialog's later renders (it does not depend on the plan): the per-website reads never
 * repeat while the member types the reason or tries another plan.
 */
final class ChangePlanDialog extends Component
{
    public const int REASON_MAX = 500;

    #[Locked]
    public string $customer = '';

    public bool $open = false;

    /** The chosen plan's key ('' = none chosen yet). */
    public string $to = '';

    public string $reason = '';

    /** A save was tried: the missing fields say so. */
    public bool $tried = false;

    /** Why the last save failed: '' · not_sold · same · failed. */
    #[Locked]
    public string $failure = '';

    /** The success message shown after the dialog closes ('' = none). */
    #[Locked]
    public string $toast = '';

    /**
     * What each live website already uses (PlanChangePreview::usage), once read; null = not yet.
     *
     * @var list<array{site: string, own: list<string>, domains: int, locales: int}>|null
     */
    #[Locked]
    public ?array $usage = null;

    public function boot(): void
    {
        abort_unless(app(CustomerPermissions::class)->canView(auth()->user()), 403);
    }

    public function openFor(string $ulid): void
    {
        $this->authorizeChange();
        $tenant = Str::isUlid($ulid) ? Tenant::query()->where('ulid', $ulid)->first(['id', 'ulid']) : null;
        if ($tenant === null) {
            return;
        }

        $this->customer = $tenant->ulid;
        $this->to = $this->reason = $this->failure = $this->toast = '';
        $this->tried = false;
        $this->usage = null;
        $this->open = true;
    }

    public function choose(string $key): void
    {
        $this->authorizeChange();
        $tenant = $this->tenant();
        $plan = Plan::query()->where('key', $key)->where('is_active', true)->first(['id', 'key']);
        if ($tenant === null || $plan === null || $plan->id === $tenant->plan_id) {
            return;
        }
        $this->to = $plan->key;
        $this->failure = '';
    }

    public function save(): void
    {
        $this->authorizeChange();
        $tenant = $this->tenant();
        if ($tenant === null) {
            $this->open = false;

            return;
        }

        $this->reason = mb_substr($this->reason, 0, self::REASON_MAX);
        $plan = $this->to === '' ? null : Plan::query()->where('key', $this->to)->first();
        if ($plan === null || trim($this->reason) === '') {
            $this->tried = true;

            return;
        }
        if ($plan->id === $tenant->plan_id) {
            // what choose() refuses, sent straight from the browser: nothing would change
            $this->failure = 'same';
            $this->to = '';

            return;
        }

        try {
            app(PlatformPlans::class)->changeCustomerPlan($this->member(), $tenant, $plan, $this->reason);
        } catch (AuthorizationException) {
            abort(403);
        } catch (PlanChangeRejectedException $e) {
            if ($e->reason === 'plan_not_sold') {
                $this->failure = 'not_sold';
                $this->to = '';
            } else {
                $this->tried = true;
            }

            return;
        } catch (QueryException $e) {
            // the plan and its history row are written together or not at all (the audit entry comes
            // after, best-effort): nothing changed, so say so and keep the reason
            report($e);
            $this->failure = 'failed';

            return;
        }

        $sites = $tenant->sites()->count();
        $this->toast = __('admin.customers_changed_toast', ['plan' => $plan->name, 'name' => $tenant->name, 'n' => $sites]);
        $this->open = false;
        $this->to = $this->reason = '';
        $this->tried = false;
        $this->usage = null;
        $this->dispatch('customer-plan-changed');
    }

    public function dismissToast(): void
    {
        $this->toast = '';
    }

    public function render(): View
    {
        $tenant = $this->open ? $this->tenant() : null;
        $data = ['tenant' => $tenant, 'current' => null, 'plans' => collect(), 'chosen' => null, 'diff' => [], 'overages' => [], 'liveSites' => collect()];

        if ($tenant !== null) {
            $preview = app(PlanChangePreview::class);
            $plans = Plan::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'key', 'name']);
            $current = $tenant->plan_id === null ? null : Plan::query()->whereKey($tenant->plan_id)->first(['id', 'key', 'name']);
            $chosen = $this->to === '' ? null : $plans->firstWhere('key', $this->to);
            $liveSites = $tenant->sites()->orderBy('id')->get(['id', 'name']);
            $data = ['tenant' => $tenant, 'current' => $current, 'plans' => $plans, 'chosen' => $chosen, 'liveSites' => $liveSites, 'diff' => [], 'overages' => []];
            if ($chosen instanceof Plan) {
                $values = $preview->values([$current?->id, $chosen->id]);
                $data['diff'] = $preview->diff($values[$current === null ? '' : $current->id], $values[$chosen->id]);
                $this->usage ??= $preview->usage($liveSites);
                $data['overages'] = $preview->overages($liveSites->count(), $this->usage, $values[$chosen->id]);
            }
        }

        return view('admin::livewire.platform.tenants.change-plan-dialog', $data);
    }

    private function tenant(): ?Tenant
    {
        return $this->customer === '' ? null : Tenant::query()->where('ulid', $this->customer)->first();
    }

    private function member(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function authorizeChange(): void
    {
        abort_unless(app(CustomerPermissions::class)->canChangePlan($this->member()), 403);
    }
}
