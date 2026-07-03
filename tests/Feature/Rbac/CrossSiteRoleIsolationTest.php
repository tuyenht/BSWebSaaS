<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * Defense layer #2 (RBAC) — the role *listing/assignment* surface is site-scoped. RBAC tables
 * are isolated only by the spatie team key (= site_id) in-app, NOT by Postgres RLS (a documented
 * D6 exception), so a regressed team scope on a role QUERY would silently bleed roles across
 * sites and RLS would not catch it. PermissionScopingTest covers the can() gate; this covers the
 * scoped queries that drive the admin Users & Roles screen (assignableRoles + a member's role
 * set) — the weakest isolation link, so it gets its own regression guard.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('scopes the assignable-roles set to the actor\'s site', function (): void {
    $tenancy = app(Tenancy::class);
    $owner = User::factory()->create();

    // An owner-level actor on site 1; a distinct role exists only on site 2.
    $tenancy->enter(1, fn () => grantRole($owner, 'owner', ['users.manage']));
    $tenancy->enter(2, fn (): Spatie\Permission\Contracts\Role => Role::findOrCreate('b-secret', PermissionRegistry::GUARD));

    $assignable = $tenancy->enter(1, fn () => app(RoleHierarchyGuard::class)
        ->assignableRoles($owner->fresh())->pluck('name')->all());

    expect($assignable)->not->toContain('b-secret');
});

it('scopes a member\'s role set to the site (no cross-site role bleed)', function (): void {
    $tenancy = app(Tenancy::class);
    $user = User::factory()->create();

    $tenancy->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));
    $tenancy->enter(2, fn () => grantRole($user, 'viewer', ['entries.view']));

    $rolesOnA = $tenancy->enter(1, fn () => $user->fresh()->roles()->pluck('name')->all());

    expect($rolesOnA)->toBe(['editor']);
});
