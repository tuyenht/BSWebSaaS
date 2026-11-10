<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Actions\ChangeCustomerPlan;
use App\Modules\Tenancy\Actions\ReorderPlans;
use App\Modules\Tenancy\Actions\SavePlan;
use App\Modules\Tenancy\Actions\SetPlanFeatures;
use App\Modules\Tenancy\Exceptions\FeatureChangeRejectedException;
use App\Modules\Tenancy\Exceptions\PlanChangeRejectedException;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\PlanFeature;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Who may edit the plan catalog and move a customer between plans, and the trail each change
 * leaves (design.md §15.2) — the one entry point for the platform console. Platform staff holding
 * `platform.billing.manage` only: the catalog is global, so nothing in the database stops a write
 * from the wrong hands — this gate does.
 *
 * The rules of each change live in the Tenancy actions this calls; a refused change throws their
 * exception and leaves no trail.
 */
final readonly class PlatformPlans
{
    public function __construct(private PlatformAccess $platform) {}

    /**
     * @throws AuthorizationException
     * @throws PlanChangeRejectedException name_required
     */
    public function create(User $actor, string $name, bool $isActive = true): Plan
    {
        $this->authorize($actor);

        $plan = app(SavePlan::class)->handle(null, $name, $isActive);
        $this->audit($actor, 'tenancy.plan.created', ['plan' => $plan->key, 'name' => $plan->name, 'sold' => $plan->is_active]);

        return $plan;
    }

    /**
     * @throws AuthorizationException
     * @throws PlanChangeRejectedException name_required
     */
    public function update(User $actor, Plan $plan, string $name, bool $isActive): Plan
    {
        $this->authorize($actor);

        $plan = app(SavePlan::class)->handle($plan, $name, $isActive);
        $this->audit($actor, 'tenancy.plan.updated', ['plan' => $plan->key, 'name' => $plan->name, 'sold' => $plan->is_active]);

        return $plan;
    }

    /**
     * @param  array<string, bool|int>  $features  the whole table: a feature left out is no longer granted
     *
     * @throws AuthorizationException
     * @throws FeatureChangeRejectedException unknown_feature · wrong_kind
     */
    public function setFeatures(User $actor, Plan $plan, array $features): void
    {
        $this->authorize($actor);

        app(SetPlanFeatures::class)->handle($plan, $features);
        $this->audit($actor, 'tenancy.plan.features_changed', ['plan' => $plan->key, 'features' => $features]);
    }

    /**
     * @param  list<int>  $planIds  every plan id, first to last
     *
     * @throws AuthorizationException
     * @throws PlanChangeRejectedException not_all_plans
     */
    public function reorder(User $actor, array $planIds): void
    {
        $this->authorize($actor);

        app(ReorderPlans::class)->handle($planIds);
        $this->audit($actor, 'tenancy.plan.reordered', ['order' => $planIds]);
    }

    /**
     * A new plan granting what $plan grants. It is not on sale until someone decides so.
     *
     * @throws AuthorizationException
     * @throws PlanChangeRejectedException name_required
     */
    public function duplicate(User $actor, Plan $plan, string $name): Plan
    {
        $this->authorize($actor);

        $copy = DB::transaction(function () use ($plan, $name): Plan {
            $copy = app(SavePlan::class)->handle(null, $name, false);

            /** @var array<string, bool|int> $grants */
            $grants = PlanFeature::query()->where('plan_id', $plan->id)->pluck('value', 'feature_key')->all();
            app(SetPlanFeatures::class)->handle($copy, $grants);

            return $copy;
        });

        $this->audit($actor, 'tenancy.plan.duplicated', ['plan' => $copy->key, 'from' => $plan->key]);

        return $copy;
    }

    /**
     * Put a customer on a plan, or on none. The reason (at most 500 characters) goes on record, in
     * the customer's history and the audit trail: it is the only trace of why a customer's websites
     * gained or lost what they may do.
     *
     * @throws AuthorizationException
     * @throws PlanChangeRejectedException reason_required · plan_not_sold
     */
    public function changeCustomerPlan(User $actor, Tenant $customer, ?Plan $plan, string $reason): void
    {
        $this->authorize($actor);

        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new PlanChangeRejectedException('reason_required');
        }

        $was = app(ChangeCustomerPlan::class)->handle($customer, $plan, $reason, Cast::int($actor->getKey()));
        if ($was === $plan?->id) {
            return;
        }

        $this->audit($actor, 'tenancy.customer.plan_changed', array_filter([
            'customer' => $customer->ulid,
            'from' => $was === null ? null : Plan::query()->whereKey($was)->value('key'),
            'to' => $plan?->key,
            'reason' => $reason,
        ], fn (mixed $v): bool => $v !== null));
    }

    /**
     * Switch the upgrade invitation on or off for the whole platform (see UpgradeInvitation).
     *
     * @throws AuthorizationException
     */
    public function setUpgradeInvitation(User $actor, bool $on): void
    {
        $this->authorize($actor);

        $switch = app(UpgradeInvitation::class);
        if ($switch->isOn() === $on) {
            return;
        }

        $switch->set($on);
        $this->audit($actor, 'tenancy.plan.upgrade_invitation_changed', ['on' => $on]);
    }

    /** @throws AuthorizationException */
    private function authorize(User $actor): void
    {
        if (! $this->platform->isSuperAdmin($actor) && ! $this->platform->can($actor, 'platform.billing.manage')) {
            throw new AuthorizationException;
        }
    }

    /** @param  array<string, mixed>  $meta */
    private function audit(User $actor, string $action, array $meta): void
    {
        Audit::record($action, [
            'actor_id' => Cast::int($actor->getKey()),
            'actor_label' => $actor->email,
            'meta' => $meta,
        ]);
    }
}
