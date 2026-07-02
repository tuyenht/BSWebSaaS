<?php

use App\Modules\Identity\Models\User;
use Illuminate\Support\Str;

/*
 * Per-user UI preferences on the global users table: a fresh account reads an empty
 * array, arbitrary nested values round-trip through the jsonb cast, and — the security
 * invariant — `preferences` is excluded from mass assignment so request data can never
 * inject values that later render into admin-chrome class/data-* attributes. Writes must
 * go through an explicit setter, never a request-data spread.
 */

it('defaults preferences to an empty array', function () {
    $user = User::factory()->create();

    expect($user->refresh()->preferences)->toBe([]);
});

it('round-trips nested preference values through the jsonb cast', function () {
    $user = User::factory()->create();

    $user->preferences = ['theme' => ['scheme' => 'dark', 'sidebar' => 'compact']];
    $user->save();

    // toEqual (order-insensitive): jsonb does not preserve key insertion order.
    expect($user->refresh()->preferences)
        ->toEqual(['theme' => ['scheme' => 'dark', 'sidebar' => 'compact']]);
});

it('guards preferences against mass assignment while other fields still fill', function () {
    // Unique email per run — the suite has no per-test rollback (shared dev DB), so a
    // hardcoded address would collide across runs.
    $user = User::create([
        'name' => 'Mass Assign Probe',
        'email' => 'probe-'.(string) Str::ulid().'@example.test',
        'password' => 'irrelevant-plaintext',
        'preferences' => ['injected' => 'nope'],
    ]);

    $user->refresh();

    // preferences is in $guarded → the injected value is dropped, DB default '{}' applies;
    // a non-guarded field (name) is still mass-assigned normally.
    expect($user->preferences)->toBe([])
        ->and($user->name)->toBe('Mass Assign Probe');
});
