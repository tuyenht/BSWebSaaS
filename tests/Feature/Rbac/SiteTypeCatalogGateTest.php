<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/*
 * T2 increment-2 — catalog-write protection, gate-first (design.md §15.2 / §2). site_types is a
 * global no-RLS table, so the app layer is the ONLY guard against cross-tenant catalog writes.
 * Ship the gate WITH the resource (proactive defense-in-depth): platform holders of
 * `platform.site_types.manage` (incl. super_admin via Gate::before) may manage it; tenants never.
 * The seed path writes raw DB inside the migration, so it never touches the gate.
 */

beforeEach(function () {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('locks site_types catalog writes behind a platform-only gate (gate-first)', function () {
    expect(PermissionRegistry::PLATFORM_PERMISSIONS)->toContain('platform.site_types.manage');

    // super_admin — short-circuits every gate (Gate::before).
    $super = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $super->assignRole('super_admin'));

    // tenant owner — full TENANT permissions, but NO platform authority.
    $owner = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($owner, 'owner', PermissionRegistry::TENANT_PERMISSIONS));

    // platform operator — granted exactly the catalog permission (not super_admin).
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(function () use ($operator) {
        Permission::findOrCreate('platform.site_types.manage', PermissionRegistry::GUARD);
        $role = Role::findOrCreate('catalog_operator', PermissionRegistry::GUARD);
        $role->forceFill(['level' => 60, 'is_system' => true])->save();
        $role->syncPermissions(['platform.site_types.manage']);
        $operator->assignRole($role);
    });

    expect(Gate::forUser($super->fresh())->allows('platform.site_types.manage'))->toBeTrue()
        ->and(app(Tenancy::class)->enter(1, fn () => Gate::forUser($owner->fresh())->allows('platform.site_types.manage')))->toBeFalse()
        ->and(Gate::forUser($operator->fresh())->allows('platform.site_types.manage'))->toBeTrue();
});

it('does not let the gate block the system seed — corporate survives the migration', function () {
    // The corporate row is seeded by raw DB inside the migration (privileged role), never invoking
    // the gate. Its presence proves system/seed writes are not blocked (no app-layer write exists in GĐ1).
    expect(DB::table('site_types')->where('key', 'corporate')->exists())->toBeTrue();
});
