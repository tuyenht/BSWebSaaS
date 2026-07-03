<?php

use App\Modules\Tenancy\Http\Middleware\SetCurrentSite;
use App\Modules\Tenancy\Models\TenantProbe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * Gate #1 — defense layer #1 (HTTP). Proves the middleware resolves hostname -> site
 * through the real HTTP kernel, runs the request inside the tenant context (so scope
 * + RLS return only that site's data), and fail-closes with 404 for unknown hosts.
 */

beforeEach(function (): void {
    seedTenancy();
    DB::connection('pgsql_admin')->table('tenant_probes')->insert([
        ['site_id' => 1, 'value' => 'A', 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'value' => 'B', 'created_at' => now(), 'updated_at' => now()],
    ]);

    Route::middleware(SetCurrentSite::class)->get('/_probe', fn () => response()->json([
        'values' => TenantProbe::pluck('value')->all(),
    ]));
});

it('resolves the site from the request host and isolates data', function (): void {
    $this->get('http://a.test/_probe')->assertOk()->assertExactJson(['values' => ['A']]);
    $this->get('http://b.test/_probe')->assertOk()->assertExactJson(['values' => ['B']]);
});

it('fail-closes with 404 for an unknown host', function (): void {
    $this->get('http://unknown.test/_probe')->assertNotFound();
});
