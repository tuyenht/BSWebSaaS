<?php

use App\Modules\Admin\Livewire\EmailTemplates;
use App\Modules\Admin\Models\MailTemplate;
use App\Modules\Admin\Notifications\MemberInvitationNotification;
use App\Modules\Admin\Notifications\ResetPasswordLinkNotification;
use App\Modules\Admin\Support\Mail\MailTemplateRenderer;
use App\Modules\Admin\Support\Mail\MailTemplates;
use App\Modules\Admin\Support\Mail\MailTemplateStore;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/*
 * «Mẫu thư» on a website's Email page: the website words its automatic mails itself (one copy per
 * template and language, only what it edited), above the platform's wording and the text in code.
 * A template's body is cleaned when saved and again when sent, and the variables are filled after
 * that cleaning: a link variable becomes the link's address only when it is a real web address, and
 * every other value is text. «Hệ thống» templates are the platform's; «Bắt buộc» ones keep the
 * variables the mail cannot work without and cannot be turned off.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_settings, site_modules, platform_mail_templates restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

/** @param list<string> $permissions */
function mailTplAdmin(array $permissions = ['settings.manage'], int $siteId = 1): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter($siteId, fn () => grantRole($admin, 'site-admin', $permissions));

    return $admin->fresh();
}

function mailTplModules(string ...$keys): void
{
    foreach ($keys as $key) {
        DB::connection('pgsql_admin')->table('site_modules')->insert(['site_id' => 1, 'module_key' => $key, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
}

function mailTplPlatform(string $key, string $locale, string $subject, string $body): void
{
    DB::connection('pgsql_admin')->table('platform_mail_templates')->insert([
        'template_key' => $key, 'locale' => $locale, 'subject' => $subject, 'body' => $body,
    ]);
}

function mailTplEnglish(int $siteId = 1): void
{
    DB::connection('pgsql_admin')->table('site_locales')->insert([
        'site_id' => $siteId, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

const MAIL_TPL_RESET_BODY = '<p>Chào {customer_name},</p><p><a href="{reset_url}">Đặt mật khẩu mới</a></p>';

it('keeps one website\'s templates away from another under RLS', function (): void {
    app(Tenancy::class)->enter(1, fn () => app(MailTemplateStore::class)->put('reset', 'vi', 'Mẫu của A', MAIL_TPL_RESET_BODY));

    app(Tenancy::class)->enter(2, function (): void {
        expect(MailTemplate::query()->count())->toBe(0)
            ->and(DB::table('mail_templates')->count())->toBe(0)
            ->and(DB::table('mail_templates')->where('site_id', 1)->update(['subject' => 'B đã sửa']))->toBe(0)
            ->and(app(MailTemplates::class)->stored('reset', 'vi'))->toBeNull();
        app(MailTemplateStore::class)->forgetAll();
        app(MailTemplateStore::class)->put('reset', 'vi', 'Mẫu của B', MAIL_TPL_RESET_BODY);
    });
    // a row for another website is refused by the policy itself, not only by the model
    expect(fn () => app(Tenancy::class)->enter(2, fn () => DB::table('mail_templates')->insert([
        'site_id' => 1, 'template_key' => 'verify', 'locale' => 'vi', 'subject' => 'x', 'body' => '<p>x</p>',
    ])))->toThrow(QueryException::class);

    expect(DB::table('mail_templates')->count())->toBe(0); // no website in context: nothing visible
    app(Tenancy::class)->enter(1, function (): void {
        expect(MailTemplate::query()->pluck('subject')->all())->toBe(['Mẫu của A'])
            ->and(app(MailTemplates::class)->stored('reset', 'vi')['subject'])->toBe('Mẫu của A');
    });
    expect(DB::connection('pgsql_admin')->table('mail_templates')->orderBy('site_id')->pluck('site_id')->all())->toBe([1, 2]);
});

it('takes the website\'s wording, then the platform\'s, then the default language\'s', function (): void {
    mailTplEnglish();
    mailTplPlatform('reset', 'vi', 'Nền tảng VI', MAIL_TPL_RESET_BODY);

    app(Tenancy::class)->enter(1, function (): void {
        $t = app(MailTemplates::class);
        expect($t->stored('reset', 'vi'))->toMatchArray(['subject' => 'Nền tảng VI', 'tier' => 'platform', 'locale' => 'vi'])
            ->and($t->stored('verify', 'vi'))->toBeNull();          // nothing stored: the text in code

        app(MailTemplateStore::class)->put('reset', 'vi', 'Website VI', MAIL_TPL_RESET_BODY);
        expect($t->stored('reset', 'vi'))->toMatchArray(['subject' => 'Website VI', 'tier' => 'site', 'locale' => 'vi'])
            // English asked, none stored in English: the default language's wording
            ->and($t->stored('reset', 'en'))->toMatchArray(['subject' => 'Website VI', 'locale' => 'vi']);
    });

    mailTplPlatform('reset', 'en', 'Platform EN', MAIL_TPL_RESET_BODY);
    app(Tenancy::class)->enter(1, function (): void {
        $t = app(MailTemplates::class);
        expect($t->stored('reset', 'en'))->toMatchArray(['subject' => 'Platform EN', 'tier' => 'platform', 'locale' => 'en']);
        app(MailTemplateStore::class)->put('reset', 'en', 'Website EN', MAIL_TPL_RESET_BODY);
        expect($t->stored('reset', 'en'))->toMatchArray(['subject' => 'Website EN', 'tier' => 'site', 'locale' => 'en']);
    });
});

it('never takes a website\'s copy of a «Hệ thống» template, and words it in the default language only', function (): void {
    mailTplEnglish();
    DB::connection('pgsql_admin')->table('mail_templates')->insert([
        'site_id' => 1, 'template_key' => 'ntf_contact', 'locale' => 'vi', 'subject' => 'Website đã sửa', 'body' => '<p>x</p>',
    ]);
    mailTplPlatform('ntf_contact', 'en', 'Platform EN', '<p>x</p>');

    app(Tenancy::class)->enter(1, function (): void {
        expect(app(MailTemplates::class)->stored('ntf_contact', 'vi'))->toBeNull()
            ->and(app(MailTemplates::class)->stored('ntf_contact', 'en'))->toBeNull();
    });
    mailTplPlatform('ntf_contact', 'vi', 'Platform VI', '<p>x</p>');
    app(Tenancy::class)->enter(1, fn () => expect(app(MailTemplates::class)->stored('ntf_contact', 'en'))
        ->toMatchArray(['subject' => 'Platform VI', 'tier' => 'platform', 'locale' => 'vi']));
});

it('saves an edited template with a plain subject, and refuses one without the variable it needs', function (): void {
    $admin = mailTplAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $c = Livewire::actingAs($admin)->test(EmailTemplates::class)
            ->call('open', 'reset')
            ->assertSet('editing', 'reset')
            ->assertSet('lang', 'vi');

        $c->call('save', 'Đặt lại', '<p>Không có liên kết nào</p>')
            ->assertHasErrors(['tplBody']);
        expect(MailTemplate::query()->count())->toBe(0);

        $c->call('save', str_repeat('a', 201), MAIL_TPL_RESET_BODY)->assertHasErrors(['tplSubject']);
        $c->call('save', '   ', MAIL_TPL_RESET_BODY)->assertHasErrors(['tplSubject']);
        expect(MailTemplate::query()->count())->toBe(0);

        $c->call('save', "<b>Đặt lại</b>\nmật khẩu {site_name}", MAIL_TPL_RESET_BODY.'<script>alert(1)</script><p onclick="x()">Hết</p>')
            ->assertHasNoErrors();
        $row = MailTemplate::query()->sole();
        expect($row->template_key)->toBe('reset')
            ->and($row->locale)->toBe('vi')
            ->and($row->subject)->toBe('Đặt lại mật khẩu {site_name}')
            ->and($row->body)->toContain('href="{reset_url}"')->not->toContain('script')->not->toContain('onclick');
    });
});

it('refuses to save a «Hệ thống» template, and never turns a «Bắt buộc» one off', function (): void {
    mailTplModules('contacts');
    $admin = mailTplAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailTemplates::class)
            ->call('open', 'ntf_contact')
            ->assertSet('editing', 'ntf_contact')
            ->call('save', 'Liên hệ mới', '<p>{customer_name}</p>')
            ->assertSet('toastTone', 'error')
            ->call('toggle', 'reset')
            ->call('toggle', 'ntf_contact')
            ->call('askReset', 'ntf_contact')
            ->assertSet('resetAsk', null);

        expect(MailTemplate::query()->count())->toBe(0)
            ->and(app(MailTemplates::class)->offKeys())->toBe([]);
    });
});

it('opens only the templates of the website\'s modules, and only in its languages', function (): void {
    $admin = mailTplAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailTemplates::class)
            ->assertSee(__('admin.mt_n_welcome'))
            ->assertDontSee(__('admin.mt_n_team_invite'))
            ->call('open', 'team_invite')
            ->assertSet('editing', null)
            ->call('open', 'reset')
            ->call('pickLang', 'en')               // not one of the website's languages
            ->assertSet('lang', 'vi');
    });
});

it('fills the variables after cleaning: a link only with a web address, every other value as text', function (): void {
    $r = app(MailTemplateRenderer::class);
    $body = '<p>Chào {customer_name},</p><p><a href="{reset_url}">Đặt lại</a></p><script>alert(1)</script><p><a href="{site_url}">Trang chủ</a> và {site_url}</p>';

    $html = $r->html($body, [
        '{customer_name}' => '<b>An</b> <script>x()</script>',
        '{reset_url}' => 'https://a.test/dat-lai?t=1&u=2',
        '{site_url}' => 'javascript:alert(1)',
    ], '#2050a0', '#ffffff');

    expect($html)
        ->toContain('href="https://a.test/dat-lai?t=1&amp;u=2"')     // the token in the address works
        ->toContain('&lt;b&gt;An&lt;/b&gt;')                         // a value is text, never markup
        ->not->toContain('<b>An')
        ->not->toContain('<script')
        ->not->toContain('href="javascript')
        ->not->toContain('{reset_url}')
        ->toContain('Trang chủ');                                      // an unsafe link keeps its words only

    // the subject is plain text: no tags, no line breaks, at most 200 characters
    expect($r->subject("Xin chào {customer_name}\n<i>nhé</i>", ['{customer_name}' => '<b>An</b>']))
        ->toBe('Xin chào <b>An</b> nhé')   // the wording's tags go; a value is shown as typed
        ->and(mb_strlen($r->subject('Chào {customer_name}', ['{customer_name}' => str_repeat('ă', 400)])))->toBe(200);
});

it('answers 403 to a member without the Email page\'s permission', function (): void {
    $viewer = mailTplAdmin(['media.view']);

    app(Tenancy::class)->enter(1, function () use ($viewer): void {
        Livewire::actingAs($viewer)->test(EmailTemplates::class)->assertForbidden();
    });
});

it('sends the password reset mail in the website\'s wording', function (): void {
    $user = User::factory()->create(['name' => 'An <b>Nguyễn</b>']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        app(MailTemplateStore::class)->put('reset', 'vi', 'Lấy lại mật khẩu {site_name}', MAIL_TPL_RESET_BODY);
        $mail = (new ResetPasswordLinkNotification('https://a.test/admin/reset/abc?email=an%40x.test'))->toMail($user);
        $html = (string) $mail->render();

        expect($mail->subject)->toBe('Lấy lại mật khẩu A')
            ->and($html)->toContain('href="https://a.test/admin/reset/abc?email=an%40x.test"')
            ->toContain('An &lt;b&gt;Nguyễn&lt;/b&gt;')
            ->not->toContain('{reset_url}');
    });
});

it('sends the address verification mail in the website\'s wording', function (): void {
    $user = User::factory()->unverified()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        app(MailTemplateStore::class)->put('verify', 'vi', 'Xác minh cho {site_name}', '<p>Bấm để xác minh {customer_email}</p><p><a href="{verify_url}">Xác minh</a></p>');
        $mail = (new VerifyEmail)->toMail($user);
        $html = (string) $mail->render();

        expect($mail->subject)->toBe('Xác minh cho A')
            ->and($html)->toContain(e($user->email))
            ->toMatch('/href="https?:\/\/[^"]*verify[^"]*"/')
            ->not->toContain('{verify_url}');
    });
});

it('sends the member invitation in the website\'s wording, its personal message as text', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        app(MailTemplateStore::class)->put('team_invite', 'vi', '{inviter_name} mời bạn vào {site_name}', '<p>Vai trò: {roles}</p><p>{invite_message}</p><p><a href="{accept_url}">Nhận lời mời</a></p><p>Hạn {expires_at}</p>');
        $mail = (new MemberInvitationNotification(
            acceptUrl: 'https://a.test/admin/invitations/token',
            siteName: 'Shop A',
            inviterName: 'Ngọc Anh',
            roles: [['name' => 'viewer', 'system' => true]],
            personalMessage: '[Sign in here](https://evil.test) <b>now</b>',
            expiresOn: '15/10/2026',
        ))->toMail(new AnonymousNotifiable);
        $html = (string) $mail->render();

        expect($mail->subject)->toBe('Ngọc Anh mời bạn vào Shop A')
            ->and($html)->toContain('href="https://a.test/admin/invitations/token"')
            ->toContain('Chỉ xem')
            ->toContain('&lt;b&gt;now&lt;/b&gt;')
            ->toContain('15/10/2026')
            ->not->toMatch('/<a[^>]+evil\.test/');
    });
});

it('sends no member invitation from a website that turned it off', function (): void {
    mailTplModules('users');
    $admin = mailTplAdmin();
    $note = new MemberInvitationNotification('https://a.test/x', 'A', 'B', [], null, '15/10/2026');

    app(Tenancy::class)->enter(1, function () use ($admin, $note): void {
        expect($note->via(new AnonymousNotifiable))->toBe(['mail']);
        Livewire::actingAs($admin)->test(EmailTemplates::class)->call('toggle', 'team_invite');
        expect(app(MailTemplates::class)->offKeys())->toBe(['team_invite'])
            ->and($note->via(new AnonymousNotifiable))->toBe([]);
    });
    app(Tenancy::class)->enter(2, fn () => expect($note->via(new AnonymousNotifiable))->toBe(['mail']));
});

it('puts a template back to the wording below it, one or all', function (): void {
    mailTplEnglish();
    $admin = mailTplAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $store = app(MailTemplateStore::class);
        $store->put('reset', 'vi', 'A', MAIL_TPL_RESET_BODY);
        $store->put('reset', 'en', 'B', MAIL_TPL_RESET_BODY);
        $store->put('welcome', 'vi', 'C', '<p>Chào</p>');

        $c = Livewire::actingAs($admin)->test(EmailTemplates::class)
            ->call('askReset', 'reset')->assertSet('resetAsk', 'reset')
            ->call('doReset');
        expect(MailTemplate::query()->pluck('template_key')->all())->toBe(['welcome']);

        $c->call('askReset', '*')->call('doReset');
        expect(MailTemplate::query()->count())->toBe(0);
    });
});

it('previews the typed template with sample values, cleaned', function (): void {
    $admin = mailTplAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $c = Livewire::actingAs($admin)->test(EmailTemplates::class)->call('open', 'reset');
        $out = [];
        $c->call('preview', 'Chào {customer_name}', MAIL_TPL_RESET_BODY.'<img src="x" onerror="alert(1)">')
            ->assertReturned(function (mixed $value) use (&$out): bool {
                $out = is_array($value) ? $value : [];

                return true;
            });

        expect($out['subject'] ?? null)->toBe('Chào '.__('admin.mt_s_customer_name'))
            ->and($out['html'] ?? '')->toContain('/dat-lai-mat-khau/7b1d')->not->toContain('onerror');
    });
});

it('never lets a variable ride inside another address, nor count as present from an image\'s words', function (): void {
    $r = app(MailTemplateRenderer::class);
    $sneaky = $r->clean('<p><a href="https://evil.test/r?u={reset_url}">Đặt lại</a> <a href="https://evil.test/{reset_url}">Mở</a></p>');
    $html = $r->html($sneaky, ['{reset_url}' => 'https://a.test/dat-lai/abc'], '#2050a0', '#ffffff');
    expect($html)->not->toContain('evil.test')->not->toContain('a.test/dat-lai')->toContain('Đặt lại');
    // only a link variable may be a link's address: a value the public typed never becomes one
    $typed = $r->html('<p><a href="{customer_name}">Mở</a></p>', ['{customer_name}' => 'https://evil.test/phish'], '#2050a0', '#ffffff');
    expect($typed)->not->toContain('evil.test')->not->toContain('<a')->toContain('Mở');

    $admin = mailTplAdmin();
    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailTemplates::class)
            ->call('open', 'reset')
            ->call('save', 'Đặt lại', '<p><img src="https://a.test/x.png" alt="{reset_url}"><a href="https://evil.test/login">Đặt lại</a></p>')
            ->assertHasErrors(['tplBody'])
            ->call('save', 'Đặt lại', '<p><a href="https://evil.test/?u={reset_url}">Đặt lại</a></p>')
            ->assertHasErrors(['tplBody'])
            ->call('save', 'Đặt lại', '<p>Bấm href="{reset_url}" nhé</p>')         // words, not a link
            ->assertHasErrors(['tplBody'])
            ->call('save', 'Đặt lại', '<p>Chào</p><p><a href="{reset_url}"> </a></p>')   // a link nobody sees
            ->assertHasErrors(['tplBody'])
            ->call('save', 'Đặt lại', '<p>Chào</p><p><a href="{reset_url}">{customer_name}</a></p>')   // words that may be empty
            ->assertHasErrors(['tplBody']);
        expect(MailTemplate::query()->count())->toBe(0);
    });
});

it('keeps a value\'s "<" in the subject as text', function (): void {
    expect(app(MailTemplateRenderer::class)->subject('Lời mời từ {inviter_name} tới {site_name}', ['{inviter_name}' => 'Anh <3 Em', '{site_name}' => 'A']))
        ->toBe('Lời mời từ Anh <3 Em tới A');
});

it('turns a template back on, and resets only the templates the website sees', function (): void {
    mailTplModules('users');
    $admin = mailTplAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        app(MailTemplateStore::class)->put('reset', 'vi', 'A', MAIL_TPL_RESET_BODY);
        $c = Livewire::actingAs($admin)->test(EmailTemplates::class)->call('toggle', 'team_invite');
        expect(app(MailTemplates::class)->offKeys())->toBe(['team_invite']);
        $c->call('toggle', 'team_invite');
        expect(app(MailTemplates::class)->offKeys())->toBe([]);
    });

    // without the members module the invitation is not the website's: its copy stays, «tất cả» skips it
    DB::connection('pgsql_admin')->table('site_modules')->delete();
    app()->forgetScopedInstances();   // a new request: the website's modules are read again
    DB::connection('pgsql_admin')->table('mail_templates')->insert(['site_id' => 1, 'template_key' => 'team_invite', 'locale' => 'vi', 'subject' => 'B', 'body' => '<p><a href="{accept_url}">x</a></p>']);
    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailTemplates::class)
            ->call('askReset', 'team_invite')->assertSet('resetAsk', null)
            ->call('askReset', '*')->call('doReset');
        expect(MailTemplate::query()->pluck('template_key')->all())->toBe(['team_invite']);
    });
});

it('holds test mails at the per-minute cap, sending nothing', function (): void {
    Mail::fake();
    $admin = mailTplAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $key = 'mail-test-send:1:'.$admin->id;
        RateLimiter::clear($key);
        foreach (range(1, EmailTemplates::TEST_SENDS_PER_MINUTE) as $_) {
            RateLimiter::hit($key, 60);
        }
        Livewire::actingAs($admin)->test(EmailTemplates::class)
            ->call('open', 'reset')
            ->call('sendTest', 'Đặt lại', MAIL_TPL_RESET_BODY)
            ->assertSet('toastTone', 'error');
        RateLimiter::clear($key);
    });
    Mail::assertNothingSent();
    Mail::assertNothingOutgoing();
});
