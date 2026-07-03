<?php

use App\Modules\Identity\Models\SocialIdentity;
use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;

/*
 * Global (D6) social-identity link: a user has many; (provider, sub) is unique so one provider
 * account maps to exactly one user; deleting the user cascades the links. (The OAuth flow that
 * populates this — connect + login sub-match — is creds-gated and covered by code review.)
 */

it('links a user to a provider identity and reads it back via the relation', function (): void {
    $user = User::factory()->create();
    $user->socialIdentities()->create(['provider' => 'google', 'sub' => 'sub-123', 'email' => 'u@example.com']);

    expect($user->socialIdentities()->count())->toBe(1)
        ->and($user->socialIdentities()->first()?->provider)->toBe('google');
});

it('rejects linking one provider identity to two accounts', function (): void {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $a->socialIdentities()->create(['provider' => 'google', 'sub' => 'shared-sub']);

    expect(fn () => $b->socialIdentities()->create(['provider' => 'google', 'sub' => 'shared-sub']))
        ->toThrow(QueryException::class);
});

it('cascades social identities when the user is deleted', function (): void {
    $user = User::factory()->create();
    $user->socialIdentities()->create(['provider' => 'google', 'sub' => 'sub-x']);

    $user->delete();

    expect(SocialIdentity::query()->where('sub', 'sub-x')->exists())->toBeFalse();
});
