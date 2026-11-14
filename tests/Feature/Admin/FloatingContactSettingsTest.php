<?php

use App\Modules\Admin\Livewire\FloatingContactSettings;
use App\Modules\Admin\Support\Settings\FloatingContactInput;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Storefront\Models\ContactClickDaily;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Chrome\FloatingContact;
use App\Modules\Theme\Theming\ReadableColor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

/** A product list for the site: the catalog module, a products section on a category with an address, one published product. */
function fcsShop(int $siteId, bool $entitled = true): void
{
    if ($entitled) {
        DB::connection('pgsql_admin')->table('site_modules')->insert(['site_id' => $siteId, 'module_key' => 'catalog', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
    app(Tenancy::class)->enter($siteId, function (): void {
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Sản phẩm', 'slug' => 'san-pham']);
        ContentSection::query()->create(['key' => 'products', 'label' => 'Sản phẩm', 'entry_type' => 'post', 'taxonomy_id' => $category->id]);
        app(RouteSync::class)->syncTaxonomy($category, 'vi', 'san-pham');
        $product = Product::query()->create(['status' => 'published', 'published_at' => now()]);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Sản phẩm 1', 'slug' => 'sp-1']);
    });
}

/** The rendered page without Livewire's block markers. */
function fcsBare(Testable $lw): string
{
    return str_replace(['<!--[if BLOCK]><![endif]-->', '<!--[if ENDBLOCK]><![endif]-->'], '', $lw->html());
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

it('starts off with the six built-in channels, the site’s own phone and email filled in', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        SiteSetting::query()->create(['group_name' => 'general', 'key' => 'brand', 'value' => ['name' => 'A', 'phone' => '0901 234 567', 'email' => 'lienhe@a.test']]);

        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->assertOk()
            ->assertSet('enabled', false)
            ->assertSet('layout', 'combo')
            ->assertSet('position', 'right')
            ->assertSee('Kích hoạt nút liên hệ nổi')
            ->assertSee('Đã lưu');

        expect(array_column($lw->get('channels'), 'key'))->toBe(['phone', 'zalo', 'messenger', 'email', 'sms', 'maps'])
            ->and(array_column($lw->get('channels'), 'on'))->toBe([true, true, true, true, false, false])
            ->and($lw->get('channels')[0]['value'])->toBe('0901234567')
            ->and($lw->get('channels')[3]['value'])->toBe('lienhe@a.test');
    });
});

it('saves the draft for the current site only and loads it back', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->call('toggleEnabled')
            ->call('setLayout', 'combo')
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
        ->and(array_column($stored['channels'], 'key'))->toBe(['phone', 'zalo', 'messenger', 'email', 'sms', 'telegram', 'maps', 'c1'])
        ->and($stored['channels'][0])->toMatchArray(['key' => 'phone', 'on' => true, 'value' => '0912 345 678', 'tips' => ['vi' => 'Gọi ngay']])
        ->and($stored['channels'][7])->toMatchArray(['key' => 'c1', 'icon' => 'ri-chat-3-line', 'name' => 'Liên hệ', 'value' => '/lien-he'])
        ->and(fcsStored(2))->toBeNull();

    // Loaded back as saved; the other site still sees its own (empty) setting.
    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->assertSet('enabled', true)->assertSet('theme', 'horizontal')->assertSet('offsetX', 40);
        expect(array_column($lw->get('channels'), 'key'))->toBe(['phone', 'zalo', 'messenger', 'email', 'sms', 'telegram', 'maps', 'c1']);
    });
    $other = fcsAdmin(2);
    app(Tenancy::class)->enter(2, fn () => Livewire::actingAs($other)->test(FloatingContactSettings::class)->assertSet('enabled', false));
});

it('keeps a phone bar a content pack switched on when the form saves', function (): void {
    $admin = fcsAdmin();
    $bar = ['enabled' => true, 'cta' => ['label' => ['vi' => 'Yêu cầu báo giá'], 'url' => '/lien-he']];
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'floating_contact', 'value' => [
        'enabled' => true, 'theme' => 'vertical', 'phone_bar' => $bar,
        'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678']],
    ]]));

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->set('offsetX', 30)->call('save')->assertHasNoErrors());

    expect(fcsStored(1)['phone_bar'] ?? null)->toEqual($bar);
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
    'short phone' => ['phone', '091234', 'nhập số di động (vd 0912 345 678), số bàn 11 số (vd 024 1234 5678) hoặc tổng đài 1900/1800'],
    'phone with letters' => ['phone', '09123abc78', 'nhập số di động (vd 0912 345 678), số bàn 11 số (vd 024 1234 5678) hoặc tổng đài 1900/1800'],
    'zalo other site' => ['zalo', 'https://evil.test/0912345678', 'nhập số điện thoại Zalo (vd 0912345678) hoặc liên kết zalo.me/0912345678'],
    'messenger with spaces' => ['messenger', 'm.me/trang mau', 'nhập ID hoặc tên trang, hoặc liên kết m.me/ten-trang'],
    'email' => ['email', 'lienhe@', 'nhập dạng ten@congty.com'],
    'landline too long' => ['phone', '024 1234 56789', 'nhập số di động (vd 0912 345 678), số bàn 11 số (vd 024 1234 5678) hoặc tổng đài 1900/1800'],
    'hotline wrong length' => ['phone', '1900 12345', 'nhập số di động (vd 0912 345 678), số bàn 11 số (vd 024 1234 5678) hoặc tổng đài 1900/1800'],
    'call with country code 0' => ['phone', '+0 123', 'nhập số di động (vd 0912 345 678), số bàn 11 số (vd 024 1234 5678) hoặc tổng đài 1900/1800'],
    'whatsapp abroad too short' => ['whatsapp', '+1234567', 'nhập số di động (vd 0912 345 678), số bàn (vd 024 1234 5678) hoặc số nước ngoài bắt đầu bằng + (vd +65 9123 4567)'],
    'sms' => ['sms', '12', 'nhập số di động (vd 0912 345 678), số bàn 11 số (vd 024 1234 5678) hoặc tổng đài 1900/1800'],
    'telegram too short' => ['telegram', '@ab', 'nhập @username (5–32 chữ cái, số hoặc dấu _) hoặc liên kết t.me/username'],
    'youtube javascript' => ['youtube', 'javascript:alert(1)', 'dán liên kết đầy đủ, bắt đầu bằng https://'],
    'maps data' => ['maps', 'data:text/html,x', 'dán liên kết đầy đủ, bắt đầu bằng https://'],
    'tiktok site path' => ['tiktok', '/lien-he', 'dán liên kết đầy đủ, bắt đầu bằng https://'],
    'custom javascript' => ['c1', 'javascript:alert(1)', 'dán liên kết https://, đường dẫn trong website (vd /lien-he), hoặc tel:, sms:, mailto:'],
    'custom bad tel' => ['c1', 'tel:abc', 'dán liên kết https://, đường dẫn trong website (vd /lien-he), hoặc tel:, sms:, mailto:'],
]);

it('accepts landlines, hotlines and, for WhatsApp and Viber, numbers abroad', function (string $key, string $value, string $href): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $key, $value): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        if (! in_array($key, ['phone', 'sms'], true)) {
            $lw->call('addPreset', $key);
        }
        $i = fcsIndex($lw, $key);
        $lw->set("channels.$i.on", true)
            ->set("channels.$i.value", $value)
            ->assertHasNoErrors()
            ->call('toggleEnabled')
            ->call('save')
            ->assertHasNoErrors();
    });

    expect(collect(FloatingContact::for(fcsStored(), 'vi')['buttons'])->firstWhere('key', $key)['href'])->toBe($href);
})->with([
    'landline' => ['phone', '024 1234 5678', 'tel:02412345678'],
    'hotline 1900' => ['phone', '1900 1234', 'tel:19001234'],
    'hotline 1800 six digits' => ['phone', '1800.123.456', 'tel:1800123456'],
    'sms landline' => ['sms', '028-3822-1234', 'sms:02838221234'],
    'whatsapp abroad' => ['whatsapp', '+65 9123 4567', 'https://wa.me/6591234567'],
    'viber abroad' => ['viber', '+1 (415) 555-0100', 'viber://chat?number=%2B14155550100'],
]);

it('fills the call channel with a landline site phone, and Zalo only with a mobile', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        SiteSetting::query()->create(['group_name' => 'general', 'key' => 'brand', 'value' => ['name' => 'A', 'phone' => '024 3826 1234']]);

        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);

        expect($lw->get('channels')[fcsIndex($lw, 'phone')]['value'])->toBe('02438261234')
            ->and($lw->get('channels')[fcsIndex($lw, 'zalo')]['value'])->toBe('');
    });
});

it('caps a value even on a switched-off channel', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        $i = fcsIndex($lw, 'sms');   // off
        $lw->assertSet("channels.$i.on", false)
            ->set("channels.$i.value", str_repeat('9', FloatingContact::MAX_VALUE + 1))
            ->assertHasErrors("channels.$i.value")
            ->call('save')
            ->assertHasErrors(["channels.$i.value"])
            ->assertSee('Dài quá 500 ký tự — rút gọn lại.');
    });

    expect(fcsStored())->toBeNull();
});

it('answers a value of the wrong type with the plain message, not an error page', function (string $prop, mixed $value, string $field, bool $custom = false): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $prop, $value, $field, $custom): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        if ($custom) {
            $lw->call('addCustom');   // c1, after the built-in five
        }
        $lw->set($prop, $value)
            ->assertOk()
            ->call('save')
            ->assertOk()
            ->assertHasErrors($field)
            ->assertSee('Cấu hình gửi lên không hợp lệ — tải lại trang rồi thử lại.');
    });

    expect(fcsStored())->toBeNull();
})->with([
    'value' => ['channels.0.value', ['x'], 'channels'],
    'colour' => ['channels.0.color', ['x'], 'channels'],
    'labels' => ['channels.0.tips', 'x', 'channels'],
    'label' => ['channels.0.tips.vi', ['x'], 'channels.0.tips'],
    'channel key' => ['channels.0.key', ['x'], 'channels'],
    'a whole row as a string' => ['channels.0', 'x', 'channels'],
    'name of a channel of the site’s own' => ['channels.5.name', ['x'], 'channels', true],
]);

it('keeps working on the page after a value of the wrong type', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->set('channels.0', 'x')
            ->call('addCustom')
            ->call('toggleChannel', 'zalo')
            ->call('toggleChannel', '')
            ->call('pickIcon', '', 'ri-global-line')
            ->call('move', 'zalo', 1)
            ->call('setLayout', 'combo')
            ->assertOk()
            ->assertSee('Zalo');
    });

    expect(fcsStored())->toBeNull();
});

it('opens and saves after a language with hover labels was switched off', function (): void {
    $admin = fcsAdmin();
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'floating_contact', 'value' => [
        'enabled' => true, 'theme' => 'vertical', 'position' => 'right', 'offset_x' => 20, 'offset_y' => 24,
        'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678', 'tips' => ['vi' => 'Gọi ngay', 'en' => 'Call now']]],
    ]]));

    // en is not (or no longer) a site language: its label is dropped on load, not flagged on save.
    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)->assertOk();
        expect($lw->get('channels')[fcsIndex($lw, 'phone')]['tips'])->toBe(['vi' => 'Gọi ngay']);
        $lw->call('save')->assertHasNoErrors()->assertSet('toast', 'Đã lưu cấu hình nút liên hệ');
    });

    expect(fcsStored()['channels'][0]['tips'])->toBe(['vi' => 'Gọi ngay']);
});

it('keeps the “Mua hàng” switch but shows the button nowhere while the site has no product list', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->call('setLayout', 'combo')
            ->call('toggleBuy')
            ->assertSet('hasShop', false)
            ->assertSee('Nút này hiện khi website có trang sản phẩm.');
        $lw->set('channels.'.fcsIndex($lw, 'phone').'.value', '0912345678')
            ->set('channels.'.fcsIndex($lw, 'zalo').'.value', '0912345678')
            ->call('toggleChannel', 'sms');
        $lw->set('channels.'.fcsIndex($lw, 'sms').'.value', '0912345678');

        // The preview draws what the site draws: no buy button; the bar holds the quick channels, Messenger and email wait in "Thêm".
        expect($lw->viewData('preview')['buy'])->toBeNull()
            ->and(array_column($lw->viewData('preview')['bar'], 'key'))->toBe(['phone', 'zalo', 'sms'])
            ->and(array_column($lw->viewData('preview')['sheet'], 'key'))->toBe(['messenger', 'email'])
            ->and($lw->viewData('srcdoc'))->not->toContain('sf-fc__buy')->not->toContain('Mua hàng');
        $lw->call('save')->assertHasNoErrors();
    });

    expect(fcsStored()['buy'])->toEqual(['enabled' => true, 'color' => '#ff7a46']);
});

it('shows “Mua hàng” in the preview, after the channels, when the site has a product list', function (): void {
    $admin = fcsAdmin();
    fcsShop(1);

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->assertSet('hasShop', true)
            ->assertDontSee('Nút này hiện khi website có trang sản phẩm.')
            ->call('setLayout', 'combo')
            ->call('toggleBuy')
            ->call('setBuyColor', 'brand');
        $lw->set('channels.'.fcsIndex($lw, 'phone').'.value', '0912345678')
            ->set('channels.'.fcsIndex($lw, 'zalo').'.value', '0912345678')
            ->call('toggleChannel', 'sms');
        $lw->set('channels.'.fcsIndex($lw, 'sms').'.value', '0912345678');

        expect($lw->viewData('preview')['buy'])->toMatchArray(['fill' => 'var(--color-primary)', 'ink' => 'var(--color-on-primary)'])
            ->and(array_column($lw->viewData('preview')['bar'], 'key'))->toBe(['phone', 'zalo', 'sms'])
            ->and($lw->viewData('srcdoc'))->toContain('Mua hàng');
    });
});

it('previews the bottom bar on a phone only, with no device switch', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->assertSee('aria-label="Thiết bị xem trước"', false);

        // Combo: the bar options say they are for phones; the preview still switches device.
        $lw->call('setLayout', 'combo');
        expect(fcsBare($lw))->toContain('Tùy chọn thanh dưới<span class="font-normal text-text-muted"> · trên điện thoại</span>');
        $lw
            ->assertSee('Điện thoại hẹp tự bớt nút ở cuối')
            ->assertDontSee('style="display: none"', false);

        // The bottom bar only: phones only, so the device switch starts hidden and the preview says so.
        $lw->call('setLayout', 'horizontal')->assertSee('style="display: none"', false);
        expect(fcsBare($lw))->toContain('Xem trước<span class="font-normal text-text-muted"> · trên điện thoại</span>');

        $lw->call('setLayout', 'vertical')->assertDontSee('style="display: none"', false)->assertDontSee('Tùy chọn thanh dưới');
    });
});

it('counts no product list on a site not entitled to products', function (): void {
    $admin = fcsAdmin();
    fcsShop(1, entitled: false);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->assertSet('hasShop', false));
});

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

it('shows a channel switched on but not filled in at its place in the round-button preview, with a hint under its field', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        // No brand phone or email: the call, Zalo, Messenger and email start switched on and empty.
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)->call('setLayout', 'vertical');
        $lw->set('channels.'.fcsIndex($lw, 'phone').'.value', '0912345678')
            ->call('toggleChannel', 'sms');   // switched on, left empty
        $lw->set('channels.'.fcsIndex($lw, 'zalo').'.value', 'abc');   // a value that does not pass

        $preview = $lw->viewData('preview');
        $pending = array_column(array_filter($preview['buttons'], fn (array $b): bool => $b['pending'] ?? false), 'key');
        expect(array_column($preview['buttons'], 'key'))->toBe(['phone', 'zalo', 'messenger', 'email', 'sms'])
            ->and($pending)->toBe(['zalo', 'messenger', 'email', 'sms']);

        // The frame draws the website's own buttons; the unfinished ones carry the admin-only mark and no link.
        $frame = (string) $lw->viewData('srcdoc');
        expect(preg_match_all('/ data-fc-pending>\s*<a class="sf-fc__btn" href="#"/', $frame))->toBe(4)
            ->and($frame)->toContain('<a class="sf-fc__btn" href="tel:0912345678" aria-label="Gọi điện"');
        $html = $lw->html();
        expect($html)
            // the hint shows under the empty fields only (not under the filled call, nor under Zalo's error)
            ->toContain('id="fc-empty-sms"', 'aria-describedby="fc-hint-sms fc-empty-sms"')
            ->not->toContain('id="fc-empty-phone"')->not->toContain('id="fc-empty-zalo"')
            ->and(substr_count($html, 'Chưa nhập — nút này chưa hiện trên website.'))->toBe(3);

        // Switched off: gone from the preview, and its hint with it.
        $lw->call('toggleChannel', 'sms');
        expect($lw->html())->not->toContain('id="fc-empty-sms"')
            ->and(array_column($lw->viewData('preview')['buttons'], 'key'))->not->toContain('sms')
            ->and(preg_match_all('/ data-fc-pending>\s*<a class="sf-fc__btn"/', (string) $lw->viewData('srcdoc')))->toBe(3);

        // Empty stays allowed on save.
        $lw->set('channels.'.fcsIndex($lw, 'zalo').'.value', '')->call('save')->assertHasNoErrors();
    });

    expect(collect(fcsStored()['channels'])->firstWhere('key', 'messenger'))->toMatchArray(['on' => true, 'value' => '']);
});

it('puts every channel switched on in the bottom bar or its “Thêm” sheet, unfilled ones at their place', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)->call('setLayout', 'horizontal');
        $lw->set('channels.'.fcsIndex($lw, 'phone').'.value', '0912345678')
            ->call('toggleChannel', 'sms')
            ->call('addPreset', 'whatsapp')->call('addPreset', 'telegram')->call('addPreset', 'viber');

        // Five places: four quick channels and "Thêm", which holds the rest in saved order.
        expect(array_column($lw->viewData('preview')['bar'], 'key'))->toBe(['phone', 'zalo', 'sms', 'whatsapp'])
            ->and(array_column($lw->viewData('preview')['sheet'], 'key'))->toBe(['messenger', 'email', 'telegram', 'viber'])
            ->and($lw->viewData('srcdoc'))->toContain('popovertarget="sf-fc-more"')
            ->toMatch('#class="sf-fc-bar__item[^"]*"(?: style="[^"]*")? data-fc-pending><a class="sf-fc-bar__link" href="\#"#')
            ->not->toContain('sf-fc__float');

        // A switched-off channel never takes a cell.
        $lw->call('toggleChannel', 'zalo');
        expect(array_column($lw->viewData('preview')['bar'], 'key'))->toBe(['phone', 'sms', 'whatsapp', 'telegram'])
            ->and(array_column($lw->viewData('preview')['sheet'], 'key'))->toBe(['messenger', 'email', 'viber']);
    });
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
            ->assertSee('Mặc định: Phone');   // the channel's own word in English
        $lw->set("channels.$phone.tips.en", 'Call now')
            ->call('setLang', 'fr')            // not a site language: ignored
            ->assertSet('lang', 'en')
            ->call('save')
            ->assertHasNoErrors();
    });

    // JSONB keeps its own key order, so compare without it.
    expect(fcsStored()['channels'][0]['tips'])->toEqual(['vi' => 'Gọi ngay', 'en' => 'Call now']);
});

it('names every error that blocks the save on a switched-off channel', function (): void {
    $admin = fcsAdmin();
    DB::connection('pgsql_admin')->table('site_locales')->insert([
        ['site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        app(SiteLocale::class)->forget();
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)->call('addCustom');
        $i = fcsIndex($lw, 'c1');
        $name = str_repeat('a', 41);
        $lw->set("channels.$i.icon", 'ri-skull-line')
            ->set("channels.$i.name", $name)
            ->set("channels.$i.value", str_repeat('9', FloatingContact::MAX_VALUE + 1))
            ->set("channels.$i.color", 'red;x')
            ->set("channels.$i.tips.vi", str_repeat('b', 41))
            ->set("channels.$i.tips.en", str_repeat('c', 41))
            ->call('toggleChannel', 'c1')            // switched off: its fields fold away
            ->assertSet("channels.$i.on", false)
            ->call('save')
            ->assertHasErrors(["channels.$i.icon", "channels.$i.name", "channels.$i.value", "channels.$i.color", "channels.$i.tips.vi", "channels.$i.tips.en"])
            ->assertSee('Dài quá 40 ký tự — rút gọn lại.')    // the name, under its own field
            ->assertSee($name.' · Biểu tượng: Chọn một biểu tượng trong danh sách', false)
            ->assertSee($name.' · Thông tin liên hệ: Dài quá 500 ký tự — rút gọn lại.', false)
            ->assertSee($name.' · Màu: Màu chưa hợp lệ — chọn lại bằng ô màu.', false)
            ->assertSee($name.' · Nhãn khi di chuột (Tiếng Việt): Dài quá 40 ký tự — rút gọn lại.', false)
            ->assertSee($name.' · Nhãn khi di chuột (English): Dài quá 40 ký tự — rút gọn lại.', false);

        // Switched on, the label of the language not being edited is still named.
        $lw->call('toggleChannel', 'c1')->call('save')
            ->assertSee('Nhãn khi di chuột (English): Dài quá 40 ký tự — rút gọn lại.', false);
    });

    expect(fcsStored())->toBeNull();
});

it('opens a channel whose icon is no longer offered with the default icon, so it saves', function (): void {
    $admin = fcsAdmin();
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'floating_contact', 'value' => [
        'enabled' => true, 'theme' => 'vertical', 'position' => 'right', 'offset_x' => 20, 'offset_y' => 24,
        'channels' => [['key' => 'c1', 'on' => false, 'value' => '/lien-he', 'icon' => 'ri-retired-line', 'name' => 'Đặt lịch', 'tips' => []]],
    ]]));

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        expect($lw->get('channels')[fcsIndex($lw, 'c1')]['icon'])->toBe('ri-global-line');
        $lw->call('save')->assertHasNoErrors()->assertSet('toast', 'Đã lưu cấu hình nút liên hệ');
    });

    expect(collect(fcsStored()['channels'])->firstWhere('key', 'c1')['icon'])->toBe('ri-global-line');
});

it('previews “Mua hàng” only in a language whose product list has an address', function (): void {
    $admin = fcsAdmin();
    fcsShop(1);   // the product list has a Vietnamese address only
    DB::connection('pgsql_admin')->table('site_locales')->insert([
        ['site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        app(SiteLocale::class)->forget();
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->call('setLayout', 'combo')
            ->call('toggleBuy');
        $lw->set('channels.'.fcsIndex($lw, 'phone').'.value', '0912345678');
        expect($lw->get('hasShop'))->toBeTrue()
            ->and($lw->viewData('preview')['buy'])->not->toBeNull();

        $lw->call('setLang', 'en')->assertSet('hasShop', false);
        expect($lw->viewData('preview')['buy'])->toBeNull();

        $lw->call('setLang', 'vi')->assertSet('hasShop', true)
            ->set('lang', 'en')->assertSet('hasShop', false);   // the property set directly, too
    });
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

it('keeps the newer fields of a saved setting when the form saves with no change to them', function (): void {
    $admin = fcsAdmin();
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'floating_contact', 'value' => [
        'enabled' => true, 'theme' => 'horizontal', 'layout' => 'horizontal', 'dial' => false, 'bar_tone' => 'dark', 'track' => false,
        'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678']],
    ]]));

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->set('offsetX', 30)->call('save')->assertHasNoErrors());

    expect(fcsStored(1))->toMatchArray(['layout' => 'horizontal', 'dial' => false, 'bar_tone' => 'dark', 'track' => false, 'theme' => 'horizontal']);
});

it('keeps a round-button site with the phone bar as it is when the form saves with no change', function (): void {
    $admin = fcsAdmin();
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'floating_contact', 'value' => [
        'enabled' => true, 'theme' => 'vertical',
        'phone_bar' => ['enabled' => true, 'cta' => ['label' => ['vi' => 'Yêu cầu báo giá'], 'url' => '/lien-he']],
        'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678']],
    ]]));

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->set('offsetX', 30)->call('save')->assertHasNoErrors());

    $stored = fcsStored(1);
    expect($stored)->toMatchArray(['theme' => 'vertical', 'layout' => 'combo'])
        ->and($stored['phone_bar']['enabled'] ?? null)->toBeTrue();
});

it('stores the chosen layout with the older theme word it reads as', function (): void {
    $admin = fcsAdmin();
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'floating_contact', 'value' => [
        'enabled' => true, 'theme' => 'vertical', 'layout' => 'vertical', 'dial' => false, 'bar_tone' => 'light', 'track' => false,
        'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678']],
    ]]));

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->call('setLayout', 'combo')->call('save')->assertHasNoErrors());

    expect(fcsStored(1))->toMatchArray(['layout' => 'combo', 'theme' => 'horizontal', 'dial' => false, 'bar_tone' => 'light', 'track' => false]);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->call('setLayout', 'horizontal')->call('save')->assertHasNoErrors());
    expect(fcsStored(1))->toMatchArray(['layout' => 'horizontal', 'theme' => 'horizontal']);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->call('setLayout', 'vertical')->call('save')->assertHasNoErrors());
    expect(fcsStored(1))->toMatchArray(['layout' => 'vertical', 'theme' => 'vertical']);
});

it('starts a site that never saved on the combined layout', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->call('toggleEnabled')->call('save')->assertHasNoErrors());

    expect(fcsStored(1))->toMatchArray(['theme' => 'horizontal', 'layout' => 'combo', 'dial' => true, 'bar_tone' => 'auto', 'track' => true]);
});

it('opens and saves a site’s stored setting back unchanged when nothing was edited', function (): void {
    $admin = fcsAdmin();
    $value = [
        'enabled' => true, 'theme' => 'vertical', 'position' => 'left', 'offset_x' => 24, 'offset_y' => 40,
        'layout' => 'vertical', 'dial' => false, 'bar_tone' => 'dark', 'track' => false,
        'buy' => ['enabled' => false, 'color' => FloatingContact::BUY_COLOR],
        'channels' => [
            ['key' => 'email', 'on' => true, 'value' => 'lienhe@a.test', 'color' => '#ea4335', 'tips' => ['vi' => 'Gửi email']],
            ['key' => 'zalo', 'on' => true, 'value' => '0915668383', 'tips' => []],
            ['key' => 'phone', 'on' => true, 'value' => '0915 66 83 83', 'tips' => ['vi' => 'Gọi ngay']],
            ['key' => 'messenger', 'on' => false, 'value' => '', 'tips' => []],
            ['key' => 'sms', 'on' => false, 'value' => '', 'tips' => []],
            ['key' => 'maps', 'on' => false, 'value' => '', 'tips' => []],
        ],
    ];
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'floating_contact', 'value' => $value]));

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->call('save')->assertHasNoErrors());

    expect(fcsStored(1))->toEqual($value);
});

it('saves an older setting with no layout back as the layout it reads as, its theme and the site’s buttons unchanged', function (array $extra, string $layout): void {
    $admin = fcsAdmin();
    // The An Vinh content pack's shape: written before layouts, so it has the older `theme` only.
    $value = [
        'enabled' => true, 'theme' => 'vertical', 'position' => 'right',
        'channels' => [
            ['key' => 'email', 'on' => true, 'value' => 'lienhe@a.test', 'tips' => ['vi' => 'Gửi email'], 'color' => '#ea4335'],
            ['key' => 'zalo', 'on' => true, 'value' => '0915668383', 'color' => '#0068ff', 'tips' => ['vi' => 'Chat Zalo']],
            ['key' => 'phone', 'on' => true, 'value' => '0915 66 83 83', 'tips' => ['vi' => 'Gọi ngay'], 'color' => '#f59e0b'],
        ],
        'offset_x' => 16, 'offset_y' => 20,
    ] + $extra;
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'floating_contact', 'value' => $value]));

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->call('save')->assertHasNoErrors());

    $stored = fcsStored(1);
    $kept = array_values(array_filter($stored['channels'], fn (array $c): bool => in_array($c['key'], ['email', 'zalo', 'phone'], true)));
    expect($stored)->toMatchArray(['enabled' => true, 'theme' => 'vertical', 'position' => 'right', 'offset_x' => 16, 'offset_y' => 20, 'layout' => $layout])
        ->and(FloatingContact::layout($stored))->toBe(FloatingContact::layout($value))
        ->and(array_map(fn (array $c): array => array_intersect_key($c, array_flip(['key', 'on', 'value', 'color', 'tips'])), $kept))
        ->toEqual(array_map(fn (array $c): array => array_intersect_key($c, array_flip(['key', 'on', 'value', 'color', 'tips'])), $value['channels']))
        ->and(FloatingContact::widget($stored, 'vi'))->toEqual(FloatingContact::widget($value, 'vi'))
        ->and($stored['phone_bar']['enabled'] ?? null)->toBe($extra['phone_bar']['enabled'] ?? null);
})->with([
    'round buttons only' => [[], 'vertical'],
    'with the phone bar on' => [['phone_bar' => ['enabled' => true, 'cta' => ['label' => ['vi' => 'Yêu cầu báo giá'], 'url' => '/lien-he']]], 'combo'],
]);

it('keeps a stored layout and its theme word exactly as they are when nothing was edited', function (string $layout, string $theme): void {
    $admin = fcsAdmin();
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'floating_contact', 'value' => [
        'enabled' => true, 'theme' => $theme, 'layout' => $layout,
        'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678']],
    ]]));

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->call('save')->assertHasNoErrors());

    expect(fcsStored(1))->toMatchArray(['layout' => $layout, 'theme' => $theme]);
})->with([
    'combo with the round-button word' => ['combo', 'vertical'],
    'vertical with the bar word' => ['vertical', 'horizontal'],
    'horizontal with the round-button word' => ['horizontal', 'vertical'],
    'combo with the bar word' => ['combo', 'horizontal'],
]);

it('runs the preview frame with no script of its own: sandboxed, its page carrying no script', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        $lw->set('channels.'.fcsIndex($lw, 'phone').'.value', '0987 654 321')
            ->set('channels.'.fcsIndex($lw, 'phone').'.on', true);

        expect($lw->viewData('srcdoc'))->toContain('data-fc-root')->not->toContain('<script')
            ->and($lw->html())->toContain('sandbox="allow-same-origin"')->not->toContain('allow-scripts')->not->toContain('&lt;script');
    });
});

it('draws the preview with the website’s own widget and shows an edit before it is saved', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        $lw->set('channels.'.fcsIndex($lw, 'phone').'.value', '0987 654 321')
            ->set('channels.'.fcsIndex($lw, 'phone').'.on', true);

        $srcdoc = $lw->viewData('srcdoc');
        expect($srcdoc)->toContain('data-fc-root')
            ->toContain('href="tel:0987654321"')
            ->toContain('<link rel="stylesheet"')
            ->and($lw->html())->toContain('srcdoc="')->toContain('data-fc-preview');
    });
    expect(fcsStored())->toBeNull();
});

it('hides the measuring switch, the “Kết nối” row and “Dịch bằng AI”, and keeps the stored measuring choice', function (): void {
    $admin = fcsAdmin();
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'floating_contact', 'value' => [
        'enabled' => true, 'theme' => 'horizontal', 'layout' => 'combo', 'track' => false,
        'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678']],
    ]]));

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->assertDontSee('Dịch bằng AI')->assertDontSee('Kết nối')->assertDontSee('Chi tiết kỹ thuật')
            ->assertDontSee('đo lường')->assertDontSeeHtml('toggleTrack');
        $lw->call('save')->assertHasNoErrors();
    });
    expect(fcsStored(1)['track'] ?? null)->toBeFalse();
});

it('offers “Dùng số …” on an empty number channel and fills it with the call channel’s number', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        $lw->set('channels.'.fcsIndex($lw, 'phone').'.value', '0912 345 678');
        $lw->assertSee('Dùng số 0912 345 678');
        $offers = substr_count($lw->html(), 'wire:click="reuseNumber(');

        $lw->call('reuseNumber', 'zalo')->call('reuseNumber', 'email');
        expect($offers)->toBeGreaterThan(0)
            ->and($lw->get('channels')[fcsIndex($lw, 'zalo')]['value'])->toBe('0912 345 678')
            ->and($lw->get('channels')[fcsIndex($lw, 'email')]['value'])->toBe('')
            ->and(substr_count($lw->html(), 'wire:click="reuseNumber('))->toBe($offers - 1);
    });
});

it('saves the dial and bar background choices', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->call('setLayout', 'vertical')->call('toggleDial')->call('setBarTone', 'dark')->call('setBarTone', 'purple')
        ->call('save')->assertHasNoErrors());

    expect(fcsStored(1))->toMatchArray(['layout' => 'vertical', 'dial' => false, 'bar_tone' => 'dark']);
});

it('shows only the current site’s taps of the last 30 days in “Hiệu quả”', function (): void {
    $admin = fcsAdmin();
    $today = now('Asia/Ho_Chi_Minh')->toDateString();
    app(Tenancy::class)->enter(1, function () use ($today): void {
        ContactClickDaily::query()->create(['day' => $today, 'channel' => 'zalo', 'placement' => 'floating', 'clicks' => 7]);
        ContactClickDaily::query()->create(['day' => $today, 'channel' => 'phone', 'placement' => 'bar', 'clicks' => 5]);
    });
    app(Tenancy::class)->enter(2, fn () => ContactClickDaily::query()->create(['day' => $today, 'channel' => 'phone', 'placement' => 'bar', 'clicks' => 4321]));

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        expect($lw->get('stats'))->toBe(['total' => 12, 'channels' => ['zalo' => 7, 'phone' => 5]]);
        $lw->assertSee('Hiệu quả nút liên hệ')->assertDontSee('4.321')->assertDontSee('Chưa có lượt bấm nào');
    });

    $other = fcsAdmin(2);
    app(TeamContext::class)->forget();
    app(Tenancy::class)->enter(2, function () use ($other): void {
        Livewire::actingAs($other)->test(FloatingContactSettings::class)->assertSet('stats', ['total' => 4321, 'channels' => ['phone' => 4321]]);
    });
});

it('shows “Mua hàng” last in “Hiệu quả”, in its own colour, while its switch is on in a layout with the phone bar', function (): void {
    $admin = fcsAdmin();
    $today = now('Asia/Ho_Chi_Minh')->toDateString();
    app(Tenancy::class)->enter(1, function () use ($today): void {
        ContactClickDaily::query()->create(['day' => $today, 'channel' => 'zalo', 'placement' => 'floating', 'clicks' => 7]);
        ContactClickDaily::query()->create(['day' => $today, 'channel' => 'buy', 'placement' => 'bar', 'clicks' => 3]);
    });
    app(Tenancy::class)->enter(2, fn () => ContactClickDaily::query()->create(['day' => $today, 'channel' => 'buy', 'placement' => 'bar', 'clicks' => 4321]));
    // The "Hiệu quả" card of the page as drawn now: its total and its rows' labels and counts, in order.
    $card = function (Testable $lw): array {
        $html = Str::before(Str::after($lw->html(), 'Hiệu quả nút liên hệ'), 'bg-success-soft');
        preg_match('/<b class="text-\[calc\(1\.375rem[^"]*">([^<]*)<\/b>/', $html, $total);
        preg_match_all('/<li class="grid[^"]*">.*?<span class="min-w-0[^"]*">([^<]*)<\/span>.*?<b[^>]*>([^<]*)<\/b>/s', $html, $rows, PREG_SET_ORDER);

        return ['total' => $total[1] ?? null, 'rows' => array_map(fn (array $r): string => trim($r[1]).' '.trim($r[2]), $rows), 'html' => $html];
    };

    app(Tenancy::class)->enter(1, function () use ($admin, $card): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->call('setLayout', 'combo')->set('buyOn', true)->call('setBuyColor', '#f7b84b');
        expect($lw->get('stats'))->toBe(['total' => 10, 'channels' => ['zalo' => 7, 'buy' => 3]]);
        $shown = $card($lw);
        expect($shown['total'])->toBe('10')
            ->and(end($shown['rows']))->toBe('Mua hàng 3')
            ->and($shown['html'])->toContain('background: '.ReadableColor::inkUnder('#f7b84b'))->not->toContain('4.321');

        $lw->call('setLayout', 'horizontal');
        expect(end($card($lw)['rows']))->toBe('Mua hàng 3');

        $lw->call('setBuyColor', 'brand');
        expect($card($lw)['html'])->toContain('background: var(--color-primary)');

        $lw->call('setLayout', 'vertical');   // no phone bar: no "Mua hàng" row, its taps still in the total
        expect($card($lw)['rows'])->not->toContain('Mua hàng 3')
            ->and($card($lw)['total'])->toBe('10');

        $lw->call('setLayout', 'combo')->call('toggleBuy');   // switched off
        expect(implode('|', $card($lw)['rows']))->not->toContain('Mua hàng');
    });
});

it('says plainly that no taps were counted yet', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->assertSet('stats', ['total' => 0, 'channels' => []])
        ->assertSee('Chưa có lượt bấm nào. Số liệu hiện tại đây khi khách bắt đầu bấm nút trên website.'));
});

it('refuses every action to a user who lost the settings permission', function (string $method, array $args): void {
    $admin = fcsAdmin();
    $member = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($member, 'plain-member', ['entries.view']));

    app(Tenancy::class)->enter(1, function () use ($admin, $member, $method, $args): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        $this->actingAs($member->fresh());
        app(TeamContext::class)->forget();
        $lw->call($method, ...$args)->assertForbidden();
    });
    expect(fcsStored())->toBeNull();
})->with([
    ['toggleEnabled', []], ['setLayout', ['vertical']], ['toggleDial', []], ['setBarTone', ['dark']],
    ['reuseNumber', ['zalo']], ['setPosition', ['left']], ['setLang', ['vi']], ['toggleChannel', ['phone']],
    ['move', ['phone', 1]], ['sortChannel', ['phone', 2]], ['deleteChannel', ['phone']], ['togglePresetMenu', []],
    ['addPreset', ['telegram']], ['addCustom', []], ['pickIcon', ['phone', 'ri-phone']], ['toggleBuy', []],
    ['setBuyColor', ['#000000']], ['save', []], ['resetChanges', []], ['dismissToast', [1]], ['stored', []],
    ['useIcon', ['phone', '01JC0000000000000000000000']], ['clearIcon', ['phone']], ['iconRefused', ['type']],
]);

it('opens on a flat query budget, the taps read in one query', function (): void {
    $admin = fcsAdmin();
    $url = 'http://a.test/admin/settings/floating-contact';

    $this->actingAs($admin)->get($url)->assertOk(); // the permission and settings caches fill on the first request
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $count = countQueries(fn () => $this->actingAs($admin)->get($url)->assertOk()->assertSee('Hiệu quả nút liên hệ'));

    expect(array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'contact_click_daily'))))->toHaveCount(1)
        // Flat: the shell's cached nav and permissions, the top bar's notifications, the site's settings
        // and languages, and the one grouped read of the last 30 days' taps (an index on site_id, day leads it).
        // (a first visit with nothing saved also reads the footer's list once, for the map link the maps channel starts with.)
        ->and($count)->toBeLessThanOrEqual(11);
});

it('says the bottom position is a phone position only while that position is chosen', function (): void {
    $admin = fcsAdmin();
    $note = 'Giữa dưới chỉ áp dụng trên điện thoại; máy tính hiện bên phải.';

    app(Tenancy::class)->enter(1, function () use ($admin, $note): void {
        Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->call('toggleEnabled')
            ->call('setLayout', 'combo')
            ->call('setPosition', 'bottom')
            ->assertSee($note)
            ->call('setPosition', 'right')
            ->assertDontSee($note)
            ->call('setPosition', 'left')
            ->assertDontSee($note);
    });
});

/** @param  array<string, mixed>  $value */
function fcsSave(array $value): void
{
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'floating_contact'], ['value' => $value]));
}

it('offers the raised call switch on, stores only its off state, and reads a setting without the key as on', function (): void {
    $admin = fcsAdmin();
    fcsSave(['enabled' => true, 'layout' => 'combo', 'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678']]]);

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->assertSet('barRaiseCall', true)
            ->assertSee('Làm nổi nút Gọi ở giữa thanh')
            ->assertSee('Nút Gọi to hơn, nằm giữa và nhô lên trên thanh — dễ thấy, dễ bấm bằng ngón cái.')
            ->assertDontSee('Chưa dùng được: hãy bật kênh');
        expect(fcsBare($lw))->toContain('aria-labelledby="fc-raise-l"')->toMatch('~<button[^>]*aria-checked="true"[^>]*aria-labelledby="fc-raise-l"~');

        $lw->call('save')->assertHasNoErrors();
    });
    expect(fcsStored(1))->not->toHaveKey('bar_raise_call');

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->call('toggleRaise')->assertSet('barRaiseCall', false)->call('save')->assertHasNoErrors());
    expect(fcsStored(1)['bar_raise_call'])->toBeFalse();

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->assertSet('barRaiseCall', false)->call('toggleRaise')->assertSet('barRaiseCall', true)->call('save')->assertHasNoErrors());
    expect(fcsStored(1))->not->toHaveKey('bar_raise_call');
});

it('disables the raised call switch with its reason while the call channel is off or has no valid number, and ignores a toggle then', function (array $phone): void {
    $admin = fcsAdmin();
    fcsSave(['enabled' => true, 'layout' => 'combo', 'channels' => [['key' => 'phone'] + $phone, ['key' => 'zalo', 'on' => true, 'value' => '0912345678']]]);

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->assertSee('Chưa dùng được: hãy bật kênh “Gọi điện” và nhập đúng số ở trên.');
        expect(fcsBare($lw))->toMatch('~<button[^>]*aria-checked="false"[^>]*aria-labelledby="fc-raise-l"[^>]*disabled~');

        $lw->call('toggleRaise')->assertSet('barRaiseCall', true);
    });
})->with([
    'switched off' => [['on' => false, 'value' => '0912345678']],
    'no number' => [['on' => true, 'value' => '']],
    'a number that does not pass' => [['on' => true, 'value' => 'abc']],
]);

it('shows the raised call switch only where the layout has the phone bar', function (string $layout, bool $shown): void {
    $admin = fcsAdmin();
    fcsSave(['enabled' => true, 'layout' => $layout, 'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678']]]);

    app(Tenancy::class)->enter(1, function () use ($admin, $shown): void {
        $html = fcsBare(Livewire::actingAs($admin)->test(FloatingContactSettings::class));
        expect(str_contains($html, 'fc-raise-l'))->toBe($shown);
    });
})->with(['combo' => ['combo', true], 'horizontal' => ['horizontal', true], 'vertical' => ['vertical', false]]);

it('draws the preview with the call raised in the middle while the switch is on, and in the saved order when it is off', function (): void {
    $admin = fcsAdmin();
    fcsSave(['enabled' => true, 'layout' => 'combo', 'channels' => [
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'], ['key' => 'zalo', 'on' => true, 'value' => '0912345678'], ['key' => 'sms', 'on' => true, 'value' => '0912345678'],
    ]]);

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        $labels = static function (Testable $lw): array {
            preg_match('~<ul class="sf-fc-bar__list" role="list">.*?</ul>~s', html_entity_decode(fcsBare($lw), ENT_QUOTES), $m);
            preg_match_all('~<span class="sf-fc-bar__label">([^<]*)</span>~', $m[0] ?? '', $l);

            return $l[1];
        };
        expect($labels($lw))->toBe(['Zalo', 'Gọi điện', 'Nhắn tin']);
        expect($labels($lw->call('toggleRaise')))->toBe(['Gọi điện', 'Zalo', 'Nhắn tin']);
    });
});

it('always lists directions, switched off, and never lets it be removed', function (): void {
    $admin = fcsAdmin();
    fcsSave(['enabled' => true, 'layout' => 'combo', 'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678']]]);

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        $maps = fn (Testable $t): array => array_values(array_filter($t->get('channels'), fn (array $c): bool => $c['key'] === 'maps'));
        expect($maps($lw))->toHaveCount(1)->and($maps($lw)[0]['on'])->toBeFalse();

        $lw->call('deleteChannel', 'maps')->call('addPreset', 'maps');
        expect($maps($lw))->toHaveCount(1);

        $lw->call('save')->assertHasNoErrors();
        expect(array_column(fcsStored(1)['channels'], 'key'))->toContain('maps');
    });
});

it('does not offer directions among the ready-made channels', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)->call('togglePresetMenu');
        expect(fcsBare($lw))->not->toMatch('~addPreset\([^)]*maps~')->toMatch('~addPreset\([^)]*telegram~');
    });
});

it('keeps a maps row a site added from the old list as the one maps row, with its value and switch', function (): void {
    $admin = fcsAdmin();
    fcsSave(['enabled' => true, 'layout' => 'combo', 'channels' => [
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
        ['key' => 'maps', 'on' => true, 'value' => 'https://maps.google.com/?q=Ha+Noi', 'tips' => ['vi' => 'Tới xưởng']],
        ['key' => 'zalo', 'on' => false, 'value' => ''],
    ]]);

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        expect(array_column($lw->get('channels'), 'key'))->toBe(['phone', 'maps', 'zalo', 'messenger', 'email', 'sms']);

        $lw->call('save')->assertHasNoErrors();
    });

    $rows = array_values(array_filter(fcsStored(1)['channels'], fn (array $c): bool => $c['key'] === 'maps'));
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['on' => true, 'value' => 'https://maps.google.com/?q=Ha+Noi', 'tips' => ['vi' => 'Tới xưởng']]);
});

it('adds directions to a full list only when there is room, never passing the most channels a site keeps', function (): void {
    $admin = fcsAdmin();
    $own = static fn (int $n): array => array_map(static fn (int $i): array => ['key' => 'c'.$i, 'on' => false, 'value' => '/trang-'.$i, 'name' => 'Trang '.$i, 'icon' => 'ri-global-line'], range(1, $n));
    $core = array_map(static fn (string $k): array => ['key' => $k, 'on' => false, 'value' => ''], ['phone', 'zalo', 'messenger', 'email', 'sms']);
    fcsSave(['enabled' => false, 'layout' => 'combo', 'channels' => [...$core, ...$own(FloatingContact::MAX_CHANNELS - 5)]]);

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        expect($lw->get('channels'))->toHaveCount(FloatingContact::MAX_CHANNELS)
            ->and(array_column($lw->get('channels'), 'key'))->not->toContain('maps');

        // A full list saves as it is: nothing saved is dropped, nothing refused.
        $lw->call('save')->assertHasNoErrors();
    });
    expect(fcsStored(1)['channels'])->toHaveCount(FloatingContact::MAX_CHANNELS);

    // Directions joins the list as soon as a channel is removed.
    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)->call('deleteChannel', 'c15');
        expect(array_column($lw->get('channels'), 'key'))->toContain('maps')->and($lw->get('channels'))->toHaveCount(FloatingContact::MAX_CHANNELS);
        $lw->call('save')->assertHasNoErrors();
    });
    expect(array_column(fcsStored(1)['channels'], 'key'))->toContain('maps')->not->toContain('c15');
});

it('fills directions on a first visit from the first branch or factory that has a usable map link', function (array $factories, string $expected): void {
    $admin = fcsAdmin();
    app(Tenancy::class)->enter(1, function () use ($admin, $factories, $expected): void {
        SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'footer', 'value' => ['factories' => $factories]]);

        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        $maps = array_values(array_filter($lw->get('channels'), fn (array $c): bool => $c['key'] === 'maps'))[0];

        expect($maps['value'])->toBe($expected)->and($maps['on'])->toBeFalse();
    });
})->with([
    'the first factory with a link' => [[['title' => 'Nhà máy 1'], ['title' => 'Nhà máy 2', 'map_url' => 'https://maps.google.com/?q=2'], ['title' => 'Chi nhánh', 'map_url' => 'https://maps.google.com/?q=3']], 'https://maps.google.com/?q=2'],
    'a link that is not http is skipped' => [[['title' => 'A', 'map_url' => 'javascript:alert(1)'], ['title' => 'B', 'map_url' => 'https://maps.app.goo.gl/abc']], 'https://maps.app.goo.gl/abc'],
    'no factory has a link' => [[['title' => 'A']], ''],
    'a route planner to the first factory with coordinates when none has a link' => [[['title' => 'A'], ['title' => 'B', 'lat' => 21.3, 'lng' => 105.86, 'map_query' => 'Nhà máy B']], 'https://www.google.com/maps/dir/?api=1&destination=21.3%2C105.86'],
    'a route planner to the map search text' => [[['title' => 'A', 'map_query' => 'Cơ khí An Vinh']], 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode('Cơ khí An Vinh')],
    'an untitled factory is left out, as the footer does' => [[['map_url' => 'https://maps.google.com/?q=0'], ['title' => 'B', 'map_url' => 'https://maps.google.com/?q=1']], 'https://maps.google.com/?q=1'],
    'no factories' => [[], ''],
]);

it('leaves directions empty on a first visit when the site has no footer settings, and never overwrites a saved value', function (): void {
    $admin = fcsAdmin();
    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        expect(array_values(array_filter($lw->get('channels'), fn (array $c): bool => $c['key'] === 'maps'))[0]['value'])->toBe('');

        SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'footer', 'value' => ['factories' => [['title' => 'A', 'map_url' => 'https://maps.google.com/?q=1']]]]);
        SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'floating_contact', 'value' => ['enabled' => true, 'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678']]]]);

        $saved = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        expect(array_values(array_filter($saved->get('channels'), fn (array $c): bool => $c['key'] === 'maps'))[0]['value'])->toBe('');
    });
});

it('checks the raised call option as a true-or-false field and writes only its off state', function (): void {
    $base = ['enabled' => true, 'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678']]];

    expect(FloatingContactInput::errors($base + ['bar_raise_call' => true], ['vi']))->toBe([])
        ->and(FloatingContactInput::errors($base + ['bar_raise_call' => false], ['vi']))->toBe([])
        ->and(FloatingContactInput::errors($base + ['bar_raise_call' => 'no'], ['vi']))->toBe(["'bar_raise_call' must be true or false."])
        ->and(FloatingContactInput::stored($base, ['vi']))->not->toHaveKey('bar_raise_call')
        ->and(FloatingContactInput::stored($base + ['bar_raise_call' => true], ['vi']))->not->toHaveKey('bar_raise_call')
        ->and(FloatingContactInput::stored($base + ['bar_raise_call' => false], ['vi'])['bar_raise_call'])->toBeFalse()
        ->and(array_column(FloatingContactInput::stored($base, ['vi'])['channels'], 'key'))->toBe(['phone', 'zalo', 'messenger', 'email', 'sms', 'maps']);
});

it('stores a full list of channels without adding a built-in one past the most a site keeps', function (): void {
    $channels = array_map(static fn (int $i): array => ['key' => 'c'.$i, 'on' => false, 'value' => '/trang-'.$i, 'name' => 'T'.$i], range(1, FloatingContact::MAX_CHANNELS));

    expect(FloatingContactInput::stored(['enabled' => true, 'channels' => $channels], ['vi'])['channels'])->toHaveCount(FloatingContact::MAX_CHANNELS);
});

it('draws the phone preview at the visitor phone width and gives every colour swatch one shared named hit area', function (): void {
    $admin = fcsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)->call('setLayout', 'combo')->set('buyOn', true);
        $html = fcsBare($lw);
        $hit = 'inline-flex size-control-row shrink-0 cursor-pointer items-center justify-center rounded-full any-pointer-coarse:size-hit';

        // The phone is as wide as the admin window, clamped to 320-390, and the frame grows to fit it.
        expect($html)->toContain('Math.min(390, Math.max(320, this.win))')
            ->toContain("'max-width:' + (vw + 14) + 'px'");

        // Buy swatches: each a named, pressed-aware button with the shared hit area around its chip.
        expect(preg_match_all('/<button type="button" wire:click="setBuyColor\([^"]*\)"[^>]*aria-pressed="[a-z]+"[^>]*aria-label="[^"]+"[^>]*class="'.preg_quote($hit, '/').'"/', $html))
            ->toBe(count(FloatingContactSettings::BUY_SWATCHES));

        // Channel swatches (the open colour panel of every channel) share the same class, the custom-colour label too.
        expect(substr_count($html, 'class="'.$hit.'"'))->toBeGreaterThan(count(FloatingContactSettings::BUY_SWATCHES))
            ->and($html)->toContain('class="'.$hit.' focus-ring-within relative"')
            ->and($html)->not->toContain('size-[1.625rem] rounded-[7px] any-pointer-coarse:size-11');
    });
});
