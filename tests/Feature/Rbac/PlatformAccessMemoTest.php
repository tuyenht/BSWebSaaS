<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Listeners\FlushRbacTeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\PlatformAccess;

/*
 * M2 — PlatformAccess::isSuperAdmin memoization (N+1 fix on Gate::before hot path).
 * Proves: (a) two calls within one "request" hit the DB only once; (b) forgetMemo()
 * resets the verdict so the next call re-queries; (c) FlushRbacTeamContext resets
 * the memo on request termination.
 */

beforeEach(function () {
    truncateRbac();

    // Ensure the super_admin role exists without running the full rbac:sync.
    app(PlatformAccess::class)->onPlatform(
        fn () => Role::findOrCreate('super_admin', PermissionRegistry::GUARD)
    );

    // Start each test with a clean singleton state.
    app(TeamContext::class)->forget();
    app(PlatformAccess::class)->forgetMemo();
});

it('memoizes isSuperAdmin: two consecutive calls for the same user hit the DB only once', function () {
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('super_admin'));
    $user = $user->fresh();

    $platform = app(PlatformAccess::class);

    $queries = countQueries(function () use ($platform, $user): void {
        $platform->isSuperAdmin($user);
        $platform->isSuperAdmin($user); // second call: memo hit, no DB query
    });

    expect($queries)->toBe(1);
});

it('without forgetMemo the memo prevents a second DB query', function () {
    $user = User::factory()->create();
    $platform = app(PlatformAccess::class);

    // Warm the memo (one DB query).
    $platform->isSuperAdmin($user);

    // Second call should be zero-query (served from memo).
    $queries = countQueries(fn () => $platform->isSuperAdmin($user));

    expect($queries)->toBe(0);
});

it('forgetMemo resets the cached verdict and the next call re-queries the DB', function () {
    $user = User::factory()->create();
    $platform = app(PlatformAccess::class);

    // Warm the memo (result: false — user has no roles).
    expect($platform->isSuperAdmin($user))->toBeFalse();

    // With memo warm, second call produces 0 queries.
    expect(countQueries(fn () => $platform->isSuperAdmin($user)))->toBe(0);

    // After forgetMemo, the singleton re-queries DB on the next call.
    $platform->forgetMemo();
    expect(countQueries(fn () => $platform->isSuperAdmin($user)))->toBe(1);
});

it('FlushRbacTeamContext listener resets the PlatformAccess memo', function () {
    $user = User::factory()->create();
    $platform = app(PlatformAccess::class);

    // Warm the memo.
    $platform->isSuperAdmin($user);
    expect(countQueries(fn () => $platform->isSuperAdmin($user)))->toBe(0); // memo warm

    // Simulate Octane RequestTerminated / TaskTerminated flush.
    app(FlushRbacTeamContext::class)->handle(new stdClass);

    // After the listener runs, isSuperAdmin must re-query the DB.
    expect(countQueries(fn () => $platform->isSuperAdmin($user)))->toBe(1);
});
