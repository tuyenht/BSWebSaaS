<?php

use App\Modules\Identity\Actions\ConfirmIdentity;
use App\Modules\Identity\Actions\IdentityCheck;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/*
 * ConfirmIdentity: before a step that hands out personal data, the signed-in member types their
 * password or their authenticator code again. Wrong tries are limited per member (five, then a
 * wait); a code is taken once, even while the app still shows it.
 */

function confirmTwoFactor(User $user): string
{
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();
    $service->enable($user, $secret, (new Google2FA)->getCurrentOtp($secret));

    return $secret;
}

it('takes the right password and refuses a wrong or empty one', function (): void {
    $user = User::factory()->create();
    $confirm = app(ConfirmIdentity::class);

    expect($confirm->password($user, 'password'))->toBe(IdentityCheck::Confirmed)
        ->and($confirm->password($user, 'Password'))->toBe(IdentityCheck::Wrong)
        ->and($confirm->password($user, ''))->toBe(IdentityCheck::Missing)
        ->and(IdentityCheck::Confirmed->confirmed())->toBeTrue()
        ->and(IdentityCheck::Wrong->confirmed())->toBeFalse();
});

it('stops checking after five wrong tries, even the right password, and only for that member', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $confirm = app(ConfirmIdentity::class);

    foreach (range(1, ConfirmIdentity::ATTEMPTS) as $i) {
        expect($confirm->password($user, 'sai-'.$i))->toBe(IdentityCheck::Wrong);
    }

    expect($confirm->password($user, 'password'))->toBe(IdentityCheck::Throttled)
        ->and($confirm->retryIn($user))->toBeGreaterThan(0)->toBeLessThanOrEqual(ConfirmIdentity::DECAY)
        ->and($confirm->password($other, 'password'))->toBe(IdentityCheck::Confirmed);

    $this->travel(ConfirmIdentity::DECAY + 1)->seconds();
    expect($confirm->password($user, 'password'))->toBe(IdentityCheck::Confirmed);
});

it('says so when the account has no password to type', function (): void {
    $user = User::factory()->create();
    DB::connection('pgsql_admin')->table('users')->where('id', $user->id)->update(['password' => '']);

    expect(app(ConfirmIdentity::class)->password($user->fresh(), ''))->toBe(IdentityCheck::NoPassword);
});

it('takes a current authenticator code once, with or without its space', function (): void {
    $user = User::factory()->create();
    $secret = confirmTwoFactor($user);
    $user = $user->fresh();
    $confirm = app(ConfirmIdentity::class);
    $code = (new Google2FA)->getCurrentOtp($secret);

    expect($confirm->code($user, '12 34'))->toBe(IdentityCheck::Missing)
        ->and($confirm->code($user, 'abcdef'))->toBe(IdentityCheck::Missing)
        ->and($confirm->code($user, substr($code, 0, 3).' '.substr($code, 3)))->toBe(IdentityCheck::Confirmed)
        ->and($confirm->code($user, $code))->toBe(IdentityCheck::Wrong)
        ->and($confirm->code($user, $code === '000000' ? '111111' : '000000'))->toBe(IdentityCheck::Wrong);
});

it('limits wrong codes like wrong passwords', function (): void {
    $user = User::factory()->create();
    $secret = confirmTwoFactor($user);
    $user = $user->fresh();
    $confirm = app(ConfirmIdentity::class);
    $wrong = (new Google2FA)->getCurrentOtp($secret) === '000000' ? '111111' : '000000';

    foreach (range(1, ConfirmIdentity::ATTEMPTS) as $i) {
        $confirm->code($user, $wrong);
    }

    expect($confirm->code($user, (new Google2FA)->getCurrentOtp($secret)))->toBe(IdentityCheck::Throttled);
});

it('refuses a code from a member without two-step sign-in', function (): void {
    expect(app(ConfirmIdentity::class)->code(User::factory()->create(), '123456'))->toBe(IdentityCheck::NoTwoFactor);
});
