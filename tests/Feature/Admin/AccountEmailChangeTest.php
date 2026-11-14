<?php

use App\Modules\Admin\Livewire\Account;
use App\Modules\Admin\Notifications\EmailChangeCodeNotification;
use App\Modules\Admin\Notifications\EmailChangedNotification;
use App\Modules\Admin\Support\EmailChange;
use App\Modules\Admin\Support\EmailOtp;
use App\Modules\Admin\Support\SignedInDevices;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * Changing the sign-in email from My account. Besides the password the member passes the second
 * step they use to sign in (the authenticator app or a recovery code when it is on, else a code
 * mailed to the current address), then types the code mailed to the new address. The old address
 * always hears of the change; staff signed in for support cannot do it; the codes are single use,
 * expire after ten minutes, are tried and sent a bounded number of times and never pass as
 * sign-in codes; whether another account holds the new address is told only to whoever reads
 * that mailbox.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    fakePwnedVerifier();
    app()->setLocale('vi');
    RateLimiter::clear('email-change-send-ip:'.sha1('127.0.0.1'));
    Notification::fake();
});

function emMember(array $attributes = []): User
{
    $user = User::factory()->create(['email' => 'em-'.Str::lower(Str::random(10)).'@example.test', 'password' => Hash::make('old-password-123'), ...$attributes]);
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));
    foreach (['account-reauth', 'email-change-send', 'email-change-verify', 'email-otp-send'] as $limiter) {
        RateLimiter::clear($limiter.':'.$user->id);
    }
    Cache::forget('email-change:'.$user->id);

    return $user->fresh();
}

function emWithApp(User $user): User
{
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();
    $service->enable($user, $secret, (new Google2FA)->getCurrentOtp($secret));

    return $user->fresh();
}

/** @return Livewire\Features\SupportTesting\Testable */
function emPage(User $user): mixed
{
    return Livewire::actingAs($user)->test(Account::class);
}

function emNewAddress(): string
{
    return 'moi-'.Str::lower(Str::random(10)).'@congty.vn';
}

/** @return list<EmailChangeCodeNotification> the code mails sent to $address, oldest first */
function emCodeMails(string $address): array
{
    return Notification::sent(new AnonymousNotifiable, EmailChangeCodeNotification::class,
        fn ($notification, $channels, $notifiable): bool => ($notifiable->routes['mail'] ?? null) === $address,
    )->values()->all();
}

/** The six digits of the newest code mailed to $address, as the member reads them in the mail. */
function emCode(string $address): string
{
    $mails = emCodeMails($address);
    expect($mails)->not->toBe([]);
    $mail = app(Tenancy::class)->enter(1, fn () => end($mails)->toMail(new AnonymousNotifiable));
    preg_match('/\b(\d{6})\b/', implode("\n", $mail->introLines), $m);

    return $m[1] ?? '';
}

/** Walk steps 1 and 2 for a member without the app: returns the page waiting for the new code. */
function emToLastStep(User $user, string $new): mixed
{
    $page = emPage($user)->call('openEmailChange')
        ->set('newEmail', $new)->set('emailPassword', 'old-password-123')
        ->call('startEmailChange')->assertHasNoErrors()->assertSet('emailStep', 2);

    return $page->set('emailFactorCode', emCode($user->email))->call('verifyEmailFactor')
        ->assertHasNoErrors()->assertSet('emailStep', 3);
}

function emAudit(string $action, User $user): array
{
    return DB::connection('pgsql_admin')->table('audit_logs')->where('action', $action)->where('actor_id', $user->id)->get()->all();
}

// ── the whole change ─────────────────────────────────────────────────────────

it('changes the email after the password, a code to the current address and a code to the new one', function (): void {
    $user = emMember();
    $old = $user->email;
    $new = emNewAddress();

    app(Tenancy::class)->enter(1, function () use ($user, $old, $new): void {
        $page = emPage($user)->call('openEmailChange')->assertSet('emailStep', 1)
            ->set('newEmail', '  '.Str::upper($new).' ')->set('emailPassword', 'old-password-123')
            ->call('startEmailChange')
            ->assertHasNoErrors()->assertSet('emailStep', 2)->assertSet('emailFactor', 'mail');

        // the second step's code goes to the address the member signs in with today, nothing to the new one yet
        expect(emCodeMails($old))->toHaveCount(1)->and(emCodeMails($new))->toBe([]);

        $page->set('emailFactorCode', emCode($old))->call('verifyEmailFactor')
            ->assertHasNoErrors()->assertSet('emailStep', 3);
        expect(emCodeMails($new))->toHaveCount(1);

        $page->set('emailCode', implode(' ', str_split(emCode($new), 3)))->call('confirmEmailChange')
            ->assertHasNoErrors()->assertSet('emailStep', 0)
            ->assertSee('Đã đổi email đăng nhập. Đã gửi thư báo tới địa chỉ cũ.')
            ->assertSee($new);
    });

    $fresh = $user->fresh();
    expect($fresh->email)->toBe($new)
        ->and($fresh->email_verified_at)->not->toBeNull()
        ->and(Cache::get('email-change:'.$user->id))->toBeNull();

    // the old address is told, on its own (a queued mail cannot follow the account to the new one)
    Notification::assertSentOnDemand(EmailChangedNotification::class, fn ($notice, $channels, $notifiable): bool => $notifiable->routes['mail'] === $old && $channels === ['mail']);
    Notification::assertNotSentTo($fresh, EmailChangedNotification::class);

    $rows = emAudit('identity.email.changed', $user);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->actor_label)->toBe($old)
        ->and(json_decode((string) $rows[0]->meta, true))->toBe(['fields' => ['email']])
        ->and(json_encode($rows[0]))->not->toContain($new);
});

it('masks the new address in the notice to the old one and says how to reach support', function (): void {
    config(['support.mailbox' => 'hotro@nentang.test']);
    $notice = new EmailChangedNotification('15/11/2026 09:30', EmailChange::mask('nguyen.van@congty.vn'));
    $mail = app(Tenancy::class)->enter(1, fn () => $notice->toMail(new AnonymousNotifiable));
    $text = implode("\n", $mail->introLines);

    expect(EmailChange::mask('nguyen.van@congty.vn'))->toBe('n***@congty.vn')
        ->and($text)->toContain('n***@congty.vn')->toContain('15/11/2026 09:30')->toContain('hotro@nentang.test')
        ->and($text)->not->toContain('nguyen.van')
        ->and($notice->via(new AnonymousNotifiable))->toBe(['mail']);
});

it('asks for the authenticator app instead of a mailed code when it is on, and takes a recovery code once', function (): void {
    $user = emWithApp(emMember());
    $secret = (string) $user->two_factor_secret;
    $recovery = $user->two_factor_recovery_codes[0];

    app(Tenancy::class)->enter(1, function () use ($user, $secret, $recovery): void {
        $page = emPage($user)->call('openEmailChange')
            ->set('newEmail', emNewAddress())->set('emailPassword', 'old-password-123')
            ->call('startEmailChange')
            ->assertHasNoErrors()->assertSet('emailStep', 2)->assertSet('emailFactor', 'app');
        expect(emCodeMails($user->email))->toBe([]);

        $page->set('emailFactorCode', '000000')->call('verifyEmailFactor')->assertHasErrors('emailFactorCode')->assertSet('emailStep', 2);
        $page->set('emailFactorCode', (new Google2FA)->getCurrentOtp($secret))->call('verifyEmailFactor')
            ->assertHasNoErrors()->assertSet('emailStep', 3);

        // again, with a recovery code: it is used up
        $page->call('cancelEmailChange')->assertSet('emailStep', 0)->call('openEmailChange')
            ->set('newEmail', emNewAddress())->set('emailPassword', 'old-password-123')->call('startEmailChange')
            ->set('emailUseRecovery', true)->set('emailFactorCode', ' '.Str::upper($recovery).' ')->call('verifyEmailFactor')
            ->assertHasNoErrors()->assertSet('emailStep', 3);
    });

    expect($user->fresh()->two_factor_recovery_codes)->not->toContain($recovery);
});

// ── step 1 ─────────────────────────────────────────────────────────

it('checks the new address when the member leaves the field, with the board wording', function (string $typed, ?string $error): void {
    $user = emMember();
    $typed = $typed === 'CURRENT' ? Str::upper($user->email) : $typed;

    app(Tenancy::class)->enter(1, function () use ($user, $typed, $error): void {
        $page = emPage($user)->call('openEmailChange')->set('newEmail', $typed);
        $error === null ? $page->assertHasNoErrors('newEmail') : $page->assertHasErrors(['newEmail' => $error]);
    });
})->with([
    'not an address' => ['ten-congty', 'Email chưa đúng dạng, ví dụ ten@congty.vn.'],
    'no ending' => ['ten@congty', 'Email chưa đúng dạng, ví dụ ten@congty.vn.'],
    'too long' => [str_repeat('a', 250).'@congty.vn', 'Email chưa đúng dạng, ví dụ ten@congty.vn.'],
    'the current one' => ['CURRENT', 'Đây là email bạn đang dùng. Nhập địa chỉ mới.'],
    'a good one' => ['ten@congty.vn', null],
]);

it('sends nothing until the address is right and the password is the member\'s', function (string $new, string $password, string $field, string $error): void {
    $user = emMember();

    app(Tenancy::class)->enter(1, fn () => emPage($user)->call('openEmailChange')
        ->set('newEmail', $new)->set('emailPassword', $password)
        ->call('startEmailChange')
        ->assertHasErrors([$field => $error])->assertSet('emailStep', 1));

    expect(emCodeMails($user->email))->toBe([])->and(Cache::get('email-change:'.$user->id))->toBeNull();
})->with([
    'bad address' => ['ten-congty', 'old-password-123', 'newEmail', 'Email chưa đúng dạng, ví dụ ten@congty.vn.'],
    'no password' => ['ten@congty.vn', '', 'emailPassword', 'Nhập mật khẩu bạn đang dùng để xác nhận là bạn.'],
    'wrong password' => ['ten@congty.vn', 'not-my-password', 'emailPassword', 'Mật khẩu chưa đúng. Gõ lại — chú ý chữ hoa và bộ gõ tiếng Việt.'],
]);

it('pauses the password check after five wrong tries', function (): void {
    $user = emMember();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = emPage($user)->call('openEmailChange')->set('newEmail', 'ten@congty.vn');
        foreach (range(1, 5) as $_) {
            $page->set('emailPassword', 'not-my-password')->call('startEmailChange');
        }
        $page->set('emailPassword', 'old-password-123')->call('startEmailChange')
            ->assertHasErrors(['emailPassword' => fn (array $rules, array $messages): bool => str_starts_with($messages[0] ?? '', 'Bạn đã thử sai nhiều lần.')])->assertSet('emailStep', 1);
    });
});

it('does not start for an account that has no password', function (): void {
    $user = emMember();
    // No password on record (written past the hashing cast): a social sign-in is the only way in.
    User::query()->whereKey($user->id)->update(['password' => '']);
    $user = $user->fresh();

    app(Tenancy::class)->enter(1, fn () => emPage($user)->call('openEmailChange')
        ->assertSet('emailStep', 0)
        ->assertSee('Tài khoản này chưa có mật khẩu nên chưa đổi được email.'));
});

it('answers an address another account holds exactly as a free one until its mailbox code is typed', function (): void {
    $user = emMember();
    $other = User::factory()->create(['email' => emNewAddress()]);

    app(Tenancy::class)->enter(1, function () use ($user, $other): void {
        $page = emToLastStep($user, Str::upper($other->email));
        expect(emCodeMails($other->email))->toHaveCount(1);

        // only whoever reads that mailbox learns it is taken
        $page->set('emailCode', emCode($other->email))->call('confirmEmailChange')
            ->assertHasErrors(['emailCode' => 'Không đổi được sang địa chỉ này. Nếu đó là tài khoản khác của bạn, hãy nhờ hỗ trợ.']);
    });

    expect($user->fresh()->email)->not->toBe($other->email)
        ->and($other->fresh()->email)->toBe($other->email)
        ->and(emAudit('identity.email.changed', $user))->toBe([]);
    Notification::assertNotSentTo(new AnonymousNotifiable, EmailChangedNotification::class);
});

it('refuses at the last step an address another account took meanwhile', function (): void {
    $user = emMember();
    $new = emNewAddress();

    app(Tenancy::class)->enter(1, function () use ($user, $new): void {
        $page = emToLastStep($user, $new);
        User::factory()->create(['email' => Str::upper($new)]);

        $page->set('emailCode', emCode($new))->call('confirmEmailChange')
            ->assertHasErrors(['emailCode' => 'Không đổi được sang địa chỉ này. Nếu đó là tài khoản khác của bạn, hãy nhờ hỗ trợ.']);
    });

    expect(User::query()->where('email', $new)->count())->toBe(1)
        ->and($user->fresh()->email)->not->toBe($new);
});

// ── the codes ─────────────────────────────────────────────────────────

it('never lets the change codes pass as sign-in codes or use up the sign-in code sends', function (): void {
    $user = emMember();

    app(Tenancy::class)->enter(1, fn () => emToLastStep($user, emNewAddress()));

    $code = emCode($user->email);
    expect(app(EmailOtp::class)->verify($user, $code))->toBeFalse()
        ->and(RateLimiter::attempts('email-otp-send:'.$user->id))->toBe(0);
});

it('burns a code after five wrong tries, on record, and takes only a fresh one', function (): void {
    $user = emMember();
    $new = emNewAddress();

    app(Tenancy::class)->enter(1, function () use ($user, $new): void {
        $page = emToLastStep($user, $new);
        $right = emCode($new);
        $wrong = $right === '111111' ? '222222' : '111111';
        foreach (range(1, 5) as $_) {
            $page->set('emailCode', $wrong)->call('confirmEmailChange')->assertHasErrors('emailCode');
        }
        foreach (range(1, 3) as $_) {
            $page->set('emailCode', $right)->call('confirmEmailChange')
                ->assertHasErrors(['emailCode' => 'Bạn đã nhập sai nhiều lần. Bấm Gửi lại mã để nhận mã mới.'])
                ->assertSet('emailStep', 3);
        }
    });

    // one row for the lock, however many tries come after it
    expect($user->fresh()->email)->not->toBe($new)
        ->and(emAudit('identity.email.change_locked', $user))->toHaveCount(1);
});

it('takes six digits only, spaces allowed, with the board wording', function (): void {
    $user = emMember();
    $new = emNewAddress();

    app(Tenancy::class)->enter(1, fn () => emToLastStep($user, $new)
        ->set('emailCode', '12345')->call('confirmEmailChange')
        ->assertHasErrors(['emailCode' => 'Mã gồm đúng 6 số, ví dụ 123 456.']));

    expect(RateLimiter::attempts('email-change-verify:'.$user->id))->toBe(0);
});

it('lets a code go after ten minutes', function (): void {
    $user = emMember();
    $new = emNewAddress();

    app(Tenancy::class)->enter(1, function () use ($user, $new): void {
        $page = emToLastStep($user, $new);
        $code = emCode($new);
        $this->travel(11)->minutes();
        $page->set('emailCode', $code)->call('confirmEmailChange')->assertNotSet('emailStep', 0);
    });

    expect($user->fresh()->email)->not->toBe($new)->and(emAudit('identity.email.changed', $user))->toBe([]);
});

it('sends a code again only after a minute, and only the newest one works', function (): void {
    $user = emMember();
    $new = emNewAddress();

    app(Tenancy::class)->enter(1, function () use ($user, $new): void {
        $page = emToLastStep($user, $new);
        $first = emCode($new);

        $page->call('resendEmailCode')->assertSee('Vừa gửi mã xong. Đợi một chút rồi bấm Gửi lại mã.');
        expect(emCodeMails($new))->toHaveCount(1);

        $this->travel(61)->seconds();
        $page->call('resendEmailCode')->assertSee('Đã gửi lại mã tới '.$new.'.');
        expect(emCodeMails($new))->toHaveCount(2);
        $second = emCode($new);

        if ($first !== $second) {
            $page->set('emailCode', $first)->call('confirmEmailChange')->assertHasErrors('emailCode');
        }
        $page->set('emailCode', $second)->call('confirmEmailChange')->assertHasNoErrors()->assertSet('emailStep', 0);
    });

    expect($user->fresh()->email)->toBe($new);
});

it('caps the code mails of one account in an hour, on record', function (): void {
    $user = emMember();
    $new = emNewAddress();

    app(Tenancy::class)->enter(1, function () use ($user, $new): void {
        $page = emToLastStep($user, $new); // two mails: the current address, then the new one
        foreach (range(1, 8) as $_) {
            $this->travel(61)->seconds();
            $page->call('resendEmailCode');
        }
    });

    // one row for the cap, not one per refused click
    expect(count(emCodeMails($user->email)) + count(emCodeMails($new)))->toBe(EmailChange::MAX_SENDS_PER_HOUR)
        ->and(emAudit('identity.email.code_throttled', $user))->toHaveCount(1);
});

it('caps the code mails to one new address in an hour, on its own key', function (): void {
    $user = emMember();
    $new = emNewAddress();
    foreach (range(1, EmailChange::MAX_SENDS_PER_HOUR_PER_ADDRESS) as $_) {
        RateLimiter::hit('email-change-send-to:new:'.sha1($new), 3600);
    }

    app(Tenancy::class)->enter(1, fn () => emToLastStep($user, $new));

    expect(emCodeMails($new))->toBe([])
        ->and(emAudit('identity.email.code_throttled', $user))->toHaveCount(1);
});

it('still sends a member the second-step code when other accounts keep naming their address as a new one', function (): void {
    $user = emMember();
    foreach (range(1, EmailChange::MAX_SENDS_PER_HOUR_PER_ADDRESS) as $_) {
        RateLimiter::hit('email-change-send-to:new:'.sha1($user->email), 3600);
    }

    app(Tenancy::class)->enter(1, fn () => emToLastStep($user, emNewAddress()));

    expect(emCodeMails($user->email))->toHaveCount(1);
});

it('caps the code mails from one network address in an hour', function (): void {
    $user = emMember();
    foreach (range(1, EmailChange::MAX_SENDS_PER_HOUR_PER_IP) as $_) {
        RateLimiter::hit('email-change-send-ip:'.sha1('127.0.0.1'), 3600);
    }

    app(Tenancy::class)->enter(1, fn () => emPage($user)->call('openEmailChange')
        ->set('newEmail', emNewAddress())->set('emailPassword', 'old-password-123')
        ->call('startEmailChange')->assertHasNoErrors()->assertSet('emailStep', 2));

    expect(emCodeMails($user->email))->toBe([])
        ->and(emAudit('identity.email.code_throttled', $user))->toHaveCount(1);
});

it('pauses the authenticator step after five wrong codes, even for the right one', function (): void {
    $user = emWithApp(emMember());
    $secret = (string) $user->two_factor_secret;

    app(Tenancy::class)->enter(1, function () use ($user, $secret): void {
        $page = emPage($user)->call('openEmailChange')
            ->set('newEmail', emNewAddress())->set('emailPassword', 'old-password-123')
            ->call('startEmailChange')->assertSet('emailStep', 2)->assertSet('emailFactor', 'app');
        $right = (new Google2FA)->getCurrentOtp($secret);
        $wrong = $right === '111111' ? '222222' : '111111';
        foreach (range(1, EmailChange::MAX_TRIES) as $_) {
            $page->set('emailFactorCode', $wrong)->call('verifyEmailFactor')->assertHasErrors('emailFactorCode');
        }
        $page->set('emailFactorCode', $right)->call('verifyEmailFactor')
            ->assertHasErrors('emailFactorCode')->assertSet('emailStep', 2);
    });

    expect(emAudit('identity.email.change_locked', $user))->toHaveCount(1);
});

it('starts again when the account\'s email changed some other way before the last code', function (): void {
    $user = emMember();
    $new = emNewAddress();

    app(Tenancy::class)->enter(1, function () use ($user, $new): void {
        $page = emToLastStep($user, $new);
        User::query()->whereKey($user->id)->update(['email' => 'khac-'.Str::lower(Str::random(8)).'@example.test']);

        $page->set('emailCode', emCode($new))->call('confirmEmailChange')
            ->assertHasNoErrors()->assertSet('emailStep', 1)->assertSee('Yêu cầu đổi email đã hết hạn. Bắt đầu lại từ đầu.');
    });

    expect($user->fresh()->email)->not->toBe($new)
        ->and(emAudit('identity.email.changed', $user))->toBe([]);
});

it('never takes one member\'s code for another member\'s change', function (): void {
    $a = emMember();
    $b = emMember();
    $newA = emNewAddress();
    $newB = emNewAddress();

    app(Tenancy::class)->enter(1, function () use ($a, $b, $newA, $newB): void {
        emToLastStep($a, $newA);
        $pageB = emToLastStep($b, $newB);
        if (emCode($newA) !== emCode($newB)) { // two random codes alike: nothing to tell apart
            $pageB->set('emailCode', emCode($newA))->call('confirmEmailChange')->assertHasErrors('emailCode')->assertSet('emailStep', 3);
            expect($b->fresh()->email)->toBe($b->email);
        }
        // B's own code still works afterwards: A's code neither passed nor burnt B's change
        $pageB->set('emailCode', emCode($newB))->call('confirmEmailChange')->assertHasNoErrors()->assertSet('emailStep', 0);
    });

    expect($b->fresh()->email)->toBe($newB)->and($a->fresh()->email)->toBe($a->email);
});

it('burns a sign-in code already mailed to the old address once the email moves', function (): void {
    $user = emMember();
    $new = emNewAddress();

    app(Tenancy::class)->enter(1, function () use ($user, $new): void {
        expect(app(EmailOtp::class)->send($user))->toBeTrue();
        expect(Cache::has('email-otp:'.$user->id))->toBeTrue();

        emToLastStep($user, $new)->set('emailCode', emCode($new))->call('confirmEmailChange')
            ->assertHasNoErrors()->assertSet('emailStep', 0);
    });

    expect($user->fresh()->email)->toBe($new)
        ->and(Cache::has('email-otp:'.$user->id))->toBeFalse();
});

// ── order and binding ─────────────────────────────────────────────────────────

it('never skips the second step, and changes to the address the code went to', function (): void {
    $user = emMember();
    $new = emNewAddress();

    app(Tenancy::class)->enter(1, function () use ($user, $new): void {
        $page = emPage($user)->call('openEmailChange')
            ->set('newEmail', $new)->set('emailPassword', 'old-password-123')->call('startEmailChange')
            ->set('emailCode', '123456')->call('confirmEmailChange');
        expect($user->fresh()->email)->not->toBe($new);

        $page->set('emailFactorCode', emCode($user->email))->call('verifyEmailFactor')->assertSet('emailStep', 3)
            ->set('newEmail', 'ke-gian@congty.vn')
            ->set('emailCode', emCode($new))->call('confirmEmailChange')->assertHasNoErrors();
    });

    expect($user->fresh()->email)->toBe($new);
});

it('drops a change begun in another browser session', function (): void {
    $user = emMember();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = emPage($user)->call('openEmailChange')
            ->set('newEmail', emNewAddress())->set('emailPassword', 'old-password-123')->call('startEmailChange');
        $code = emCode($user->email);
        session()->setId(Str::random(40));

        $page->set('emailFactorCode', $code)->call('verifyEmailFactor')
            ->assertSee('Yêu cầu đổi email đã hết hạn. Bắt đầu lại từ đầu.')->assertSet('emailStep', 1);
    });
});

it('starts again from the address when the member goes back', function (): void {
    $user = emMember();
    $new = emNewAddress();

    app(Tenancy::class)->enter(1, fn () => emToLastStep($user, $new)
        ->call('backEmailChange')->assertSet('emailStep', 1)->assertSet('newEmail', $new)->assertSet('emailPassword', ''));

    expect(Cache::get('email-change:'.$user->id))->toBeNull();
});

// ── after the change ─────────────────────────────────────────────────────────

it('signs the member\'s other devices out, keeps this one with a new session id, and drops a reset link of the old address', function (): void {
    $user = emMember();
    $old = $user->email;
    $new = emNewAddress();
    session()->setId(Str::random(40));
    session()->start();
    $before = session()->getId();
    $other = Str::random(40);
    app('session.store')->getHandler()->write($other, 'a:0:{}');
    app(SignedInDevices::class)->record($user, $other, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1', 1, null);
    DB::table('password_reset_tokens')->insert(['email' => $old, 'token' => Hash::make('x'), 'created_at' => now()]);

    app(Tenancy::class)->enter(1, fn () => emToLastStep($user, $new)
        ->set('emailCode', emCode($new))->call('confirmEmailChange')
        ->assertHasNoErrors()->assertSee('1 thiết bị khác đã tự đăng xuất.'));

    expect(app('session.store')->getHandler()->read($other))->toBe('')
        ->and(session()->getId())->not->toBe($before)
        ->and(auth()->id())->toBe($user->id)
        ->and(DB::table('password_reset_tokens')->where('email', $old)->exists())->toBeFalse();
});

it('only lets support staff look: the email change is refused during a support session', function (string $method): void {
    $user = emMember();
    $before = $user->getAttributes();

    $session = app('session')->driver();
    request()->setLaravelSession($session);
    auth()->login($user);
    app(SupportSession::class)->start($user, $user, 1, 'Kiểm tra', now()->addMinutes(30));
    app()->rebinding('request', function ($app, Request $request) use ($session): void {
        if (! $request->hasSession()) {
            $request->setLaravelSession($session);
        }
    });

    app(Tenancy::class)->enter(1, fn () => emPage($user)
        ->set('newEmail', emNewAddress())->set('emailPassword', 'old-password-123')
        ->set('emailFactorCode', '123456')->set('emailCode', '123456')
        ->call($method)
        ->assertForbidden());

    expect($user->fresh()->getAttributes())->toBe($before)->and(emCodeMails($user->email))->toBe([]);
})->with(['openEmailChange', 'startEmailChange', 'verifyEmailFactor', 'resendEmailCode', 'confirmEmailChange', 'backEmailChange', 'cancelEmailChange']);

it('drops a change half an hour after it began, however often its code was sent again', function (): void {
    $user = emMember();
    $new = emNewAddress();

    app(Tenancy::class)->enter(1, function () use ($user, $new): void {
        $page = emToLastStep($user, $new);
        foreach (range(1, 3) as $_) {
            $this->travel(9)->minutes();
            $page->call('resendEmailCode')->assertSee('Đã gửi lại mã tới '.$new.'.');
        }
        $this->travel(4)->minutes(); // 31 minutes since it began, 4 since the last code
        $page->set('emailCode', emCode($new))->call('confirmEmailChange')
            ->assertSee('Yêu cầu đổi email đã hết hạn. Bắt đầu lại từ đầu.')->assertSet('emailStep', 1);
    });

    expect($user->fresh()->email)->not->toBe($new);
});

it('never lets another browser session of the member wipe a change pending in this one', function (): void {
    $user = emMember();
    $new = emNewAddress();
    $mine = Str::random(40);
    session()->setId($mine);

    app(Tenancy::class)->enter(1, function () use ($user, $new, $mine): void {
        $page = emToLastStep($user, $new);

        session()->setId(Str::random(40));
        emPage($user)->call('cancelEmailChange');
        session()->setId($mine);

        $page->set('emailCode', emCode($new))->call('confirmEmailChange')->assertHasNoErrors()->assertSet('emailStep', 0);
    });

    expect($user->fresh()->email)->toBe($new);
});

it('says so when the other devices could not be signed out after the change', function (): void {
    Exceptions::fake();
    $user = emMember();
    $new = emNewAddress();

    $config = config('database.redis');
    $flaky = new class(app(), Arr::pull($config, 'client', 'phpredis'), $config) extends RedisManager
    {
        public function __call($method, $parameters)
        {
            if (strtolower($method) === 'zremrangebyscore' && str_starts_with((string) ($parameters[0] ?? ''), 'signed_in_devices:')) {
                throw new RuntimeException('Valkey went away');
            }

            return parent::__call($method, $parameters);
        }
    };

    app(Tenancy::class)->enter(1, function () use ($user, $new, $flaky): void {
        $page = emToLastStep($user, $new)->set('emailCode', emCode($new));
        $real = app('redis');
        app()->instance('redis', $flaky);
        Redis::clearResolvedInstance('redis');
        try {
            $page->call('confirmEmailChange')->assertHasNoErrors()
                ->assertSee('chưa đăng xuất được các thiết bị khác', false)
                ->assertDontSee('Đã đổi email đăng nhập. Đã gửi thư báo tới địa chỉ cũ.</');
        } finally {
            app()->instance('redis', $real);
            Redis::clearResolvedInstance('redis');
        }
    });

    expect($user->fresh()->email)->toBe($new);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Valkey went away');
});
