<?php

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\TenantProbe;
use Tests\Fixtures\WriteProbeJob;

/*
 * Gate #1 — isolation must hold OUTSIDE HTTP too (design.md §5: every entry point).
 * A queued job carries its site_id and runs through the WithinSite middleware →
 * same Tenancy::enter primitive → same isolation. dispatchSync runs the job inline
 * through its middleware (independent of the configured queue driver).
 */

beforeEach(fn () => seedTenancy());

it('runs a queued job inside its own tenant context', function () {
    WriteProbeJob::dispatchSync(1, 'from-job-a');
    WriteProbeJob::dispatchSync(2, 'from-job-b');

    $tenancy = app(Tenancy::class);

    expect($tenancy->enter(1, fn () => TenantProbe::pluck('value')->all()))->toBe(['from-job-a'])
        ->and($tenancy->enter(2, fn () => TenantProbe::pluck('value')->all()))->toBe(['from-job-b']);
});
