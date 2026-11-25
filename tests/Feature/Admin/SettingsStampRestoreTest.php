<?php

use App\Modules\Admin\Livewire\AdminUiSettings;
use App\Modules\Admin\Livewire\CatalogSettings;
use App\Modules\Admin\Livewire\GeneralSettings;
use App\Modules\Admin\Livewire\SeoSettings;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The board's line above a settings section's fields — «Cập nhật lần cuối HH:MM · <người>» (or
 * «Chưa có thay đổi nào được lưu ở mục này») and «Khôi phục mặc định» (in the "⋮" on phones) with
 * «Hoàn tác» — on «Thông tin chung», «Giao diện trang quản trị», «Sản phẩm» and «Google & mạng
 * xã hội». A reset puts back only the choices that have a real default: never the site's name,
 * contact, page titles, descriptions or share image.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_settings restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

function ssrAdmin(int $siteId = 1, string $name = 'Chị Lan'): User
{
    $admin = User::factory()->create(['name' => $name]);
    app(Tenancy::class)->enter($siteId, fn () => grantRole($admin, 'site-admin', ['settings.manage', 'entries.view']));

    return $admin->fresh();
}

/** @return array<string, mixed>|null */
function ssrSetting(string $group, string $key, int $siteId = 1): ?array
{
    return app(Tenancy::class)->enter($siteId, fn (): ?array => ssrRow($group, $key));
}

/**
 * The stored value inside the site's own transaction (the step is not committed yet).
 *
 * @return array<string, mixed>|null
 */
function ssrRow(string $group, string $key): ?array
{
    return SiteSetting::query()->where('group_name', $group)->where('key', $key)->first()?->value;
}

it('draws the stamp line and «Khôi phục mặc định» on each section, and the "⋮" for phones', function (string $path): void {
    $html = (string) $this->actingAs(ssrAdmin())->get('http://a.test/admin/settings/'.$path)->assertOk()->getContent();
    $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    expect(preg_replace('/\s+/u', ' ', trim((string) $dom->querySelector('[data-set-stamp]')?->textContent)))
        ->toBe('Chưa có thay đổi nào được lưu ở mục này Khôi phục mặc định')
        ->and($dom->querySelector('[data-set-stamp]')?->getAttribute('class'))->toContain('max-md:hidden')   // phones: only once saved
        ->and($dom->querySelector('[data-set-restore]')?->getAttribute('wire:click'))->toBe('restoreDefaults')
        ->and($dom->querySelector('[data-set-more]')?->getAttribute('aria-label'))->toStartWith('Việc khác cho mục ');
})->with(['general' => 'general', 'admin-ui' => 'admin-ui', 'catalog' => 'catalog', 'seo' => 'seo', 'appearance' => 'appearance']);

it('names who saved the section last, today by the hour', function (): void {
    $admin = ssrAdmin(1, 'Anh Minh');

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(CatalogSettings::class)
            ->set('mode', 'commerce')->call('save')->assertHasNoErrors()
            ->assertSee('Cập nhật lần cuối')->assertSee('· Anh Minh');
    });
});

it('puts the catalog back to brochure and the products tab on, and «Hoàn tác» brings both back', function (): void {
    $admin = ssrAdmin();
    app(Tenancy::class)->enter(1, function (): void {
        app(SettingsRepository::class)->put('catalog', 'mode', ['value' => 'commerce']);
        app(SettingsRepository::class)->put('storefront', 'header', ['sticky' => false, 'drawer_products' => false]);
    });

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $page = Livewire::actingAs($admin)->test(CatalogSettings::class)->assertSet('mode', 'commerce')
            ->call('restoreDefaults')->assertSet('mode', 'brochure')
            ->assertNotSet('undo', null)->assertSee('Đã khôi phục thiết lập mặc định')->assertSee('Hoàn tác');

        expect(ssrRow('catalog', 'mode'))->toBeNull()
            ->and(ssrRow('storefront', 'header'))->toBe(['sticky' => false]);   // the rest of the header stays

        $page->call('undoRestore')->assertSet('undo', null)->assertSet('mode', 'commerce');
    });

    expect(ssrSetting('catalog', 'mode'))->toBe(['value' => 'commerce'])
        ->and(ssrSetting('storefront', 'header'))->toMatchArray(['sticky' => false, 'drawer_products' => false]);
});

it('puts every admin-look axis back to the platform default and repaints the frame at once', function (): void {
    $admin = ssrAdmin();
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('admin_theme', 'config', ['scheme' => 'dark', 'topbar' => 'dark']));

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $page = Livewire::actingAs($admin)->test(AdminUiSettings::class)->assertSet('scheme', 'dark')
            ->call('restoreDefaults')->assertSet('scheme', '')->assertSet('topbar', '')->assertSet('saved', false)
            ->assertDispatched('admin-chrome-changed');
        expect(ssrRow('admin_theme', 'config'))->toBeNull();

        $page->call('undoRestore')->assertSet('scheme', 'dark')->assertSet('topbar', 'dark')->assertDispatched('admin-chrome-changed');
    });

    expect(ssrSetting('admin_theme', 'config'))->toMatchArray(['scheme' => 'dark', 'topbar' => 'dark']);
});

it('puts back only the time zone and day format on «Thông tin chung»: the name, contact and an unsaved draft stay', function (): void {
    $admin = ssrAdmin();
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(GeneralSettings::class)
        ->set('i18n.vi.name', 'Cơ Khí Mẫu')->set('form.legalName', 'CÔNG TY TNHH CƠ KHÍ MẪU')->set('form.phone', '0901 234 567')
        ->set('form.timezone', 'Asia/Tokyo')->set('form.dateFormat', 'iso')
        ->call('save')->assertHasNoErrors());

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $page = Livewire::actingAs($admin)->test(GeneralSettings::class)
            ->assertSet('form.timezone', 'Asia/Tokyo')->assertSet('form.dateFormat', 'iso')
            ->set('form.legalName', 'CÔNG TY TNHH ĐANG GÕ')   // typed, not saved yet
            ->call('restoreDefaults')
            ->assertSet('form.timezone', 'Asia/Ho_Chi_Minh')->assertSet('form.dateFormat', 'auto')
            ->assertSet('form.legalName', 'CÔNG TY TNHH ĐANG GÕ')
            ->assertSet('saved.shared.timezone', 'Asia/Ho_Chi_Minh')
            ->assertSee('Hoàn tác');

        $profile = SiteProfile::query()->firstOrFail();
        expect(ssrRow('general', 'timezone'))->toBeNull()
            ->and(ssrRow('general', 'date_format'))->toBeNull()
            ->and($profile->phone)->toBe('0901 234 567')
            ->and($profile->legal_name)->toBe('CÔNG TY TNHH CƠ KHÍ MẪU');

        $page->call('undoRestore')->assertSet('form.timezone', 'Asia/Tokyo')->assertSet('form.dateFormat', 'iso')
            ->assertSet('form.legalName', 'CÔNG TY TNHH ĐANG GÕ');
    });

    expect(ssrSetting('general', 'timezone'))->toBe(['tz' => 'Asia/Tokyo']);
});

it('puts back the title pattern, the two switches and the robots lines on SEO, keeping the titles and descriptions', function (): void {
    $admin = ssrAdmin();
    $stored = [
        'titles' => ['vi' => 'Cơ Khí Mẫu — gia công chính xác'], 'descriptions' => ['vi' => 'Gia công CNC.'],
        'template' => '%tieu-de% | Mẫu', 'indexable' => false, 'sitemap' => false, 'robots' => 'Disallow: /tam',
    ];
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('seo', 'config', $stored));

    app(Tenancy::class)->enter(1, function () use ($admin, $stored): void {
        $page = Livewire::actingAs($admin)->test(SeoSettings::class)
            ->set('i18n.vi.description', 'Mô tả đang gõ')
            ->call('restoreDefaults')
            ->assertSet('form.template', '')->assertSet('form.indexable', true)->assertSet('form.sitemap', true)->assertSet('form.robots', '')
            ->assertSet('i18n.vi.description', 'Mô tả đang gõ')
            ->assertSet('saved.i18n.vi.description', 'Gia công CNC.');

        expect(ssrRow('seo', 'config'))->toBe(['titles' => $stored['titles'], 'descriptions' => $stored['descriptions']]);

        $page->call('undoRestore')->assertSet('form.indexable', false)->assertSet('form.robots', 'Disallow: /tam');
    });

    expect(ssrSetting('seo', 'config'))->toMatchArray($stored);
});

it('drops «Hoàn tác» once the section is saved again, so an old undo never overwrites the newer save', function (string $component, string $field, string $restored, string $newer, string $group, string $key, array $stored): void {
    $admin = ssrAdmin();
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put($group, $key, $stored));

    app(Tenancy::class)->enter(1, function () use ($admin, $component, $field, $restored, $newer): void {
        Livewire::actingAs($admin)->test($component)
            ->call('restoreDefaults')->assertSet($field, $restored)->assertNotSet('undo', null)
            ->set($field, $newer)->call('save')->assertHasNoErrors()->assertSet('undo', null)
            ->call('undoRestore')->assertSet($field, $newer);
    });

    expect(ssrSetting($group, $key))->toMatchArray($group === 'catalog' ? ['value' => 'commerce'] : ['scheme' => 'light']);
})->with([
    'catalog' => [CatalogSettings::class, 'mode', 'brochure', 'commerce', 'catalog', 'mode', ['value' => 'commerce']],
    'admin look' => [AdminUiSettings::class, 'scheme', '', 'light', 'admin_theme', 'config', ['scheme' => 'dark']],
]);

it('is refused to a member who lost settings.manage, and resets nothing', function (string $component): void {
    $admin = ssrAdmin();
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('catalog', 'mode', ['value' => 'commerce']));

    app(Tenancy::class)->enter(1, function () use ($admin, $component): void {
        $page = Livewire::actingAs($admin)->test($component);
        $admin->syncPermissions([]);
        $admin->syncRoles([]);
        app(TeamContext::class)->forget();
        $page->call('restoreDefaults')->assertForbidden();
    });

    expect(ssrSetting('catalog', 'mode'))->toBe(['value' => 'commerce']);
})->with([CatalogSettings::class, AdminUiSettings::class, GeneralSettings::class, SeoSettings::class]);

it('leaves another site\'s settings alone', function (): void {
    $admin = ssrAdmin();
    app(Tenancy::class)->enter(2, fn () => app(SettingsRepository::class)->put('admin_theme', 'config', ['scheme' => 'dark']));
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('admin_theme', 'config', ['scheme' => 'dark']));

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(AdminUiSettings::class)->call('restoreDefaults'));

    expect(ssrSetting('admin_theme', 'config', 1))->toBeNull()
        ->and(ssrSetting('admin_theme', 'config', 2))->toBe(['scheme' => 'dark']);
});
