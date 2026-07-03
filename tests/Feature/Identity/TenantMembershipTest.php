<?php

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Str;

/*
 * P0.1 — global identity (D6). A user auto-stamps its external ulid, hides secrets,
 * and joins tenants through tenant_users (membership ≠ per-site authority).
 */

it('auto-generates a ulid and hides secret attributes', function (): void {
    $user = User::factory()->create();

    expect($user->ulid)->toBeString()->toHaveLength(26)
        ->and($user->toArray())->not->toHaveKeys([
            'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
        ]);
});

it('links a user to tenants through tenant_users', function (): void {
    $user = User::factory()->create();
    $tenant = Tenant::create(['ulid' => (string) Str::ulid(), 'name' => 'An Vinh']);

    $user->tenants()->attach($tenant);

    expect($user->tenants()->pluck('name')->all())->toBe(['An Vinh']);
});
