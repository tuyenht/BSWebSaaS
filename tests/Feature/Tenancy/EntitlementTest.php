<?php

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Http\Middleware\SetCurrentSite;
use App\Modules\Tenancy\Models\SiteModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * P0.5 — defense layer #1 (entitlement), enforced BEFORE RBAC + RLS. A site only
 * reaches a module it is entitled to, and only while its lifecycle is 'active'.
 */

beforeEach(fn () => seedTenancy());

it('reports a module enabled only for the site that has it', function () {
    $tenancy = app(Tenancy::class);
    $tenancy->enter(1, fn () => SiteModule::create(['module_key' => 'cms', 'enabled' => true]));

    expect($tenancy->enter(1, fn () => app(Entitlement::class)->moduleEnabled('cms')))->toBeTrue()
        ->and($tenancy->enter(1, fn () => app(Entitlement::class)->moduleEnabled('commerce')))->toBeFalse()
        ->and($tenancy->enter(2, fn () => app(Entitlement::class)->moduleEnabled('cms')))->toBeFalse();
});

it('blocks a request into a module the site is not entitled to', function () {
    app(Tenancy::class)->enter(1, fn () => SiteModule::create(['module_key' => 'cms', 'enabled' => true]));

    Route::middleware([SetCurrentSite::class, 'site.active', 'module:cms'])->get('/_cms', fn () => 'ok');

    $this->get('http://a.test/_cms')->assertOk();        // site 1 is entitled
    $this->get('http://b.test/_cms')->assertForbidden(); // site 2 is not
});

it('blocks a request to a non-active site', function () {
    DB::connection('pgsql_admin')->table('sites')->where('id', 2)->update(['status' => 'suspended']);

    Route::middleware([SetCurrentSite::class, 'site.active'])->get('/_active', fn () => 'ok');

    $this->get('http://a.test/_active')->assertOk();        // active
    $this->get('http://b.test/_active')->assertForbidden(); // suspended
});
