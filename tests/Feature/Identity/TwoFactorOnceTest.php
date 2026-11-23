<?php

use App\Modules\Admin\Livewire\TwoFactorChallenge;
use App\Modules\Identity\Actions\ConfirmIdentity;
use App\Modules\Identity\Actions\IdentityCheck;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/*
 * An authenticator code passes once (RFC 6238 §5.2), wherever the member types it: at sign-in,
 * in a re-check before a sensitive step, when turning the app on. The same code again, or an
 * older one, reads as a wrong code; a wrong code leaves nothing behind; the next code works.
 */

/** A member with the app on; returns the member (fresh) and the secret. */
function onceMember(): array
{
    $service = app(TwoFactorService::class);
    $user = User::factory()->create();
    $secret = $service->generateSecret();
    $service->enable($user, $secret, totpCode($secret, -1));

    return [$user->fresh(), $secret];
}

/** A 6-digit code that fits none of the steps the app's window accepts now. */
function onceWrongCode(string $secret): string
{
    foreach (['000000', '111111', '222222', '333333', '444444'] as $code) {
        if (! app(TwoFactorService::class)->verify($secret, $code)) {
            return $code;
        }
    }

    throw new RuntimeException('no wrong code found');
}

/** Sign-in step two with $code for $user, as a fresh visitor; returns the Livewire test. */
function onceSignIn(User $user, string $code): mixed
{
    Auth::logout();
    session(['2fa.user_id' => $user->getAuthIdentifier()]);

    return Livewire::test(TwoFactorChallenge::class)->set('code', $code)->call('verify');
}

it('takes a code once, and the same code sent twice side by side passes only once', function (): void {
    [$user, $secret] = onceMember();
    $service = app(TwoFactorService::class);
    $code = totpCode($secret);

    expect($service->verifyOnce($user, $code))->toBeTrue()
        ->and($service->verifyOnce($user, $code))->toBeFalse();

    // a second request that read the last-step mark before the first one wrote it: the step's own
    // atomic key still turns it away
    $next = totpCode($secret, 1);
    expect($service->verifyOnce($user, $next))->toBeTrue();
    Cache::forget('two-factor:last-step:'.$user->id.':'.substr(hash('sha256', $secret), 0, 16));
    expect($service->verifyOnce($user, $next))->toBeFalse();
});

it('never remembers a wrong code, refuses an older code and takes the next one', function (): void {
    [$user, $secret] = onceMember();
    $service = app(TwoFactorService::class);

    expect($service->verifyOnce($user, onceWrongCode($secret)))->toBeFalse()
        ->and($service->verifyOnce($user, totpCode($secret)))->toBeTrue()
        ->and($service->verifyOnce($user, totpCode($secret, -1)))->toBeFalse() // the code that turned the app on
        ->and($service->verifyOnce($user, totpCode($secret, 1)))->toBeTrue();
});

it('refuses an older code nobody used once a newer one was taken', function (): void {
    [$user, $secret] = onceMember();
    $service = app(TwoFactorService::class);

    expect($service->verifyOnce($user, totpCode($secret, 1)))->toBeTrue()
        ->and($service->verify($secret, totpCode($secret)))->toBeTrue() // still inside the app's window
        ->and($service->verifyOnce($user, totpCode($secret)))->toBeFalse()
        ->and($service->verifyOnce($user, totpCode($secret, 2)))->toBeTrue();
});

it('starts clean when the app is set up again with a new secret', function (): void {
    $service = app(TwoFactorService::class);
    $user = User::factory()->create();
    $first = $service->generateSecret();
    $service->enable($user, $first, totpCode($first, 1));

    $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
    $second = $service->generateSecret();

    expect($service->enable($user->fresh(), $second, totpCode($second)))->toBeTrue();
});

it('keeps each member to their own codes', function (): void {
    [$user, $secret] = onceMember();
    $other = User::factory()->create();
    $otherSecret = app(TwoFactorService::class)->generateSecret();
    app(TwoFactorService::class)->enable($other, $otherSecret, totpCode($otherSecret, -1));
    $other = $other->fresh();

    expect(app(TwoFactorService::class)->verifyOnce($user, totpCode($secret)))->toBeTrue()
        ->and(app(TwoFactorService::class)->verifyOnce($other, totpCode($otherSecret)))->toBeTrue()
        ->and(app(TwoFactorService::class)->verifyOnce(User::factory()->create(), '123456'))->toBeFalse();
});

it('refuses a code used at sign-in for a second sign-in and for the re-check', function (): void {
    [$user, $secret] = onceMember();
    $code = totpCode($secret);

    onceSignIn($user, $code)->assertHasNoErrors()->assertRedirect(route('admin.dashboard'));
    $this->assertAuthenticatedAs($user);

    onceSignIn($user, $code)->assertHasErrors('code')->assertSee(__('auth.two_factor_invalid'))->assertNoRedirect();
    $this->assertGuest();

    expect(app(ConfirmIdentity::class)->code($user, $code))->toBe(IdentityCheck::Wrong)
        ->and(app(ConfirmIdentity::class)->code($user, totpCode($secret, 1)))->toBe(IdentityCheck::Confirmed);
});

it('refuses at sign-in a code just used in the re-check', function (): void {
    [$user, $secret] = onceMember();
    $code = totpCode($secret);

    expect(app(ConfirmIdentity::class)->code($user, $code))->toBe(IdentityCheck::Confirmed);

    onceSignIn($user, $code)->assertHasErrors('code')->assertNoRedirect();
    $this->assertGuest();

    onceSignIn($user, totpCode($secret, 1))->assertHasNoErrors()->assertRedirect(route('admin.dashboard'));
    $this->assertAuthenticatedAs($user);
});

it('refuses at sign-in the code that turned the app on', function (): void {
    $service = app(TwoFactorService::class);
    $user = User::factory()->create();
    $secret = $service->generateSecret();
    $code = totpCode($secret);

    expect($service->enable($user, $secret, $code))->toBeTrue();

    onceSignIn($user->fresh(), $code)->assertHasErrors('code')->assertNoRedirect();
    $this->assertGuest();
});
