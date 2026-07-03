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
    $secret = $service->generateSecret();
    $code = (new Google2FA)->getCurrentOtp($secret);

    expect($service->verify($secret, $code))->toBeTrue()
        ->and($service->verify($secret, '000000'))->toBeFalse();
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
