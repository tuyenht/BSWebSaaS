<?php

use App\Modules\Admin\Livewire\EmailSettings;
use App\Modules\Admin\Livewire\Forms\FormEditor;
use App\Modules\Admin\Support\MailHostGuard;
use App\Modules\Admin\Support\MailNotifyTo;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Forms\Models\Form;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * «Gửi thư» › «Tên và địa chỉ người gửi» and «Gửi thông báo tới»: the sender line as a customer
 * sees it, the sending address typed (its own service) or locked (a signed-in Google / Microsoft
 * mailbox, which sends as itself), and the notice addresses a NEW form starts with — a form that
 * exists keeps its own recipients, an empty list included.
 */

beforeEach(function (): void {
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    app()->instance(MailHostGuard::class, new MailHostGuard(fn (string $host): array => ['93.184.216.34']));
    app(PlatformSettings::class)->put('email', 'config', [
        'method' => 'smtp_password',
        'from_address' => 'no-reply@nen-tang.test',
        'smtp' => ['host' => 'smtp.nen-tang.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'relay', 'password' => SecretCipher::encrypt('pw')],
    ]);
});

afterEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate platform_settings restart identity cascade');
});

function esnAdmin(): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    return $admin->fresh();
}

/** @param array<string, mixed> $cfg */
function esnSetup(array $cfg): void
{
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('email', 'config', $cfg));
}

/** @return array<string, mixed> */
function esnGoogle(string $email = 'hop@anvinh.vn'): array
{
    return ['provider' => 'google', 'method' => 'oauth2', 'from_address' => 'cu@khac.vn',
        'oauth' => ['provider' => 'google', 'client_id' => 'cid', 'client_secret' => SecretCipher::encrypt('s'), 'refresh_token' => SecretCipher::encrypt('r'), 'email' => $email]];
}

it('shows the sender line a customer sees for each outbox', function (): void {
    $admin = esnAdmin();
    $site = Site::query()->findOrFail(1)->name;

    app(Tenancy::class)->enter(1, function () use ($admin, $site): void {
        $page = Livewire::actingAs($admin)->test(EmailSettings::class);
        // the platform's outbox: the website's name, the platform's address
        expect($page->viewData('sender'))->toMatchArray(['name' => $site, 'address' => 'no-reply@nen-tang.test', 'edit' => false, 'locked' => '']);
        $page->assertSeeHtml('data-sender-preview')->assertSee($site);

        // a mailbox not signed in yet has no address to show
        $page->set('provider', 'google')->set('fromName', 'Cơ Khí An Vinh');
        expect($page->viewData('sender'))->toMatchArray(['name' => 'Cơ Khí An Vinh', 'address' => '']);
        $page->assertSee(__('admin.em_sender_no_addr'));

        // its own service: the part typed before @ and its sending domain; none typed = no-reply@
        $page->set('provider', 'other')->set('relay', 'sendgrid')->set('sendDomain', 'AnVinh.vn');
        expect($page->viewData('sender'))->toMatchArray(['address' => 'no-reply@anvinh.vn', 'edit' => true, 'domain' => 'anvinh.vn']);
        $page->set('fromLocal', 'lienhe');
        expect($page->viewData('sender')['address'])->toBe('lienhe@anvinh.vn');
        $page->assertSee('@anvinh.vn');

        // «Email khác» sends from its login when nothing is typed
        $page->set('relay', 'smtp')->set('fromLocal', '')->set('smtpUsername', 'info@congty.vn');
        expect($page->viewData('sender'))->toMatchArray(['address' => 'info@congty.vn', 'defaultLocal' => 'info', 'domain' => 'congty.vn']);
    });
});

it('locks the sending address of a signed-in Google mailbox to that mailbox, and the mailer sends as it', function (): void {
    esnSetup(esnGoogle());
    $admin = esnAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $page = Livewire::actingAs($admin)->test(EmailSettings::class)->assertSet('provider', 'google');
        expect($page->viewData('sender'))->toMatchArray(['address' => 'hop@anvinh.vn', 'locked' => 'hop@anvinh.vn', 'edit' => false]);
        $page->assertSeeHtml('data-from-locked')->assertDontSeeHtml('id="em-from-local"');

        // a sending address forced from the page is not what a mailbox stores
        $page->set('fromAddress', 'gia@mao.vn')->set('fromLocal', 'gia')->set('fromName', 'An Vinh')->call('save')->assertHasNoErrors();
        expect(app(SettingsRepository::class)->get('email')['from_address'] ?? null)->toBe('cu@khac.vn');

        Cache::flush();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'at', 'expires_in' => 3600])]);
        expect(app(TenantMailer::class)->apply())->toBeTrue()
            ->and(config('mail.from.address'))->toBe('hop@anvinh.vn');
    });
});

it('stores the sending address of its own service as the part typed and its domain, and refuses a bad part', function (): void {
    esnSetup(['provider' => 'other', 'method' => 'smtp_password', 'relay' => 'sendgrid', 'send_domain' => 'anvinh.vn', 'from_address' => 'ban-hang@anvinh.vn',
        'smtp' => ['host' => 'smtp.sendgrid.net', 'port' => 587, 'encryption' => 'tls', 'username' => 'apikey', 'password' => SecretCipher::encrypt('SG.key')]]);
    $admin = esnAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $page = Livewire::actingAs($admin)->test(EmailSettings::class)->assertSet('fromLocal', 'ban-hang');
        $page->set('fromLocal', 'a..b')->call('save')->assertHasErrors(['fromLocal']);
        $page->assertSee(__('admin.em_from_local_invalid'));

        $page->set('fromLocal', 'lienhe')->call('save')->assertHasNoErrors();
        expect(app(SettingsRepository::class)->get('email')['from_address'])->toBe('lienhe@anvinh.vn');

        $page->set('fromLocal', '')->call('save')->assertHasNoErrors();
        expect(app(SettingsRepository::class)->get('email')['from_address'])->toBeNull();
    });
});

it('words a reply address that is not an email the board way', function (): void {
    $admin = esnAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('replyTo', 'lienhe@')->call('save')
            ->assertHasErrors(['replyTo'])
            ->assertSee(__('admin.em_reply_invalid'));
    });
});

it('adds notice addresses as typed or pasted, names the bad ones and saves only a good list', function (): void {
    $admin = esnAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $page = Livewire::actingAs($admin)->test(EmailSettings::class)
            ->call('addNotify', 'kinhdoanh@anvinh.vn, KinhDoanh@anvinh.vn; sai@dia-chi')
            ->assertSet('notifyTo', ['kinhdoanh@anvinh.vn', 'sai@dia-chi'])
            ->assertHasErrors(['notifyTo']);
        expect($page->errors()->first('notifyTo'))->toBe(__('admin.em_notify_invalid', ['list' => 'sai@dia-chi']));
        $page->assertSeeHtml('data-bad');

        // the save bar counts it, and «Lưu» refuses while a bad one is left
        expect($page->viewData('changes'))->toBeGreaterThan(0);
        $page->call('save')->assertHasErrors(['notifyTo']);
        expect(app(SettingsRepository::class)->get(MailNotifyTo::GROUP, MailNotifyTo::KEY))->toBeNull();

        $page->call('removeNotify', 1)->assertHasNoErrors()->call('addNotify', 'chu@anvinh.vn')
            ->call('save')->assertHasNoErrors();
        expect(app(SettingsRepository::class)->get(MailNotifyTo::GROUP, MailNotifyTo::KEY))->toBe(['emails' => ['kinhdoanh@anvinh.vn', 'chu@anvinh.vn']])
            // the notice list is written on its own: the outbox setup is not touched
            ->and(app(SettingsRepository::class)->get('email'))->toBeNull();

        Livewire::actingAs($admin)->test(EmailSettings::class)->assertSet('notifyTo', ['kinhdoanh@anvinh.vn', 'chu@anvinh.vn'])
            ->call('resetChanges')->assertSet('notifyTo', ['kinhdoanh@anvinh.vn', 'chu@anvinh.vn']);
    });
});

it('stops at the most addresses a form may notify', function (): void {
    $admin = esnAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $many = implode(',', array_map(fn (int $n): string => "nv{$n}@anvinh.vn", range(1, MailNotifyTo::MAX + 2)));
        $page = Livewire::actingAs($admin)->test(EmailSettings::class)->call('addNotify', $many);
        expect($page->get('notifyTo'))->toHaveCount(MailNotifyTo::MAX)
            ->and($page->errors()->first('notifyTo'))->toBe(__('admin.em_notify_max', ['max' => MailNotifyTo::MAX]));
    });
});

it('never takes the notice list straight from the browser', function (): void {
    $admin = esnAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        expect(fn () => Livewire::actingAs($admin)->test(EmailSettings::class)->set('notifyTo', ['x@y.vn']))
            ->toThrow(Exception::class);
    });
});

it('suggests info@ and contact@ on the sending domain, else the website\'s own domain, never on a public mailbox', function (): void {
    config(['tenancy.base_domains' => ['test']]);
    $admin = esnAdmin();

    // on a platform subdomain alone there is no domain of its own to suggest
    app(Tenancy::class)->enter(1, function () use ($admin): void {
        expect(Livewire::actingAs($admin)->test(EmailSettings::class)->viewData('sender')['suggestions'])->toBe([]);
    });

    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'a.test')->update(['is_primary' => false]);
    DB::connection('pgsql_admin')->table('domains')->insert(['site_id' => 1, 'hostname' => 'www.anvinh.vn', 'kind' => 'custom', 'is_primary' => true, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    Cache::flush();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $page = Livewire::actingAs($admin)->test(EmailSettings::class);
        expect($page->viewData('sender')['suggestions'])->toBe(['info@anvinh.vn', 'contact@anvinh.vn']);

        $page->call('addNotify', 'info@anvinh.vn');
        expect($page->viewData('sender')['suggestions'])->toBe(['contact@anvinh.vn']);

        $page->set('provider', 'other')->set('relay', 'resend')->set('sendDomain', 'cokhi.vn');
        expect($page->viewData('sender')['suggestions'])->toBe(['info@cokhi.vn', 'contact@cokhi.vn']);
    });

    esnSetup(esnGoogle('chu.anvinh@gmail.com'));
    app(Tenancy::class)->enter(1, function () use ($admin): void {
        expect(Livewire::actingAs($admin)->test(EmailSettings::class)->viewData('sender')['suggestions'])->toBe(['info@anvinh.vn', 'contact@anvinh.vn']);
    });
});

it('refuses the notice methods to a member without settings.manage', function (): void {
    $member = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($member): void {
        grantRole($member, 'editor', ['entries.view']);
        Livewire::actingAs($member->fresh())->test(EmailSettings::class)->assertForbidden();
    });
    $this->actingAs($member->fresh())->get('http://a.test/admin/settings/email')->assertForbidden();
    expect(app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->get(MailNotifyTo::GROUP, MailNotifyTo::KEY)))->toBeNull();
});

it('fills a new form\'s recipients from «Gửi thông báo tới» and leaves an existing empty form as it is', function (): void {
    DB::connection('pgsql_admin')->table('site_modules')->insert(['site_id' => 1, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $manager = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($manager): void {
        grantRole($manager, 'admin', ['forms.view', 'forms.manage']);
        app(SettingsRepository::class)->put(MailNotifyTo::GROUP, MailNotifyTo::KEY, ['emails' => ['kinhdoanh@anvinh.vn', 'sai@', 'KINHDOANH@anvinh.vn', 'chu@anvinh.vn']]);
    });
    $manager = $manager->fresh();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $old = Form::query()->create(['key' => 'cu', 'name' => ['vi' => 'Biểu mẫu cũ'], 'status' => 'active', 'notify_emails' => []]);
        $old->fields()->create(['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Họ tên'], 'is_required' => true, 'sort_order' => 1]);

        Livewire::actingAs($manager)->test(FormEditor::class, ['purpose' => 'quote'])
            ->assertSet('emails', ['kinhdoanh@anvinh.vn', 'chu@anvinh.vn'])
            ->set('name', 'Báo giá mới')->call('save');
        expect(Form::query()->where('key', 'bao-gia-moi')->firstOrFail()->notify_emails)->toBe(['kinhdoanh@anvinh.vn', 'chu@anvinh.vn']);

        Livewire::actingAs($manager)->test(FormEditor::class, ['ulid' => $old->ulid])->assertSet('emails', []);
        expect($old->fresh()?->notify_emails)->toBe([]);
    });
});
