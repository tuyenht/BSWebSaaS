<?php

use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Rbac\Models\Role;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;

/*
 * rbac:sync back-fill: site-type templates drift as the platform grows — a module shipped
 * AFTER a site was provisioned (e.g. catalog) adds permissions its is_system roles never
 * received. Sync must ADD the missing template grants on every deploy, never revoke, and
 * never touch custom (non-system) roles.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         platform_settings, site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();
    $this->artisan('rbac:sync')->assertSuccessful();

    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'An Vinh', siteName: 'An Vinh', hostname: 'anvinh.test',
        ownerEmail: 'owner@anvinh.test', ownerName: 'An Vinh Owner',
        ownerPassword: 'a-strong-password', locales: ['vi'], modules: ['cms', 'media'],
    ));
});

it('back-fills template permissions that shipped after the site was provisioned', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    // Simulate a pre-catalog deployment: the tenant-template roles (global, platform team 0 —
    // shared by every site until tier-2 inc-4) predate the module, so they miss the grants.
    Role::query()->where('site_id', 0)->where('name', 'owner')->firstOrFail()
        ->revokePermissionTo(['catalog.view', 'catalog.manage']);
    Role::query()->where('site_id', 0)->where('name', 'viewer')->firstOrFail()
        ->revokePermissionTo(['catalog.view']);

    $can = fn (): bool => app(Tenancy::class)->enter(1, fn (): bool => $owner->fresh()->can('catalog.view'));
    expect($can())->toBeFalse(); // drift reproduced — the owner lost the module

    $this->artisan('rbac:sync')->assertSuccessful();

    expect($can())->toBeTrue() // the deploy-time sync healed the drift
        ->and(app(Tenancy::class)->enter(1, fn (): bool => $owner->fresh()->can('catalog.manage')))->toBeTrue();

    // The viewer template got its read grant back too.
    $viewerHas = DB::table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('roles.site_id', 0)->where('roles.name', 'viewer')
        ->where('permissions.name', 'catalog.view')
        ->exists();
    expect($viewerHas)->toBeTrue();
});

it('never touches custom (non-system) tenant roles', function (): void {
    $member = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($member): void {
        grantRole($member, 'custom-marketing', ['entries.view']); // is_system = false
    });

    $this->artisan('rbac:sync')->assertSuccessful();

    $perms = DB::table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('roles.name', 'custom-marketing')
        ->pluck('permissions.name')->all();

    expect($perms)->toBe(['entries.view']); // untouched — no template grants injected
});
