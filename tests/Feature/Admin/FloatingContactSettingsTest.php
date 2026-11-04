<?php

use App\Modules\Admin\Livewire\FloatingContactSettings;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * "Nút liên hệ nổi" settings (site_settings storefront/floating_contact): the page edits a draft
 * and saves it in one step. Every value is checked with the storefront's own rules when a field is
 * left and again on save, with a plain message on the field; hover labels are kept per language.
 * settings.manage-gated (route + boot() + the save action).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');   // the admin's language (tests run in English otherwise)
});

function fcsAdmin(int $siteId = 1): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter($siteId, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    return $admin->fresh();
}

/** @return array<string, mixed>|null */
function fcsStored(int $siteId = 1): ?array
{
    return app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()
        ->where('group_name', 'storefront')->where('key', 'floating_contact')->first()?->value);
}

/** The draft index of a channel. */
function fcsIndex(Testable $lw, string $key): int
{
    foreach ($lw->get('channels') as $i => $c) {
        if ($c['key'] === $key) {
            return $i;
        }
    }
    throw new RuntimeException("no channel $key");
}

it('starts off with the five built-in channels, the site’s own phone and email filled in', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        SiteSetting::query()->create(['group_name' => 'general', 'key' => 'brand', 'value' => ['name' => 'A', 'phone' => '0901 234 567', 'email' => 'lienhe@a.test']]);

        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->assertOk()
            ->assertSet('enabled', false)
            ->assertSet('theme', 'vertical')
            ->assertSet('position', 'right')
            ->assertSee('Kích hoạt nút liên hệ nổi')
            ->assertSee('Đã lưu');

        expect(array_column($lw->get('channels'), 'key'))->toBe(['phone', 'zalo', 'messenger', 'email', 'sms'])
            ->and(array_column($lw->get('channels'), 'on'))->toBe([true, true, true, true, false])
            ->and($lw->get('channels')[0]['value'])->toBe('0901234567')
            ->and($lw->get('channels')[3]['value'])->toBe('lienhe@a.test');
    });
});

it('saves the draft for the current site only and loads it back', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->call('toggleEnabled')
            ->call('setTheme', 'horizontal')
            ->call('setPosition', 'left')
            ->set('offsetX', 40)
            ->set('offsetY', 100);
        $lw->set('channels.'.fcsIndex($lw, 'phone').'.value', '0912 345 678')
            ->set('channels.'.fcsIndex($lw, 'zalo').'.value', 'https://zalo.me/0912345678')
            ->set('channels.'.fcsIndex($lw, 'phone').'.tips.vi', 'Gọi ngay')
            ->call('addPreset', 'telegram');
        $lw->set('channels.'.fcsIndex($lw, 'telegram').'.value', '@mau_shop')
            ->call('addCustom');
        $lw->set('channels.'.fcsIndex($lw, 'c1').'.value', '/lien-he')
            ->set('channels.'.fcsIndex($lw, 'c1').'.name', 'Liên hệ')
            ->call('pickIcon', 'c1', 'ri-chat-3-line')
            ->call('move', 'telegram', -1)
            ->assertSee('Chưa lưu')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('toast', 'Đã lưu cấu hình nút liên hệ')
            ->assertSee('Đã lưu');
    });

    $stored = fcsStored(1);
    expect($stored)->toMatchArray(['enabled' => true, 'theme' => 'horizontal', 'position' => 'left', 'offset_x' => 40, 'offset_y' => 100])
        ->and(array_column($stored['channels'], 'key'))->toBe(['phone', 'zalo', 'messenger', 'email', 'telegram', 'sms', 'c1'])
        ->and($stored['channels'][0])->toMatchArray(['key' => 'phone', 'on' => true, 'value' => '0912 345 678', 'tips' => ['vi' => 'Gọi ngay']])
        ->and($stored['channels'][6])->toMatchArray(['key' => 'c1', 'icon' => 'ri-chat-3-line', 'name' => 'Liên hệ', 'value' => '/lien-he'])
        ->and(fcsStored(2))->toBeNull();

    // Loaded back as saved; the other site still sees its own (empty) setting.
    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->assertSet('enabled', true)->assertSet('theme', 'horizontal')->assertSet('offsetX', 40);
        expect(array_column($lw->get('channels'), 'key'))->toBe(['phone', 'zalo', 'messenger', 'email', 'telegram', 'sms', 'c1']);
    });
    $other = fcsAdmin(2);
    app(Tenancy::class)->enter(2, fn () => Livewire::actingAs($other)->test(FloatingContactSettings::class)->assertSet('enabled', false));
});

it('refuses every invalid value with its plain message and saves nothing', function (string $key, string $value, string $message): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $key, $value, $message): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        if (! in_array($key, ['phone', 'zalo', 'messenger', 'email', 'sms'], true)) {
            $key === 'c1' ? $lw->call('addCustom') : $lw->call('addPreset', $key);
        }
        $i = fcsIndex($lw, $key);
        $lw->set("channels.$i.on", true)
            ->set("channels.$i.value", $value)
            ->assertHasErrors("channels.$i.value")   // checked when the field is left
            ->call('save')
            ->assertHasErrors(["channels.$i.value"])
            ->assertSee('Định dạng chưa hợp lệ — '.$message, false)
            ->assertSet('toastTone', 'error')
            ->assertSet('toast', 'Còn 1 kênh chưa đúng định dạng — sửa rồi bấm Lưu lại');
    });

    expect(fcsStored())->toBeNull();
})->with([
    'short phone' => ['phone', '091234', 'nhập 10 số bắt đầu bằng 0 (vd 0912345678) hoặc +84 rồi 9 số'],
    'phone with letters' => ['phone', '09123abc78', 'nhập 10 số bắt đầu bằng 0 (vd 0912345678) hoặc +84 rồi 9 số'],
    'zalo other site' => ['zalo', 'https://evil.test/0912345678', 'nhập số điện thoại Zalo (vd 0912345678) hoặc liên kết zalo.me/0912345678'],
    'messenger with spaces' => ['messenger', 'm.me/trang mau', 'nhập ID hoặc tên trang, hoặc liên kết m.me/ten-trang'],
    'email' => ['email', 'lienhe@', 'nhập dạng ten@congty.com'],
    'sms' => ['sms', '12', 'nhập 10 số bắt đầu bằng 0 (vd 0912345678) hoặc +84 rồi 9 số'],
    'telegram too short' => ['telegram', '@ab', 'nhập @username (5–32 chữ cái, số hoặc dấu _) hoặc liên kết t.me/username'],
    'youtube javascript' => ['youtube', 'javascript:alert(1)', 'dán liên kết đầy đủ, bắt đầu bằng https://'],
    'maps data' => ['maps', 'data:text/html,x', 'dán liên kết đầy đủ, bắt đầu bằng https://'],
    'tiktok site path' => ['tiktok', '/lien-he', 'dán liên kết đầy đủ, bắt đầu bằng https://'],
    'custom javascript' => ['c1', 'javascript:alert(1)', 'dán liên kết https://, đường dẫn trong website (vd /lien-he), hoặc tel:, sms:, mailto:'],
    'custom bad tel' => ['c1', 'tel:abc', 'dán liên kết https://, đường dẫn trong website (vd /lien-he), hoặc tel:, sms:, mailto:'],
]);

it('lets a switched-off or empty channel through, as the page draws it', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        $lw->set('channels.'.fcsIndex($lw, 'sms').'.value', 'not a number')   // sms is off
            ->set('channels.'.fcsIndex($lw, 'messenger').'.value', '')
            ->call('save')
            ->assertHasNoErrors();
    });

    expect(fcsStored())->not->toBeNull();
});

it('refuses an icon outside the allowed set, an over-long name or label, and a bad colour', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)->call('addCustom');
        $i = fcsIndex($lw, 'c1');
        $lw->call('pickIcon', 'c1', 'ri-skull-line')   // not offered: ignored
            ->assertSet("channels.$i.icon", 'ri-global-line')
            ->set("channels.$i.icon", 'ri-skull-line')
            ->assertHasErrors("channels.$i.icon")
            ->set("channels.$i.name", str_repeat('a', 41))
            ->set("channels.$i.tips.vi", str_repeat('b', 41))
            ->set("channels.$i.color", 'red;background:url(x)')
            ->call('save')
            ->assertHasErrors(["channels.$i.icon", "channels.$i.name", "channels.$i.tips.vi", "channels.$i.color"])
            ->assertSee('Chọn một biểu tượng trong danh sách, hoặc dán đúng mã của một biểu tượng trong đó.')
            ->assertSee('Dài quá 40 ký tự — rút gọn lại.')
            ->assertSee('Màu chưa hợp lệ — chọn lại bằng ô màu.');
    });

    expect(fcsStored())->toBeNull();
});

it('refuses a tampered draft: unknown, repeated or missing channels and out-of-range choices', function (string $prop, mixed $value): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $prop, $value): void {
        Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->set($prop, $value)
            ->call('save')
            ->assertHasErrors('channels')
            ->assertSee('Cấu hình gửi lên không hợp lệ — tải lại trang rồi thử lại.');
    });

    expect(fcsStored())->toBeNull();
})->with([
    'unknown channel' => ['channels.0.key', 'skype'],
    'repeated channel' => ['channels.1.key', 'phone'],
    'missing core channel' => ['channels', [['key' => 'phone', 'on' => true, 'value' => '', 'color' => '', 'icon' => '', 'name' => '', 'tips' => []]]],
    'theme' => ['theme', 'popup'],
    'position' => ['position', 'top'],
    'buy colour' => ['buyColor', 'url(x)'],
]);

it('refuses a hover label in a language the site does not have', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->set('channels.0.tips', ['xx' => 'Hi'])
            ->call('save')
            ->assertHasErrors('channels.0.tips');
    });

    expect(fcsStored())->toBeNull();
});

it('keeps hover labels per language and names each language', function (): void {
    $admin = fcsAdmin();
    DB::connection('pgsql_admin')->table('site_locales')->insert([
        ['site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        app(SiteLocale::class)->forget();
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->assertSee('Tiếng Việt')->assertSee('English')
            ->assertSet('lang', 'vi');
        $phone = fcsIndex($lw, 'phone');
        $lw->set("channels.$phone.tips.vi", 'Gọi ngay')
            ->call('setLang', 'en')
            ->assertSet('lang', 'en')
            ->assertSee('Nhãn hover · Phone');   // the channel's own word in English
        $lw->set("channels.$phone.tips.en", 'Call now')
            ->call('setLang', 'fr')            // not a site language: ignored
            ->assertSet('lang', 'en')
            ->call('save')
            ->assertHasNoErrors();
    });

    // JSONB keeps its own key order, so compare without it.
    expect(fcsStored()['channels'][0]['tips'])->toEqual(['vi' => 'Gọi ngay', 'en' => 'Call now']);
});

it('words the page in the admin’s language', function (string $locale, string $title, string $save): void {
    $admin = fcsAdmin();
    app()->setLocale($locale);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->assertSee($title)->assertSee($save));
})->with([
    'vi' => ['vi', 'Kích hoạt nút liên hệ nổi', 'Lưu thay đổi'],
    'en' => ['en', 'Turn on the floating contact buttons', 'Save changes'],
    'ja' => ['ja', 'フローティング連絡ボタンを有効にする', '変更を保存'],
    'zh' => ['zh', '启用浮动联系按钮', '保存更改'],
]);

it('discards the draft back to the saved setting', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->call('toggleEnabled')
            ->call('addPreset', 'maps')
            ->assertSee('Chưa lưu')
            ->call('resetChanges')
            ->assertSet('enabled', false)
            ->assertSet('toast', 'Đã bỏ thay đổi')
            ->assertSee('Đã lưu');
    });
});

it('gates the page — settings.manage on the route, in boot() and on save', function (): void {
    $admin = fcsAdmin();
    $member = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($member, 'plain-member', ['entries.view']));

    $this->actingAs($admin)->get('http://a.test/admin/settings/floating-contact')->assertOk()->assertSee(__('admin.fc.title'));
    $this->actingAs($member->fresh())->get('http://a.test/admin/settings/floating-contact')->assertForbidden();

    app(Tenancy::class)->enter(1, function () use ($member): void {
        Livewire::actingAs($member->fresh())->test(FloatingContactSettings::class)->assertForbidden();
    });
    expect(fcsStored())->toBeNull();
});
