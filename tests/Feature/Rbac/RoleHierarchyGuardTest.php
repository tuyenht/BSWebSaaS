<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Actions\AssignRole;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;

/*
 * Tầng-2 spine — the role.level grant≤self guard. Escalation invariant (design.md §15.1/§15.3, §2):
 * an actor may grant a role only if it is STRICTLY BELOW their own level AND its permissions are a
 * subset of theirs, within their own site team (= site_id). role.level was inert (stored, never
 * compared) — this is its first enforcement. Every user-facing grant routes through AssignRole.
 */

beforeEach(function () {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** Materialize a tenant role template (canonical level + perms) inside $site's team. */
function templateRole(int $site, string $name): Role
{
    $tpl = PermissionRegistry::tenantRoleTemplates('corporate')[$name];
    $perms = $tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions'];

    return app(Tenancy::class)->enter($site, function () use ($name, $tpl, $perms) {
        $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $tpl['level'], 'is_system' => true])->save();
        $role->syncPermissions($perms);

        return $role;
    });
}

/** A fresh user carrying the named role template in $site. */
function userWithRole(int $site, string $name): User
{
    $role = templateRole($site, $name);
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => $user->assignRole($role));

    return $user;
}

it('lets owner grant a strictly-lower role (editor)', function () {
    $owner = userWithRole(1, 'owner');
    $editor = templateRole(1, 'editor');

    $granted = app(Tenancy::class)->enter(1, function () use ($owner, $editor) {
        app(RoleHierarchyGuard::class)->assertCanGrant($owner->fresh(), $editor);

        return true;
    });

    expect($granted)->toBeTrue();
});

it('rejects granting a higher-level role (editor → admin = escalation)', function () {
    $editor = userWithRole(1, 'editor');
    $admin = templateRole(1, 'admin');

    app(Tenancy::class)->enter(1, fn () => app(RoleHierarchyGuard::class)->assertCanGrant($editor->fresh(), $admin));
})->throws(AuthorizationException::class);

it('rejects granting a same-level role (strict-lower: editor → editor)', function () {
    $editor = userWithRole(1, 'editor');
    $peer = templateRole(1, 'editor');

    app(Tenancy::class)->enter(1, fn () => app(RoleHierarchyGuard::class)->assertCanGrant($editor->fresh(), $peer));
})->throws(AuthorizationException::class);

it('rejects granting permissions the actor does not hold (perms-subset)', function () {
    $editor = userWithRole(1, 'editor'); // editor template lacks users.manage

    // A role LOWER in level (40 < 50) but carrying a permission the editor does not hold.
    $privileged = app(Tenancy::class)->enter(1, function () {
        $role = Role::findOrCreate('site_manager', PermissionRegistry::GUARD);
        $role->forceFill(['level' => 40, 'is_system' => true])->save();
        $role->syncPermissions(['users.manage']);

        return $role;
    });

    app(Tenancy::class)->enter(1, fn () => app(RoleHierarchyGuard::class)->assertCanGrant($editor->fresh(), $privileged));
})->throws(AuthorizationException::class);

it('exposes only strictly-lower, perms-subset roles via assignableRoles (visibility)', function () {
    $editor = userWithRole(1, 'editor');
    templateRole(1, 'admin');   // level 70 — above
    templateRole(1, 'owner');   // level 90 — above
    templateRole(1, 'viewer');  // level 10 — below, perms ⊆ editor

    $names = app(Tenancy::class)->enter(1, fn () => app(RoleHierarchyGuard::class)->assignableRoles($editor->fresh())->pluck('name'));

    expect($names)->toContain('viewer')
        ->not->toContain('admin')
        ->not->toContain('owner')
        ->not->toContain('editor'); // same level excluded
});

it('rejects granting a role that belongs to another site team', function () {
    $owner1 = userWithRole(1, 'owner');     // high level, but...
    $editorSite2 = templateRole(2, 'editor'); // ...this role lives in site 2

    app(Tenancy::class)->enter(1, fn () => app(RoleHierarchyGuard::class)->assertCanGrant($owner1->fresh(), $editorSite2));
})->throws(AuthorizationException::class);

it('lets a platform super_admin grant any role (bypass)', function () {
    $super = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $super->assignRole('super_admin'));
    $owner = templateRole(1, 'owner');

    $granted = app(Tenancy::class)->enter(1, function () use ($super, $owner) {
        app(RoleHierarchyGuard::class)->assertCanGrant($super->fresh(), $owner);

        return true;
    });

    expect($granted)->toBeTrue();
});

it('AssignRole grants the role through the guard and writes an audit trail', function () {
    $owner = userWithRole(1, 'owner');
    $target = User::factory()->create();
    $editor = templateRole(1, 'editor');

    app(Tenancy::class)->enter(1, fn () => app(AssignRole::class)->handle($owner->fresh(), $target, $editor));

    $assigned = app(Tenancy::class)->enter(1, fn () => $target->fresh()->hasRole('editor'));

    expect($assigned)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'rbac.role.assigned')->exists())->toBeTrue();
});
