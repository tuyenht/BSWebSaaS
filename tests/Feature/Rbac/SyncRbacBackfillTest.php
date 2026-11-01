<?php

use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;

/*
 * rbac:sync back-fill + context hygiene. Site-type templates drift as the platform grows —
 * a module shipped AFTER a site was provisioned (e.g. catalog) adds permissions the already
 * materialized is_system roles never received. Sync must ADD the missing grants on every
 * deploy (never revoke, never touch custom roles) across BOTH historical role shapes:
 * per-site rows (site_id = N — the genesis/prod shape) and shared team-0 rows (created by
 * provisions that ran while sync's forced platform override was still leaking — the leak
 * itself is fixed here too: sync now releases the override in finally).
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
        tenantName: 'An Vinh', siteName: 'An Vinh', hostname: 'anvinh.platform.test',
        ownerEmail: 'owner@anvinh.test', ownerName: 'An Vinh Owner',
        ownerPassword: 'a-strong-password', locales: ['vi'], modules: ['cms', 'media'],
    ));
});

it('releases the forced platform team after sync, so provisioning creates PER-SITE roles', function (): void {
    // The finally-forget: without it, the beforeEach provision (which runs after rbac:sync in
    // the same process) would have created every tenant role on team 0 instead of the site.
    expect(app(TeamContext::class)->isForced())->toBeFalse()
        ->and(Role::query()->where('name', 'owner')->where('site_id', 1)->exists())->toBeTrue();
});

it('back-fills drifted PER-SITE template roles (the genesis/prod shape)', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    // Simulate a pre-catalog deployment: the site's system roles predate the module.
    Role::query()->where('site_id', 1)->where('name', 'owner')->firstOrFail()
        ->revokePermissionTo(['catalog.view', 'catalog.manage']);
    Role::query()->where('site_id', 1)->where('name', 'viewer')->firstOrFail()
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
        ->where('roles.site_id', 1)->where('roles.name', 'viewer')
        ->where('permissions.name', 'catalog.view')
        ->exists();
    expect($viewerHas)->toBeTrue();
});

it('back-fills the read-only domains permission on an existing site\'s editor and viewer roles', function (): void {
    // A site provisioned before `domains.view` existed: its editor and viewer roles lack it.
    foreach (['editor', 'viewer'] as $name) {
        Role::query()->where('site_id', 1)->where('name', $name)->firstOrFail()->revokePermissionTo('domains.view');
    }
    $editor = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => $editor->assignRole(Role::query()->where('site_id', 1)->where('name', 'editor')->firstOrFail()));
    $canView = fn (string $role): bool => DB::table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('roles.site_id', 1)->where('roles.name', $role)
        ->where('permissions.name', 'domains.view')
        ->exists();

    expect(app(Tenancy::class)->enter(1, fn (): bool => $editor->fresh()->can('domains.view')))->toBeFalse();

    $this->artisan('rbac:sync')->assertSuccessful();
    $this->artisan('rbac:sync')->assertSuccessful(); // re-runnable: the second run adds nothing

    expect($canView('editor'))->toBeTrue()
        ->and($canView('viewer'))->toBeTrue()
        ->and($canView('author'))->toBeFalse()
        ->and($canView('contributor'))->toBeFalse()
        ->and(app(Tenancy::class)->enter(1, fn (): bool => $editor->fresh()->can('domains.view')))->toBeTrue()
        ->and(app(Tenancy::class)->enter(1, fn (): bool => $editor->fresh()->can('domains.manage')))->toBeFalse();
});

it('back-fills drifted SHARED team-0 template roles (the legacy leak shape)', function (): void {
    // Reproduce an environment provisioned under the old leak: tenant roles live on team 0.
    DB::table('roles')->whereIn('name', ['owner', 'admin', 'editor', 'author', 'contributor', 'viewer'])
        ->update(['site_id' => 0]);
    Role::query()->where('site_id', 0)->where('name', 'owner')->firstOrFail()
        ->revokePermissionTo(['catalog.view', 'catalog.manage']);

    $this->artisan('rbac:sync')->assertSuccessful();

    $ownerHas = DB::table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('roles.site_id', 0)->where('roles.name', 'owner')
        ->where('permissions.name', 'catalog.manage')
        ->exists();
    expect($ownerHas)->toBeTrue();
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
