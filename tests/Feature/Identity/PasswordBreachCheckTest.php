<?php

use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\NotPwnedVerifier;
use Illuminate\Validation\Rules\Password;

/*
 * The breach check behind every new password (Password::defaults()->uncompromised()) asks
 * HaveIBeenPwned for a 5-character hash prefix. It waits a few seconds at most, and an outage
 * there lets the password through (the length rule still applies) rather than blocking a change.
 */

it('waits at most a few seconds for the breach check', function (): void {
    $verifier = app(UncompromisedVerifier::class);

    expect($verifier)->toBeInstanceOf(NotPwnedVerifier::class)
        ->and((new ReflectionProperty(NotPwnedVerifier::class, 'timeout'))->getValue($verifier))->toBe(AppServiceProvider::BREACH_CHECK_TIMEOUT)
        ->and(AppServiceProvider::BREACH_CHECK_TIMEOUT)->toBeLessThanOrEqual(5);
});

it('lets a new password through when the breach service is down', function (): void {
    Http::preventStrayRequests();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 503)]);

    expect(Validator::make(['password' => 'mua-ca-phe-luc-7-gio'], ['password' => [Password::defaults()]])->passes())->toBeTrue()
        ->and(Validator::make(['password' => 'short'], ['password' => [Password::defaults()]])->passes())->toBeFalse();
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.pwnedpasswords.com/range/'));
});

it('refuses a password the breach service reports', function (): void {
    $suffix = substr(strtoupper(sha1('mua-ca-phe-luc-7-gio')), 5);
    Http::preventStrayRequests();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response("{$suffix}:12\r\n")]);

    expect(Validator::make(['password' => 'mua-ca-phe-luc-7-gio'], ['password' => [Password::defaults()]])->passes())->toBeFalse();
});
