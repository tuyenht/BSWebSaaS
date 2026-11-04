<?php

use App\Modules\Admin\Livewire\SeoSettings;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale as SiteLocaleModel;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * "Hiện trên Google & mạng xã hội" settings: per language the site's default Google title and
 * description, a shared title template, the "let Google find the website" and sitemap switches and
 * a custom robots.txt, all in one site_settings row (group seo, key config). Fields are checked when
 * left and again on save; turning Google off asks first. settings.manage-gated (route, boot() and
 * every action).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    RateLimiter::clear('seo-sitemap:1');
});

afterEach(function (): void {
    Livewire::flushState();   // a full-page GET leaves Livewire's asset-injection flag set for the next file's pages
});

function seoAdmin(int $siteId = 1): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter($siteId, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    return $admin->fresh();
}

/** @return array<string, mixed>|null the stored seo setting of a site */
function seoStored(int $siteId = 1): ?array
{
    return app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->where('group_name', 'seo')->where('key', 'config')->first()?->value);
}

function seoEnglish(int $siteId = 1): void
{
    app(Tenancy::class)->enter($siteId, function (): void {
        SiteLocaleModel::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        app(SiteLocale::class)->forget();
    });
}

it('starts from the defaults that keep today’s output, and opening the page writes nothing', function (): void {
    $admin = seoAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->assertOk()
            ->assertSee('Hiện trên Google')
            ->assertSee('Chưa có thay đổi')
            ->assertSet('i18n.vi.title', '')
            ->assertSet('form.template', '')
            ->assertSet('form.indexable', true)
            ->assertSet('form.sitemap', true)
            ->assertSet('form.robots', '');
    });

    expect(seoStored())->toBeNull();
});

it('saves and loads back each field', function (): void {
    $admin = seoAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->set('i18n.vi.title', 'Cơ Khí Mẫu — gia công chính xác')
            ->set('i18n.vi.description', 'Gia công CNC, tiện, phay chính xác cao.')
            ->set('form.template', '%tieu-de% | %ten-website%')
            ->call('toggleSitemap')
            ->set('form.robots', "User-agent: *\nDisallow: /gio-hang")
            ->assertSee('5 thay đổi chưa lưu')
            ->call('save')->assertHasNoErrors()
            ->assertSee('Chưa có thay đổi');

        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->assertSet('i18n.vi.title', 'Cơ Khí Mẫu — gia công chính xác')
            ->assertSet('i18n.vi.description', 'Gia công CNC, tiện, phay chính xác cao.')
            ->assertSet('form.template', '%tieu-de% | %ten-website%')
            ->assertSet('form.sitemap', false)
            ->assertSet('form.robots', "User-agent: *\nDisallow: /gio-hang");
    });

    expect(seoStored())->toMatchArray([
        'titles' => ['vi' => 'Cơ Khí Mẫu — gia công chính xác'],
        'descriptions' => ['vi' => 'Gia công CNC, tiện, phay chính xác cao.'],
        'template' => '%tieu-de% | %ten-website%',
        'indexable' => true,
        'sitemap' => false,
        'robots' => "User-agent: *\nDisallow: /gio-hang",
    ]);
});

it('keeps each language’s own text: an English save leaves Vietnamese untouched', function (): void {
    $admin = seoAdmin();
    seoEnglish();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->set('i18n.vi.title', 'Cơ Khí Mẫu')->set('i18n.vi.description', 'Mô tả tiếng Việt')
            ->call('save')->assertHasNoErrors();

        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->call('setLang', 'en')
            ->assertSet('lang', 'en')
            ->assertSee('Chung')                              // the shared fields are marked and read-only
            ->set('i18n.en.title', 'Sample Engineering')
            ->call('save')->assertHasNoErrors();
    });

    expect(seoStored()['titles'])->toMatchArray(['vi' => 'Cơ Khí Mẫu', 'en' => 'Sample Engineering'])
        ->and(seoStored()['descriptions'])->toBe(['vi' => 'Mô tả tiếng Việt']);
});

it('keeps the titles one line and robots.txt plain text, and refuses what is too long', function (): void {
    $admin = seoAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->set('i18n.vi.title', "Dòng một\nDòng\u{200B} hai\u{0007}")
            ->set('form.robots', "User-agent: *\r\nDisallow: /a\u{0000}\u{001B}\n\tAllow: /b")
            ->call('save')->assertHasNoErrors();

        expect(seoStored()['titles']['vi'])->toBe('Dòng một Dòng hai')
            ->and(seoStored()['robots'])->toBe("User-agent: *\nDisallow: /a\n\tAllow: /b");

        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->set('form.robots', str_repeat('a', 10001))
            ->assertHasErrors(['form.robots'])
            ->assertSee('Tối đa 10.000 ký tự')
            ->set('form.robots', str_repeat('a', 10000))
            ->assertHasNoErrors()
            ->set('i18n.vi.title', str_repeat('x', 101))
            ->call('save')
            ->assertHasErrors(['i18n.vi.title']);
    });

    expect(mb_strlen((string) seoStored()['robots']))->toBe(37);   // the long values were never saved
});

it('asks before hiding the site from Google, and turns it back on without asking', function (): void {
    $admin = seoAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->call('toggleIndexable')
            ->assertSet('confirmHide', true)
            ->assertSet('form.indexable', true)
            ->assertSee('Google sẽ gỡ website khỏi kết quả tìm kiếm')
            ->call('cancelHide')
            ->assertSet('confirmHide', false)
            ->assertSet('form.indexable', true)
            ->call('toggleIndexable')
            ->call('hideFromGoogle')
            ->assertSet('form.indexable', false)
            ->assertDontSee('Tạo sitemap.xml tự động')            // the sitemap switch depends on Google
            ->call('save')->assertHasNoErrors()
            ->call('toggleIndexable')
            ->assertSet('confirmHide', false)
            ->assertSet('form.indexable', true);
    });

    expect(seoStored()['indexable'])->toBeFalse();
});

it('shows the platform-subdomain sentence only while the platform hides its subdomains', function (): void {
    $admin = seoAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)->assertDontSee('Tên miền phụ của nền tảng luôn ẩn với Google');
        config(['tenancy.noindex_platform_subdomains' => true]);
        Livewire::actingAs($admin)->test(SeoSettings::class)->assertSee('Tên miền phụ của nền tảng luôn ẩn với Google');
    });
});

it('rebuilds a cached sitemap plan, says plainly when there is nothing to rebuild, and limits the tries', function (): void {
    $admin = seoAdmin();
    $key = 'sitemap-plan:v1:1:'.max(1, min(50000, (int) config('cms.sitemap.max_urls', 50000)));

    app(Tenancy::class)->enter(1, function () use ($admin, $key): void {
        $lw = Livewire::actingAs($admin)->test(SeoSettings::class);
        $lw->call('regenerateSitemap')->assertSet('toastTone', 'success')->assertSee('Sitemap đã mới nhất');

        Cache::put($key, [['/a', 1], ['/m', 9]], 600);
        $lw->call('regenerateSitemap')->assertSee('Đã làm mới sitemap');
        expect(Cache::get($key))->toBeNull();

        $lw->call('regenerateSitemap')->assertSet('toastTone', 'success');                     // three tries per ten minutes
        $lw->call('regenerateSitemap')->assertSet('toastTone', 'error')->assertSee('Bạn vừa tạo lại sitemap');
    });
});

it('gates the page — settings.manage on the route, in boot() and on every action', function (string $action): void {
    $admin = seoAdmin();
    $editor = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($editor, 'editor', ['entries.view', 'entries.edit.any']));

    $this->actingAs($admin)->get('http://a.test/admin/settings/seo')->assertOk()->assertSee('Hiện trên Google &amp; mạng xã hội', false);
    $this->actingAs($editor->fresh())->get('http://a.test/admin/settings/seo')->assertForbidden();

    app(Tenancy::class)->enter(1, function () use ($admin, $editor, $action): void {
        Livewire::actingAs($editor->fresh())->test(SeoSettings::class)->assertForbidden();

        // A page opened by someone allowed, then called after the permission is gone.
        $lw = Livewire::actingAs($admin)->test(SeoSettings::class)->set('i18n.vi.title', 'Bị chặn');
        $admin->roles()->firstOrFail()->revokePermissionTo('settings.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        auth()->setUser($admin->fresh());   // the next request reads the member afresh
        $lw->call($action)->assertForbidden();
    });

    expect(seoStored())->toBeNull();
})->with(['save', 'setLang', 'toggleIndexable', 'hideFromGoogle', 'cancelHide', 'toggleSitemap', 'regenerateSitemap', 'resetChanges', 'dismissToast']);

it('saves for the current site only', function (): void {
    $admin = seoAdmin();
    app(Tenancy::class)->enter(2, fn () => SiteSetting::query()->create(['group_name' => 'seo', 'key' => 'config', 'value' => ['titles' => ['vi' => 'Trang B']]]));

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->assertSet('i18n.vi.title', '')
            ->set('i18n.vi.title', 'Trang A')->call('save')->assertHasNoErrors();
    });

    expect(seoStored(1)['titles'])->toBe(['vi' => 'Trang A'])
        ->and(seoStored(2)['titles'])->toBe(['vi' => 'Trang B'])
        ->and(DB::connection('pgsql_admin')->table('site_settings')->where('group_name', 'seo')->count())->toBe(2);
});

it('shows a save on the website at once, though robots.txt reads a shared copy', function (): void {
    $admin = seoAdmin();
    seedHomepage(1, 'Trang chủ');
    $this->get('http://a.test/robots.txt')->assertContent("User-agent: *\nDisallow:\nSitemap: http://a.test/sitemap.xml");   // now cached

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->call('toggleIndexable')->call('hideFromGoogle')->call('save')->assertHasNoErrors();
    });

    $this->get('http://a.test/robots.txt')->assertContent("User-agent: *\nDisallow: /");
    $this->get('http://a.test/sitemap.xml')->assertNotFound();
    expect($this->get('http://a.test/')->getContent())->toContain('<meta name="robots" content="noindex, nofollow">');
});

it('discards the draft back to the saved values', function (): void {
    $admin = seoAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->set('form.template', '%tieu-de%')->assertSee('1 thay đổi chưa lưu')
            ->call('resetChanges')
            ->assertSet('form.template', '')->assertSee('Chưa có thay đổi');
    });
});

it('lists the page in the settings menu for settings.manage only', function (): void {
    $admin = seoAdmin();

    $this->actingAs($admin)->get('http://a.test/admin/settings/general')->assertOk()
        ->assertSee(route('admin.settings.seo', [], false));
});
