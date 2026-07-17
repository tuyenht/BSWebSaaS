<?php

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\PlanFeature;
use App\Modules\Tenancy\Models\SiteFeature;
use Illuminate\Support\Facades\DB;

/*
 * Plan → feature entitlement (design.md §15.2, ABP-style). A site's features resolve from its
 * tenant's plan, overridable per-site, falling back to a fail-closed registry default. Shape only:
 * billing is deferred, so a plan grants features and carries no price.
 */

beforeEach(function (): void {
    seedTenancy();   // tenant 1 owns sites 1 (a.test) + 2 (b.test)
    // plans is GLOBAL — seedTenancy's site/tenant cascade never touches it, and the suite's
    // no-rollback DB would carry a plan across cases. DELETE (not TRUNCATE) so the plan_id
    // FK's nullOnDelete + plan_features cascade fire; site_features rides the sites cascade.
    DB::connection('pgsql_admin')->statement('delete from plans');
});

/** Give tenant 1 a plan granting the passed feature values, and return it. */
function planGranting(array $features): Plan
{
    $plan = Plan::query()->create(['key' => 'pro', 'name' => 'Pro']);
    foreach ($features as $key => $value) {
        PlanFeature::query()->create(['plan_id' => $plan->id, 'feature_key' => $key, 'value' => $value]);
    }
    DB::table('tenants')->where('id', 1)->update(['plan_id' => $plan->id]);

    return $plan;
}

it('falls back to the fail-closed registry default with no plan and no override', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $entitlement = app(Entitlement::class);

        // Registry defaults: editorial workflow OFF, sites.max = 1.
        expect($entitlement->featureEnabled('workflow.editorial'))->toBeFalse();
        expect($entitlement->featureValue('sites.max'))->toBe(1);
        // Unknown key is fail-closed too.
        expect($entitlement->featureEnabled('nope.nope'))->toBeFalse();
    });
});

it("grants a feature from the tenant's plan", function (): void {
    planGranting(['workflow.editorial' => true, 'sites.max' => 10]);

    app(Tenancy::class)->enter(1, function (): void {
        $entitlement = app(Entitlement::class);

        expect($entitlement->featureEnabled('workflow.editorial'))->toBeTrue();
        expect($entitlement->featureValue('sites.max'))->toBe(10);       // limit coerced to int
    });
});

it('lets a per-site override beat the plan — both raising and denying', function (): void {
    planGranting(['workflow.editorial' => true, 'sites.max' => 10]);

    app(Tenancy::class)->enter(1, function (): void {
        // Deny a feature the plan grants, and cap a limit below the plan.
        SiteFeature::query()->create(['feature_key' => 'workflow.editorial', 'value' => false]);
        SiteFeature::query()->create(['feature_key' => 'sites.max', 'value' => 3]);

        $entitlement = app(Entitlement::class);
        expect($entitlement->featureEnabled('workflow.editorial'))->toBeFalse();
        expect($entitlement->featureValue('sites.max'))->toBe(3);
    });
});

it('is fail-closed with no site context (defaults, never a leak)', function (): void {
    planGranting(['workflow.editorial' => true]);

    // No Tenancy::enter — the platform plane / a job with no site resolved.
    expect(app(Entitlement::class)->featureEnabled('workflow.editorial'))->toBeFalse();
});

it('never leaks one site override to another under the same tenant', function (): void {
    planGranting(['workflow.editorial' => true]);   // tenant-wide grant

    app(Tenancy::class)->enter(1, function (): void {
        SiteFeature::query()->create(['feature_key' => 'workflow.editorial', 'value' => false]);   // site 1 denies
    });

    // Site 2 (same tenant) sees only the plan grant — site 1's override is RLS-isolated.
    app(Tenancy::class)->enter(2, function (): void {
        expect(app(Entitlement::class)->featureEnabled('workflow.editorial'))->toBeTrue();
        expect(SiteFeature::query()->count())->toBe(0);   // cannot even see site 1's row
    });
});
