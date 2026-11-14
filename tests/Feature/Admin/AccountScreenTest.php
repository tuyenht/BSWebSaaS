<?php

use App\Modules\Admin\Livewire\Account;
use App\Modules\Admin\Notifications\TwoFactorDisabledNotification;
use App\Modules\Identity\Models\SocialIdentity;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * The member's own account page: profile, password, two-step sign-in, linked sign-in accounts,
 * display preferences and notification choices. Every change is the signed-in member's own,
 * staff signed in for support only look, and the sensitive ones ask for the password again,
 * are rate limited and go on record.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    fakePwnedVerifier();
    app()->setLocale('vi');
});

function accountMember(array $permissions = ['entries.view'], array $attributes = []): User
{
    $user = User::factory()->create(['password' => Hash::make('old-password-123'), ...$attributes]);
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', $permissions));
    RateLimiter::clear('account-reauth:'.$user->id);
    RateLimiter::clear('account-2fa:'.$user->id);

    return $user->fresh();
}

/** @return Livewire\Features\SupportTesting\Testable */
function accountPage(User $user): mixed
{
    return Livewire::actingAs($user)->test(Account::class);
}

/** The member's own audit rows for $action (the shared test database keeps older runs' rows). */
function auditCount(string $action, User $user): int
{
    return DB::connection('pgsql_admin')->table('audit_logs')->where('action', $action)->where('actor_id', $user->id)->count();
}

function withTwoFactor(User $user): User
{
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();
    $service->enable($user, $secret, (new Google2FA)->getCurrentOtp($secret));

    return $user->fresh();
}

it('opens at /admin/account and sends the old addresses there', function (): void {
    $user = accountMember();

    $this->actingAs($user)->get('http://a.test/admin/account')->assertOk()
        ->assertSee('Tài khoản của tôi')
        ->assertSee('Hồ sơ')->assertSee('Bảo mật')->assertSee('Tài khoản liên kết')->assertSee('Tùy chọn');
    $this->actingAs($user)->get('http://a.test/admin/settings')->assertRedirect('/admin/account');
    $this->actingAs($user)->get('http://a.test/admin/settings/security')->assertRedirect('/admin/account?tab=security');
});

it('links its own styles on the account page only, in the head', function (): void {
    $user = accountMember();
    // Name each Vite entry a page asks for (the suite's stub draws nothing).
    $this->swap(Vite::class, new class extends Vite
    {
        public function __invoke($entrypoints, $buildDirectory = null): HtmlString
        {
            return new HtmlString('<link data-entry="'.implode(' ', (array) $entrypoints).'">');
        }
    });

    // Each request starts clean, as Octane starts each one (Livewire puts an @assets block in a head once).
    app('livewire')->flushState();
    $account = $this->actingAs($user)->get('http://a.test/admin/account?tab=security')->assertOk()->getContent();
    app('livewire')->flushState();
    $dashboard = $this->actingAs($user)->get('http://a.test/admin')->assertOk()->getContent();

    expect(Str::before((string) $account, '</head>'))->toContain('<link data-entry="resources/css/admin-account.css">')
        ->and((string) $dashboard)->not->toContain('admin-account.css')
        ->and((string) file_get_contents(resource_path('css/app.css')))->not->toContain('admin-account');
});

it('keeps an unknown tab on the profile', function (): void {
    $user = accountMember();

    $this->actingAs($user)->get('http://a.test/admin/account?tab=nope')->assertOk();
    app(Tenancy::class)->enter(1, fn () => accountPage($user)->set('tab', 'nope')->assertSet('tab', 'profile')
        ->set('tab', 'linked')->assertSet('tab', 'linked'));
});

it('checks the name when the member leaves the field and saves it trimmed', function (): void {
    $user = accountMember();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        accountPage($user)->set('profileName', '   ')
            ->assertHasErrors(['profileName'])
            ->assertSee('Nhập họ tên để đồng nghiệp nhận ra bạn');

        accountPage($user)->set('profileName', '  Nguyễn Ngọc Anh ')->call('saveProfile')
            ->assertHasNoErrors()
            ->assertSet('savedName', 'Nguyễn Ngọc Anh');
    });

    expect($user->fresh()->name)->toBe('Nguyễn Ngọc Anh');
});

it('puts the board wording on each password mistake, in the board order', function (): void {
    $user = accountMember();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = accountPage($user)->call('changePassword');
        expect($page->errors()->get('current_password'))->toBe(['Nhập mật khẩu bạn đang dùng.'])
            ->and($page->errors()->get('password'))->toBe(['Nhập mật khẩu mới, ít nhất 10 ký tự.'])
            ->and($page->errors()->get('password_confirmation'))->toBe(['Nhập lại mật khẩu mới để chắc là bạn gõ đúng.']);

        $page = accountPage($user)->set('current_password', 'old-password-123')->set('password', 'short')->set('password_confirmation', 'short')->call('changePassword');
        expect($page->errors()->get('password'))->toBe(['Mật khẩu mới cần ít nhất 10 ký tự — còn thiếu 5 ký tự.']);

        $page = accountPage($user)->set('current_password', 'old-password-123')->set('password', 'old-password-123')->set('password_confirmation', 'old-password-123')->call('changePassword');
        expect($page->errors()->get('password'))->toBe(['Mật khẩu mới cần khác mật khẩu hiện tại.']);
    });

    expect(Hash::check('old-password-123', $user->fresh()->password))->toBeTrue();
});

it('changes the password on record and keeps this session signed in', function (): void {
    $user = accountMember();
    session()->start();
    $before = session()->getId();

    app(Tenancy::class)->enter(1, fn () => accountPage($user)
        ->set('current_password', 'old-password-123')
        ->set('password', 'mua-ca-phe-luc-7-gio')
        ->set('password_confirmation', 'mua-ca-phe-luc-7-gio')
        ->call('changePassword')
        ->assertHasNoErrors()
        ->assertSet('passwordChanged', true)
        ->assertSet('current_password', ''));

    expect(Hash::check('mua-ca-phe-luc-7-gio', $user->fresh()->password))->toBeTrue()
        ->and(session('password_hash_web'))->toBe($user->fresh()->getAuthPassword())
        ->and(session()->getId())->not->toBe($before)
        ->and(auth()->id())->toBe($user->id)
        ->and(auditCount('auth.password.changed', $user))->toBe(1);

    // Still signed in on the next page: the session's saved hash matches the new password.
    $this->get('http://a.test/admin/account')->assertOk();
});

it('pauses the current-password check after five wrong tries', function (): void {
    $user = accountMember();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        foreach (range(1, 5) as $_) {
            accountPage($user)->set('current_password', 'wrong-password')->set('password', 'mua-ca-phe-luc-7-gio')
                ->set('password_confirmation', 'mua-ca-phe-luc-7-gio')->call('changePassword')
                ->assertHasErrors(['current_password']);
        }

        // Even the right password waits now.
        $page = accountPage($user)->set('current_password', 'old-password-123')->set('password', 'mua-ca-phe-luc-7-gio')
            ->set('password_confirmation', 'mua-ca-phe-luc-7-gio')->call('changePassword');
        expect($page->errors()->first('current_password'))->toContain('Bạn đã thử sai nhiều lần');
    });

    expect(Hash::check('old-password-123', $user->fresh()->password))->toBeTrue();
});

it('turns two-step sign-in on in three steps, on record, warns the member and shows the codes once', function (): void {
    Notification::fake();
    $user = accountMember();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = accountPage($user)->set('tab', 'security')
            ->call('openDialog', 'enable')->assertSet('dialog', 'enable')->assertSee('Bật xác thực 2 lớp?')
            ->set('confirmPassword', 'old-password-123')->call('startTwoFactor')
            ->assertHasNoErrors()->assertSet('dialogOpen', false)->assertSet('tfaStep', 1)
            ->assertSee('Bước 1/3 · Quét mã')
            ->call('twoFactorNext')->assertSet('tfaStep', 2);

        $page->set('tfaCode', '12 34')->call('confirmTwoFactor')->assertHasErrors(['tfaCode'])
            ->assertSee('Mã gồm đúng 6 số');
        $page->set('tfaCode', '000000')->call('confirmTwoFactor')->assertHasErrors(['tfaCode']);

        $secret = (string) session('account.tfa_secret');
        $page->set('tfaCode', (new Google2FA)->getCurrentOtp($secret))->call('confirmTwoFactor')
            ->assertHasNoErrors()->assertSet('tfaStep', 3)
            ->assertCount('recoveryCodes', 8)
            ->call('finishTwoFactor')->assertSet('tfaStep', 0)->assertSet('recoveryCodes', []);
    });

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull()
        ->and(session('account.tfa_secret'))->toBeNull()
        ->and(auditCount('auth.2fa.enabled', $user))->toBe(1);
    Notification::assertSentTo($user, TwoFactorDisabledNotification::class, function (TwoFactorDisabledNotification $notice) use ($user): bool {
        $row = $notice->toSiteDatabase($user);

        return $row['kind'] === 'security.two_factor_disabled' && ($row['data']['sentence'] ?? null) === 'enabled'
            && $notice->toMail($user)->subject === 'Xác thực 2 lớp đã được bật';
    });
});

it('starts two-step setup only after the password is typed again', function (): void {
    $user = accountMember();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        // Straight to the setup, without the dialog: nothing starts.
        accountPage($user)->set('confirmPassword', 'old-password-123')->call('startTwoFactor')->assertSet('tfaStep', 0);

        // The dialog with no password, then a wrong one: refused, still nothing started.
        accountPage($user)->call('openDialog', 'enable')
            ->call('startTwoFactor')->assertHasErrors(['confirmPassword'])->assertSet('tfaStep', 0)
            ->set('confirmPassword', 'wrong-password')->call('startTwoFactor')
            ->assertHasErrors(['confirmPassword'])->assertSee('Mật khẩu chưa đúng')->assertSet('tfaStep', 0)->assertSet('dialogOpen', true);
    });

    expect(session('account.tfa_secret'))->toBeNull();
});

it('pauses the password check of the two-step setup after five wrong tries', function (): void {
    $user = accountMember();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = accountPage($user)->call('openDialog', 'enable');
        foreach (range(1, 5) as $_) {
            $page->set('confirmPassword', 'wrong-password')->call('startTwoFactor')->assertHasErrors(['confirmPassword']);
        }

        // Even the right password waits now.
        $page->set('confirmPassword', 'old-password-123')->call('startTwoFactor')->assertSet('tfaStep', 0);
        expect($page->errors()->first('confirmPassword'))->toContain('Bạn đã thử sai nhiều lần');
    });

    expect(session('account.tfa_secret'))->toBeNull();
});

it('drops a two-step setup left open for more than ten minutes', function (): void {
    $user = accountMember();

    app(Tenancy::class)->enter(1, fn () => accountPage($user)->call('openDialog', 'enable')->set('confirmPassword', 'old-password-123')
        ->call('startTwoFactor')->assertSet('tfaStep', 1));
    expect(session('account.tfa_secret'))->toBeString();

    // Nine minutes later the setup is still there; past ten minutes the page opens without it.
    $this->travel(9)->minutes();
    app(Tenancy::class)->enter(1, fn () => accountPage($user)->assertSet('tfaStep', 1));
    $this->travel(2)->minutes();
    app(Tenancy::class)->enter(1, fn () => accountPage($user)->assertSet('tfaStep', 0));

    expect(session('account.tfa_secret'))->toBeNull()
        ->and($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

it('cancels a started setup without turning anything on', function (): void {
    $user = accountMember();

    app(Tenancy::class)->enter(1, fn () => accountPage($user)->call('openDialog', 'enable')->set('confirmPassword', 'old-password-123')
        ->call('startTwoFactor')->assertSet('tfaStep', 1)->call('cancelTwoFactor')->assertSet('tfaStep', 0));

    expect(session('account.tfa_secret'))->toBeNull()
        ->and($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

it('makes new recovery codes only with the password, on record', function (): void {
    $user = withTwoFactor(accountMember());
    $old = $user->two_factor_recovery_codes;

    app(Tenancy::class)->enter(1, function () use ($user): void {
        // Without the dialog nothing happens.
        accountPage($user)->set('confirmPassword', 'old-password-123')->call('regenerateRecoveryCodes')->assertSet('recoveryCodes', []);

        accountPage($user)->call('openDialog', 'regen')->assertSet('dialog', 'regen')
            ->set('confirmPassword', 'wrong-password')->call('regenerateRecoveryCodes')
            ->assertHasErrors(['confirmPassword'])
            ->set('confirmPassword', 'old-password-123')->call('regenerateRecoveryCodes')
            ->assertHasNoErrors()->assertSet('dialog', 'codes')->assertCount('recoveryCodes', 8);
    });

    expect($user->fresh()->two_factor_recovery_codes)->not->toBe($old)
        ->and(auditCount('auth.2fa.recovery_regenerated', $user))->toBe(1);
});

it('turns two-step sign-in off only with the password, on record, and warns the member', function (): void {
    Notification::fake();
    $user = withTwoFactor(accountMember());

    app(Tenancy::class)->enter(1, fn () => accountPage($user)->call('openDialog', 'disable')
        ->set('confirmPassword', '')->call('disableTwoFactor')
        ->assertHasErrors(['confirmPassword'])
        ->set('confirmPassword', 'old-password-123')->call('disableTwoFactor')
        ->assertHasNoErrors()->assertSet('dialogOpen', false));

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull()
        ->and($user->fresh()->two_factor_secret)->toBeNull()
        ->and(auditCount('auth.2fa.disabled', $user))->toBe(1);
    Notification::assertSentTo($user, TwoFactorDisabledNotification::class);
});

it('opens no two-step dialog that does not fit whether two-step sign-in is on', function (): void {
    $off = accountMember();
    $on = withTwoFactor(accountMember());

    app(Tenancy::class)->enter(1, function () use ($off, $on): void {
        accountPage($on)->call('openDialog', 'enable')->assertSet('dialogOpen', false);
        accountPage($off)->call('openDialog', 'disable')->assertSet('dialogOpen', false)
            ->call('openDialog', 'regen')->assertSet('dialogOpen', false)
            ->call('openDialog', 'anything')->assertSet('dialogOpen', false);
    });
});

it('unlinks the member\'s own sign-in account, on record, and nobody else\'s', function (): void {
    $user = accountMember();
    $other = accountMember();
    SocialIdentity::query()->create(['user_id' => $user->id, 'provider' => 'google', 'sub' => 'g-'.$user->ulid, 'email' => $user->email]);
    SocialIdentity::query()->create(['user_id' => $other->id, 'provider' => 'facebook', 'sub' => 'f-'.$other->ulid, 'email' => $other->email]);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        accountPage($user)->set('tab', 'linked')->assertSee('Đã liên kết')
            ->call('openDialog', 'unlink', 'facebook')->assertSet('dialogOpen', false)
            ->call('openDialog', 'unlink', 'google')->assertSet('dialogProvider', 'google')
            ->call('unlinkProvider')->assertSet('dialogOpen', false);
    });

    expect($user->socialIdentities()->count())->toBe(0)
        ->and($other->socialIdentities()->count())->toBe(1)
        ->and(DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'auth.social.unlinked')->where('actor_id', $user->id)->value('meta'))->toContain('google');
});

it('keeps the last linked sign-in account of a member who has no password', function (): void {
    $user = accountMember();
    // No password on record (written past the hashing cast): the link is the only way in.
    User::query()->whereKey($user->id)->update(['password' => '']);
    $user = $user->fresh();
    SocialIdentity::query()->create(['user_id' => $user->id, 'provider' => 'google', 'sub' => 'g-'.$user->ulid, 'email' => $user->email]);

    app(Tenancy::class)->enter(1, fn () => accountPage($user)->set('tab', 'linked')
        ->call('openDialog', 'unlink', 'google')->assertSet('dialogProvider', 'google')
        ->call('unlinkProvider')->assertSet('dialogOpen', false));

    expect($user->getAuthPassword())->toBe('')
        ->and($user->socialIdentities()->count())->toBe(1)
        ->and(auditCount('auth.social.unlinked', $user))->toBe(0);
});

it('saves the member\'s language and uses it when the session has none', function (): void {
    $user = accountMember();

    app(Tenancy::class)->enter(1, fn () => accountPage($user)->call('setLocale', 'en')->assertRedirect(route('admin.account', ['tab' => 'prefs']))
        ->call('setLocale', 'xx'));

    expect($user->fresh()->preferences['locale'] ?? null)->toBe('en');

    session()->forget('locale');
    $this->actingAs($user->fresh())->get('http://a.test/admin/account')->assertOk()->assertSee('My account');
});

it('lets the top-bar language link change this session only, never the saved language', function (): void {
    // A plain GET link can be fired from another site (an <img>), so it must not write the account:
    // the saved language changes only from the Tùy chọn tab.
    $user = accountMember(attributes: ['preferences' => ['locale' => 'en']]);

    $this->actingAs($user)->get('http://a.test/admin/locale?set=ja')->assertRedirect()->assertSessionHas('locale', 'ja');
    $this->actingAs($user)->get('http://a.test/admin/locale?set=xx')->assertRedirect();

    expect($user->fresh()->preferences)->toBe(['locale' => 'en']);
});

it('saves scheme, text size and motion as the member\'s own and paints them on the page', function (): void {
    $user = accountMember();

    app(Tenancy::class)->enter(1, fn () => accountPage($user)
        ->call('setScheme', 'auto')->assertDispatched('admin-chrome-changed')
        ->call('setTextSize', 'xl')
        ->call('setMotion', 'reduce')
        ->call('setScheme', 'neon')->call('setTextSize', 'huge')->call('setMotion', 'none'));

    expect($user->fresh()->preferences['theme'])->toMatchArray(['scheme' => 'auto', 'text_size' => 'xl', 'motion' => 'reduce']);

    $html = (string) $this->actingAs($user->fresh())->get('http://a.test/admin/account')->assertOk()->getContent();
    preg_match('/<html[^>]*>/', $html, $tag);
    expect($tag[0])->toContain('data-text-size="xl"')->toContain('data-motion="reduce"')->toContain('data-scheme-auto="true"');

    app(Tenancy::class)->enter(1, fn () => accountPage($user->fresh())->call('resetTextSize'));
    expect($user->fresh()->preferences['theme'])->not->toHaveKey('text_size');
});

it('changes only the notifications the member receives, never a locked one or an unsent channel', function (): void {
    $user = accountMember(['entries.view', 'entries.review']);

    app(Tenancy::class)->enter(1, fn () => accountPage($user)->set('tab', 'prefs')
        ->assertSee('Có bài chờ bạn duyệt')
        ->assertDontSee('Khách liên hệ mới')
        ->call('setNotification', 'workflow.pending_review', 'in_app', false)
        ->call('setNotification', 'workflow.pending_review', 'mail', true)       // no e-mail for this kind
        ->call('setNotification', 'security.two_factor_disabled', 'in_app', false) // locked
        ->call('setNotification', 'lead.new', 'in_app', false)                  // not received
        ->call('setNotification', 'system.domain_problem', 'mail', false)        // domains.manage only
        ->call('setNotification', 'made.up', 'in_app', false));

    expect($user->fresh()->preferences['notifications'] ?? null)->toBe(['workflow.pending_review' => ['in_app' => false]]);
});

it('lets a member who manages domains turn the domain e-mail off', function (): void {
    $user = accountMember(['entries.view', 'domains.manage']);

    app(Tenancy::class)->enter(1, fn () => accountPage($user)->call('setNotification', 'system.domain_problem', 'mail', false));

    expect($user->fresh()->preferences['notifications']['system.domain_problem'] ?? null)->toBe(['mail' => false]);
});

it('only lets support staff look: every change is refused during a support session', function (string $method, array $args): void {
    $user = withTwoFactor(accountMember(['entries.view', 'domains.manage']));
    $before = $user->getAttributes();

    $session = app('session')->driver();
    request()->setLaravelSession($session);
    auth()->login($user);
    app(SupportSession::class)->start($user, $user, 1, 'Kiểm tra', now()->addMinutes(30));
    // Livewire's test requests skip the middleware, so nothing starts the session on them;
    // hand each one this session, as StartSession does on a real request.
    app()->rebinding('request', function ($app, Request $request) use ($session): void {
        if (! $request->hasSession()) {
            $request->setLaravelSession($session);
        }
    });

    app(Tenancy::class)->enter(1, function () use ($user, $method, $args): void {

        accountPage($user)->assertSee('Chỉ xem.')
            ->set('profileName', 'Đổi Trộm')->set('profileTitle', 'Giả danh')->set('profilePhone', '0901 234 567')
            ->set('current_password', 'old-password-123')->set('password', 'mua-ca-phe-luc-7-gio')->set('password_confirmation', 'mua-ca-phe-luc-7-gio')
            ->set('confirmPassword', 'old-password-123')
            ->call($method, ...$args)
            ->assertForbidden();
    });

    expect($user->fresh()->getAttributes())->toBe($before);
})->with([
    'save profile' => ['saveProfile', []],
    'change password' => ['changePassword', []],
    'start two-step' => ['startTwoFactor', []],
    'open a dialog' => ['openDialog', ['disable']],
    'regenerate codes' => ['regenerateRecoveryCodes', []],
    'turn two-step off' => ['disableTwoFactor', []],
    'unlink' => ['unlinkProvider', []],
    'language' => ['setLocale', ['en']],
    'scheme' => ['setScheme', ['dark']],
    'text size' => ['setTextSize', ['xl']],
    'reset text size' => ['resetTextSize', []],
    'motion' => ['setMotion', ['reduce']],
    'notification' => ['setNotification', ['system.domain_problem', 'mail', false]],
]);

it('refuses a visitor who is not signed in', function (): void {
    $this->get('http://a.test/admin/account')->assertRedirect();
});
