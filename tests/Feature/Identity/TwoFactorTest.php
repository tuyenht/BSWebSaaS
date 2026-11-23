<?php

use App\Modules\Identity\Models\User;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/*
 * P0.2 — TOTP two-factor (design.md §29). Secret + recovery codes encrypted at rest;
 * enable requires proving possession of the secret; recovery codes are single-use.
 */

it('verifies a valid TOTP code and rejects an invalid one', function (): void {
    $service = app(TwoFactorService::class);
    $user = User::factory()->create();
    $secret = $service->generateSecret();
    $service->enable($user, $secret, totpCode($secret, -1));
    $wrong = collect(['000000', '111111', '222222', '333333'])->first(fn (string $c): bool => ! totpFits($secret, $c));

    expect($wrong)->toBeString()
        ->and($service->verifyOnce($user->refresh(), (string) $wrong))->toBeFalse()
        ->and($service->verifyOnce($user, totpCode($secret)))->toBeTrue();
});

it('enables 2FA and stores the secret + recovery codes encrypted at rest', function (): void {
    $service = app(TwoFactorService::class);
    $user = User::factory()->create();
    $secret = $service->generateSecret();

    expect($service->enable($user, $secret, (new Google2FA)->getCurrentOtp($secret)))->toBeTrue();

    $user->refresh();
    expect($user->two_factor_confirmed_at)->not->toBeNull()
        ->and($user->two_factor_secret)->toBe($secret)               // decrypted via cast
        ->and($user->two_factor_recovery_codes)->toHaveCount(8);

    // Raw column is ciphertext, not the plaintext secret.
    $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
    expect($raw)->toBeString()->not->toBe($secret);
});

it('refuses to enable 2FA with a bad code', function (): void {
    $service = app(TwoFactorService::class);
    $user = User::factory()->create();

    expect($service->enable($user, $service->generateSecret(), '000000'))->toBeFalse()
        ->and($user->refresh()->two_factor_confirmed_at)->toBeNull();
});

it('consumes a recovery code exactly once', function (): void {
    $service = app(TwoFactorService::class);
    $user = User::factory()->create();
    $secret = $service->generateSecret();
    $service->enable($user, $secret, (new Google2FA)->getCurrentOtp($secret));

    $code = $user->refresh()->two_factor_recovery_codes[0];

    expect($service->consumeRecoveryCode($user, $code))->toBeTrue()
        ->and($service->consumeRecoveryCode($user->refresh(), $code))->toBeFalse();
});

/** A member with the app on; returns the member (fresh) and their recovery codes. */
function recoveryMember(): array
{
    $service = app(TwoFactorService::class);
    $user = User::factory()->create();
    $secret = $service->generateSecret();
    $service->enable($user, $secret, totpCode($secret, -1));
    $user = $user->fresh();

    return [$user, $user->two_factor_recovery_codes];
}

/** The member's recovery codes as stored now. */
function storedRecoveryCodes(User $user): array
{
    return User::query()->findOrFail($user->id)->two_factor_recovery_codes;
}

it('lets a recovery code through once when two requests loaded the member before either used it', function (): void {
    [$user, $codes] = recoveryMember();
    $first = User::query()->findOrFail($user->id);  // two requests, each with the member as it
    $second = User::query()->findOrFail($user->id); // was when the request started
    $service = app(TwoFactorService::class);

    expect($service->consumeRecoveryCode($first, $codes[0]))->toBeTrue()
        ->and($service->consumeRecoveryCode($second, $codes[0]))->toBeFalse()
        ->and(storedRecoveryCodes($user))->toBe(array_slice($codes, 1));
});

it('removes both of two recovery codes used side by side, so neither works again', function (): void {
    [$user, $codes] = recoveryMember();
    $first = User::query()->findOrFail($user->id);
    $second = User::query()->findOrFail($user->id);
    $service = app(TwoFactorService::class);

    expect($service->consumeRecoveryCode($first, $codes[0]))->toBeTrue()
        ->and($service->consumeRecoveryCode($second, $codes[1]))->toBeTrue()
        ->and(storedRecoveryCodes($user))->toBe(array_slice($codes, 2));

    $later = User::query()->findOrFail($user->id);
    expect($service->consumeRecoveryCode($later, $codes[0]))->toBeFalse()
        ->and($service->consumeRecoveryCode($later, $codes[1]))->toBeFalse()
        ->and($service->consumeRecoveryCode($first, $codes[1]))->toBeFalse()
        ->and($service->consumeRecoveryCode($second, $codes[0]))->toBeFalse()
        ->and(storedRecoveryCodes($user))->toHaveCount(6);
});

it('changes nothing for a wrong or empty recovery code', function (): void {
    [$user, $codes] = recoveryMember();
    $row = fn (): array => (array) DB::connection('pgsql_admin')->table('users')->where('id', $user->id)
        ->first(['two_factor_recovery_codes', 'updated_at']);
    $before = $row();
    $service = app(TwoFactorService::class);

    expect($service->consumeRecoveryCode($user, 'aaaaa-bbbbb'))->toBeFalse()
        ->and($service->consumeRecoveryCode($user, ''))->toBeFalse()
        ->and($service->consumeRecoveryCode($user, strtoupper($codes[0])))->toBeFalse()
        ->and($row())->toBe($before)
        ->and($user->two_factor_recovery_codes)->toBe($codes);
});

it('brings the member it was given up to date with the codes stored', function (): void {
    [$user, $codes] = recoveryMember();
    $stale = User::query()->findOrFail($user->id);
    app(TwoFactorService::class)->consumeRecoveryCode(User::query()->findOrFail($user->id), $codes[0]);

    expect(app(TwoFactorService::class)->consumeRecoveryCode($stale, $codes[1]))->toBeTrue()
        ->and($stale->two_factor_recovery_codes)->toBe(array_slice($codes, 2))
        ->and($stale->isDirty())->toBeFalse()
        ->and($stale->updated_at?->toJSON())->toBe(User::query()->findOrFail($user->id)->updated_at?->toJSON());
});

it('takes a recovery code inside a transaction the request already holds', function (): void {
    [$user, $codes] = recoveryMember();
    $service = app(TwoFactorService::class);

    $ok = DB::transaction(fn (): bool => $service->consumeRecoveryCode($user, $codes[0]));

    expect($ok)->toBeTrue()
        ->and(storedRecoveryCodes($user))->toBe(array_slice($codes, 1));
});
