<?php

use App\Modules\Admin\Listeners\SetTenantReplyTo;
use App\Modules\Admin\Livewire\ForgotPassword;
use App\Modules\Admin\Livewire\Login;
use App\Modules\Admin\Livewire\ResetPassword;
use App\Modules\Admin\Notifications\EmailChangeCodeNotification;
use App\Modules\Admin\Notifications\EmailOtpNotification;
use App\Modules\Admin\Notifications\ResetPasswordLinkNotification;
use App\Modules\Admin\Support\Mail\MailSendContext;
use App\Modules\Admin\Support\MailHostGuard;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/*
 * The sign-in account is shared by every website. A mail carrying something that acts on it — a
 * reset link, a verification link, a sign-in code, a code that changes the sign-in email — leaves
 * through the platform's outbox only, never a website's own mail server (whose owner could read it).
 * And a website's «Quên mật khẩu» / reset pages serve only the people who may enter that website.
 */

beforeEach(function (): void {
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->instance(MailHostGuard::class, new MailHostGuard(fn (string $host): array => ['93.184.216.34']));
    Cache::forget('mail-own-outbox-failing:1');
});

afterEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings restart identity cascade');
    Cache::forget('mail-own-outbox-failing:1');
});

function amPlatformOutbox(?string $replyTo = null): void
{
    app(PlatformSettings::class)->put('email', 'config', [
        'method' => 'smtp_password',
        'from_address' => 'no-reply@nen-tang.test',
        'reply_to' => $replyTo,
        'smtp' => ['host' => 'smtp.nen-tang.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'relay', 'password' => SecretCipher::encrypt('pw')],
    ]);
}

/** Site 1 runs its own, working mail server. */
function amOwnOutbox(?string $replyTo = null): void
{
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('email', 'config', [
        'method' => 'smtp_password',
        'from_address' => 'chu@cong-ty.test',
        'from_name' => 'Cong Ty A',
        'reply_to' => $replyTo,
        'smtp' => ['host' => 'smtp.cong-ty.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'chu', 'password' => SecretCipher::encrypt('pw')],
    ]));
}

/** @return list<string> The Reply-To a message about to leave now gets. */
function amReplyTo(): array
{
    $event = new MessageSending(new Email);
    app(SetTenantReplyTo::class)->handle($event);

    return array_map(fn (Address $address): string => $address->getAddress(), $event->message->getReplyTo());
}

function amMember(): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'am-member', []));
    app(TeamContext::class)->forget();

    return $user;
}

it('sends the account mails through the platform outbox, not the website\'s own', function (Closure $send): void {
    amPlatformOutbox();
    amOwnOutbox();
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($send, $user): void {
        // Control: ordinary website mail does go through the website's own server.
        app(TenantMailer::class)->apply();
        expect(config('mail.mailers.smtp.host'))->toBe('smtp.cong-ty.test');

        $send($user);

        expect(config('mail.default'))->toBe('smtp')
            ->and(config('mail.mailers.smtp.host'))->toBe('smtp.nen-tang.test')
            ->and(config('mail.from.address'))->toBe('no-reply@nen-tang.test')
            // The website's sender name stays; only the server and address are the platform's.
            ->and(config('mail.from.name'))->toBe('Cong Ty A')
            ->and(app(MailSendContext::class)->via)->toBe('platform')
            ->and(app(MailSendContext::class)->mailer)->toBe('smtp');
    });
})->with([
    'password reset link' => [fn (User $user) => (new ResetPasswordLinkNotification('https://a.test/admin/reset-password?token=t'))->toMail($user)],
    'sign-in code' => [fn (User $user) => (new EmailOtpNotification('123456', 10))->toMail($user)],
    'sign-in email change code' => [fn (User $user) => (new EmailChangeCodeNotification('123456', 10, true))->toMail($user)],
    'email verification link' => [fn (User $user) => (new VerifyEmail)->toMail($user)],
]);

it('gives an account mail no website Reply-To, so a reply cannot carry its link or code to the website', function (Closure $send, ?string $platformReplyTo): void {
    amPlatformOutbox($platformReplyTo);
    amOwnOutbox(replyTo: 'lienhe@cong-ty.test');
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($send, $user, $platformReplyTo): void {
        // Control: an ordinary website notice still answers to the website.
        app(TenantMailer::class)->apply();
        expect(amReplyTo())->toBe(['lienhe@cong-ty.test']);

        $send($user);
        expect(amReplyTo())->toBe($platformReplyTo === null ? [] : [$platformReplyTo]);

        // The next ordinary mail answers to the website again.
        app(TenantMailer::class)->apply();
        expect(amReplyTo())->toBe(['lienhe@cong-ty.test']);
    });
})->with([
    'password reset link' => [fn (User $user) => (new ResetPasswordLinkNotification('https://a.test/admin/reset-password?token=t'))->toMail($user)],
    'sign-in code' => [fn (User $user) => (new EmailOtpNotification('123456', 10))->toMail($user)],
    'sign-in email change code' => [fn (User $user) => (new EmailChangeCodeNotification('123456', 10, true))->toMail($user)],
    'email verification link' => [fn (User $user) => (new VerifyEmail)->toMail($user)],
])->with(['no platform reply-to' => [null], 'the platform\'s reply-to' => ['hotro@nen-tang.test']]);

it('sends the verification link of a sign-in on a website through the platform outbox, without the website\'s Reply-To', function (): void {
    config(['services.turnstile.secret_key' => '']);
    amPlatformOutbox();
    amOwnOutbox(replyTo: 'lienhe@cong-ty.test');
    $transport = new ArrayTransport;
    Mail::extend('smtp', fn (): ArrayTransport => $transport);
    $member = amMember();
    $member->forceFill(['email_verified_at' => null])->save();

    app(Tenancy::class)->enter(1, function () use ($member): void {
        Livewire::test(Login::class)
            ->set('email', $member->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email');
    });

    expect($transport->messages())->toHaveCount(1);
    $sent = $transport->messages()->first()?->getOriginalMessage();
    expect($sent)->toBeInstanceOf(Email::class);
    assert($sent instanceof Email);
    expect($sent->getFrom()[0]->getAddress())->toBe('no-reply@nen-tang.test')
        ->and($sent->getTo()[0]->getAddress())->toBe($member->email)
        ->and($sent->getReplyTo())->toBe([]);
});

it('writes an account mail to the log, never to the website\'s own server, when the platform has no outbox', function (): void {
    amOwnOutbox();
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        app(TenantMailer::class)->apply();
        expect(config('mail.default'))->toBe('smtp');

        (new ResetPasswordLinkNotification('https://a.test/admin/reset-password?token=t'))->toMail($user);

        expect(config('mail.default'))->toBe('log')
            ->and(app(MailSendContext::class)->via)->toBe('platform')
            ->and(app(MailSendContext::class)->mailer)->toBe('log');
    });
});

it('sends no reset link to an account that is not a member of this website, with the same reply', function (): void {
    Notification::fake();
    $stranger = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($stranger): void {
        Livewire::test(ForgotPassword::class)
            ->set('email', $stranger->email)
            ->call('submit')
            ->assertSet('sent', true)
            ->assertSet('email', '')
            ->assertHasNoErrors()
            ->assertSee(__('auth.reset_link_sent'));
    });

    Notification::assertNothingSentTo($stranger);
    expect(DB::connection('pgsql_admin')->table('password_reset_tokens')->where('email', $stranger->email)->exists())->toBeFalse();
});

it('sends the reset link to a member of this website', function (): void {
    Notification::fake();
    $member = amMember();

    app(Tenancy::class)->enter(1, function () use ($member): void {
        Livewire::test(ForgotPassword::class)
            ->set('email', $member->email)
            ->call('submit')
            ->assertSet('sent', true)
            ->assertSee(__('auth.reset_link_sent'));
    });

    Notification::assertSentTo($member, ResetPasswordLinkNotification::class);
});

it('sends the reset link to a platform operator on a website\'s host (the operator may enter every website)', function (): void {
    Notification::fake();
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    app(TeamContext::class)->forget();

    app(Tenancy::class)->enter(1, function () use ($operator): void {
        Livewire::test(ForgotPassword::class)
            ->set('email', $operator->email)
            ->call('submit')
            ->assertSet('sent', true)
            ->assertSee(__('auth.reset_link_sent'));
    });

    Notification::assertSentTo($operator, ResetPasswordLinkNotification::class);
});

it('keeps serving every account on the platform console', function (bool $operator): void {
    Notification::fake();
    $user = User::factory()->create();
    if ($operator) {
        app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('super_admin'));
    }

    Livewire::test(ForgotPassword::class)
        ->set('email', $user->email)
        ->call('submit')
        ->assertSet('sent', true);

    Notification::assertSentTo($user, ResetPasswordLinkNotification::class);
})->with(['operator' => true, 'any account' => false]);

it('refuses a reset link of a non-member on this website\'s reset page and leaves it untouched', function (): void {
    fakePwnedVerifier();
    $stranger = User::factory()->create();
    $token = Password::createToken($stranger);

    app(Tenancy::class)->enter(1, function () use ($stranger, $token): void {
        Livewire::test(ResetPassword::class)
            ->set('token', $token)->set('email', $stranger->email)
            ->set('password', 'a-fresh-secure-pass')->set('password_confirmation', 'a-fresh-secure-pass')
            ->call('resetPassword')
            ->assertHasErrors('email')
            ->assertSee(__('auth.reset_failed'));
    });

    $stranger->refresh();
    expect(Hash::check('a-fresh-secure-pass', $stranger->password))->toBeFalse()
        ->and(Password::tokenExists($stranger, $token))->toBeTrue();
});

it('lets a member reset on this website\'s reset page', function (): void {
    fakePwnedVerifier();
    $member = amMember();
    $token = Password::createToken($member);

    app(Tenancy::class)->enter(1, function () use ($member, $token): void {
        Livewire::test(ResetPassword::class)
            ->set('token', $token)->set('email', $member->email)
            ->set('password', 'a-fresh-secure-pass')->set('password_confirmation', 'a-fresh-secure-pass')
            ->call('resetPassword')
            ->assertHasNoErrors();
    });

    expect(Hash::check('a-fresh-secure-pass', $member->fresh()?->password ?? ''))->toBeTrue();
});

it('spends the token\'s hash on a reset page refusal, whoever the address belongs to', function (string $who): void {
    fakePwnedVerifier();
    $email = match ($who) {
        'unknown address' => 'nobody-'.Str::random(8).'@example.test',
        'not a member' => User::factory()->create()->email,
        'member with no link waiting' => amMember()->email,
    };
    Hash::spy();

    app(Tenancy::class)->enter(1, function () use ($email): void {
        Livewire::test(ResetPassword::class)
            ->set('token', Str::random(64))->set('email', $email)
            ->set('password', 'a-fresh-secure-pass')->set('password_confirmation', 'a-fresh-secure-pass')
            ->call('resetPassword')
            ->assertHasErrors('email')
            ->assertSee(__('auth.reset_failed'));
    });

    Hash::shouldHaveReceived('make')->once();
})->with(['unknown address', 'not a member', 'member with no link waiting']);

it('spends the token\'s hash on a repeated ask within the broker\'s throttle, so it answers no faster', function (): void {
    Notification::fake();
    $member = amMember();
    $ask = fn () => app(Tenancy::class)->enter(1, fn () => Livewire::test(ForgotPassword::class)
        ->set('email', $member->email)->call('submit')->assertSet('sent', true));

    $ask();
    Hash::spy();
    $ask();

    Hash::shouldHaveReceived('make')->once();
    Notification::assertSentToTimes($member, ResetPasswordLinkNotification::class, 1);
});
