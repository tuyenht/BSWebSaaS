<?php

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\TenantProbe;

/*
 * Gate #1 — application-layer isolation (BelongsToSite). Complements the DB-layer
 * RLS test: proves the global scope filters by site and stamps site_id on create.
 */

beforeEach(fn () => seedTenancy());

it('auto-stamps site_id on create and filters reads by current site', function (): void {
    $tenancy = app(Tenancy::class);

    $tenancy->enter(1, fn () => TenantProbe::create(['value' => 'a1']));
    $tenancy->enter(2, fn () => TenantProbe::create(['value' => 'b1']));

    $aValues = $tenancy->enter(1, fn () => TenantProbe::pluck('value')->all());
    $bValues = $tenancy->enter(2, fn () => TenantProbe::pluck('value')->all());

    expect($aValues)->toBe(['a1'])
        ->and($bValues)->toBe(['b1']);
});

it('is fail-closed at the app layer when no site is resolved', function (): void {
    app(Tenancy::class)->enter(1, fn () => TenantProbe::create(['value' => 'a1']));

    // No current site → global scope adds 1=0 (and RLS would return nothing anyway).
    expect(TenantProbe::count())->toBe(0);
});
