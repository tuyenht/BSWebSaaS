<?php

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\TenantProbe;
use Illuminate\Support\Facades\DB;

/*
 * Gate #1 — defense layer #4 (concurrency). A deterministic stand-in for the Octane
 * worker pool: interleaves site A/B contexts over the SAME pooled connection and
 * asserts no data bleeds; also asserts the singleton is reset and that the three
 * documented failure scenarios are caught (design.md §5).
 *
 * The real-Octane HTTP hammer is documented in tests/Concurrency/README.md; this
 * deterministic test is the reliable CI gate.
 */

beforeEach(fn () => seedTenancy());

afterEach(fn () => app(CurrentSite::class)->clear());

it('clears the current-site singleton after each enter (scenario #2: no A→B leak)', function (): void {
    $tenancy = app(Tenancy::class);
    $current = app(CurrentSite::class);

    $tenancy->enter(1, function () use ($current): void {
        expect($current->id())->toBe(1);
    });

    expect($current->hasId())->toBeFalse();
});

it('never leaks data across interleaved site contexts on one connection', function (): void {
    DB::connection('pgsql_admin')->table('tenant_probes')->insert([
        ['site_id' => 1, 'value' => 'A', 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'value' => 'B', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $tenancy = app(Tenancy::class);
    $seen = [];

    foreach ([1, 2, 1, 2, 1] as $site) {
        $seen[] = $tenancy->enter($site, fn () => TenantProbe::pluck('value')->all());
    }

    expect($seen)->toBe([['A'], ['B'], ['A'], ['B'], ['A']]);
});

it('throws when a tenant query runs with a site set but outside a transaction (scenario #1)', function (): void {
    app(CurrentSite::class)->setId(1);

    expect(fn () => TenantProbe::count())->toThrow(RuntimeException::class);
});
