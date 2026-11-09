<?php

use App\Modules\Admin\Livewire\SeoSettings;
use App\Modules\Admin\Support\SeoFields;
use App\Modules\Admin\Support\Settings\SetBrandImages;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale as SiteLocaleModel;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * "Google & mạng xã hội" settings: per language the site's home page title and default
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
});

afterEach(function (): void {
    Livewire::flushState();   // a full-page GET leaves Livewire's asset-injection flag set for the next file's pages
});

/** @param  list<string>  $more  permissions besides settings.manage */
function seoAdmin(int $siteId = 1, array $more = []): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter($siteId, fn () => grantRole($admin, 'site-admin', ['settings.manage', ...$more]));

    return $admin->fresh();
}

/** @return array<string, mixed>|null the stored seo setting of a site */
function seoStored(int $siteId = 1): ?array
{
    return app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->where('group_name', 'seo')->where('key', 'config')->first()?->value);
}

/** A picture in a site's Image library. */
function seoMedia(int $siteId, string $name, string $mime = 'image/jpeg'): Media
{
    return app(Tenancy::class)->enter($siteId, function () use ($name, $mime): Media {
        $hash = hash('sha256', $name.random_bytes(8));

        return Media::query()->create([
            'collection' => 'library', 'slug' => $name, 'hash' => $hash, 'original_filename' => $name.'.jpg',
            'storage_key' => 's/library/'.$name.'-'.substr($hash, 0, 12).'.jpg',
            'mime' => $mime, 'size' => 120_000, 'width' => 1200, 'height' => 630,
        ]);
    });
}

/** The share image id a site's profile holds. */
function seoShareId(int $siteId = 1): ?int
{
    $id = DB::connection('pgsql_admin')->table('site_profiles')->where('site_id', $siteId)->value('og_image_media_id');

    return $id === null ? null : (int) $id;
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

it('names the fields as the home page title and the template, with buttons that add its placeholders', function (): void {
    $admin = seoAdmin();

    $page = $this->actingAs($admin)->get('http://a.test/admin/settings/seo')->assertOk()
        ->assertSee('<title>Google &amp; mạng xã hội', false)
        ->assertDontSee('Hiện trên Google &amp; mạng xã hội', false)
        ->assertSee('Tiêu đề trang chủ')
        ->assertDontSee('Tiêu đề mặc định')
        ->assertSee('Dòng chữ lớn màu xanh khi trang chủ hiện trên Google. Nên dưới 60 ký tự. Bỏ trống thì dùng mẫu tiêu đề.')
        ->assertSee('Áp cho các trang còn lại chưa đặt tiêu đề riêng. Bấm nút để chèn: %tieu-de% sẽ thành tên trang, %ten-website% thành tên website.')
        ->getContent();

    expect($page)->toContain('data-template-insert', "insert('%tieu-de%')", "insert('%ten-website%')", "\$wire.\$set('form.template', next)",
        'aria-label="Chèn tên trang vào mẫu tiêu đề trang con"', '>+ Tên trang<',
        'aria-label="Chèn tên website vào mẫu tiêu đề trang con"', '>+ Tên website<');
});

it('checks a template the insert buttons push past the limit, as if typed and left, and never saves it', function (): void {
    $admin = seoAdmin();
    $cap = SeoFields::hardCap('seoTitle');

    app(Tenancy::class)->enter(1, function () use ($admin, $cap): void {
        // What a button sends: the text so far, one space, the placeholder.
        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->set('form.template', str_repeat('a', $cap - 5).' %ten-website%')
            ->assertHasErrors(['form.template'])
            ->call('save')
            ->assertHasErrors(['form.template'])
            ->set('form.template', 'Trang %tieu-de%')
            ->assertHasNoErrors();
    });

    expect(seoStored())->toBeNull();   // the refused save wrote nothing
});

it('keeps the template read-only without its buttons on a language other than the source', function (): void {
    $admin = seoAdmin();
    seoEnglish();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->assertSeeHtml('data-template-insert')
            ->call('setLang', 'en')
            ->assertDontSeeHtml('data-template-insert')
            ->assertDontSee('Chèn tên trang vào mẫu tiêu đề trang con');
    });
});

it('has no "rebuild the sitemap" action: the sitemap follows the pages by itself', function (): void {
    $admin = seoAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->assertSee('Tạo sitemap.xml tự động')
            ->assertDontSee('Tạo lại sitemap ngay')
            ->assertDontSeeHtml('regenerateSitemap');
    });

    expect(method_exists(SeoSettings::class, 'regenerateSitemap'))->toBeFalse();
});

it('shows the share image as a slot that opens the Image library, with no link to the admin theme page', function (): void {
    $admin = seoAdmin(1, ['media.view']);

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $html = Livewire::actingAs($admin)->test(SeoSettings::class)
            ->assertSee('Ảnh khi chia sẻ lên Facebook, Zalo')
            ->assertSee('Cỡ 1200×630. Hiện khi ai đó gửi liên kết website lên Facebook, Zalo. Bỏ trống thì dùng logo. Ảnh cũ vẫn nằm trong Thư viện ảnh; liên kết đã gửi trước đó có thể còn hiện ảnh cũ một thời gian.')
            ->assertSee('Chọn từ Thư viện ảnh hoặc tải ảnh mới lên')
            ->assertSee('Xem trước khi chia sẻ lên Facebook, Zalo')
            ->assertDontSee('Đặt ở mục Giao diện website')
            ->assertSeeHtml('wire:click="openImagePicker"')
            ->html();

        expect($html)->not->toMatch('~href="[^"]*/admin/settings/appearance"[^>]*data-share~');
    });
});

it('picks the share image into the draft, saves it by its media key and shows it again when the page opens', function (): void {
    config(['media.driver' => 'cloudflare', 'media.cloudflare.base' => 'https://files.test']);
    $admin = seoAdmin(1, ['media.view']);
    $share = seoMedia(1, 'anh-chia-se');
    $other = seoMedia(1, 'anh-khac');

    app(Tenancy::class)->enter(1, function () use ($admin, $share, $other): void {
        $lw = Livewire::actingAs($admin)->test(SeoSettings::class)
            ->call('openImagePicker')->assertSet('showImagePicker', true)
            // One image for the slot: choosing a second replaces the first.
            ->call('togglePickerItem', $other->id)->call('togglePickerItem', $share->id)
            ->assertSet('pickerSelected', [$share->id])
            ->call('insertPicked')
            ->assertSet('showImagePicker', false)
            ->assertSet('shareKey', $share->storage_key)
            ->assertSee('1 thay đổi chưa lưu')
            ->assertSeeHtml('data-unsaved-count="1"')
            ->assertSee('anh-chia-se.jpg')
            ->assertSee('1200×630 px')
            ->assertSeeHtml('aria-label="Gỡ ảnh"');
        // The Facebook/Zalo preview follows the draft before it is saved.
        expect($lw->html())->toContain('<img src="https://files.test/'.$share->storage_key.'?w=1280" alt="Ảnh khi chia sẻ"');

        $lw->call('save')->assertHasNoErrors()->assertSee('Chưa có thay đổi');
    });

    expect(seoShareId())->toBe($share->id)
        ->and(seoStored())->not->toBeNull();

    app(Tenancy::class)->enter(1, function () use ($admin, $share): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->assertSet('shareKey', $share->storage_key)
            ->assertSee('anh-chia-se.jpg')
            ->assertSee('Chưa có thay đổi');
    });
});

it('brings the saved share image back with "Bỏ thay đổi"', function (): void {
    $admin = seoAdmin(1, ['media.view']);
    $saved = seoMedia(1, 'anh-da-luu');
    $picked = seoMedia(1, 'anh-moi');
    app(Tenancy::class)->enter(1, function () use ($admin, $saved): void {
        auth()->setUser($admin);
        app(SetBrandImages::class)->handle(['og_image' => $saved->storage_key]);
    });

    app(Tenancy::class)->enter(1, function () use ($admin, $saved, $picked): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->assertSet('shareKey', $saved->storage_key)
            ->call('openImagePicker')->call('pickImage', $picked->id)
            ->assertSet('shareKey', $picked->storage_key)->assertSee('1 thay đổi chưa lưu')
            ->call('resetChanges')
            ->assertSet('shareKey', $saved->storage_key)->assertSee('Chưa có thay đổi')->assertSee('anh-da-luu.jpg');
    });

    expect(seoShareId())->toBe($saved->id);
});

it('empties the slot with "Gỡ ảnh"; once saved the image is gone from the site and the preview shows the logo', function (): void {
    config(['media.driver' => 'cloudflare', 'media.cloudflare.base' => 'https://files.test']);
    $admin = seoAdmin(1, ['media.view']);
    $share = seoMedia(1, 'anh-chia-se');
    $logo = seoMedia(1, 'logo');
    app(Tenancy::class)->enter(1, function () use ($admin, $share, $logo): void {
        auth()->setUser($admin);
        app(SetBrandImages::class)->handle(['og_image' => $share->storage_key, 'logo' => $logo->storage_key]);
    });

    app(Tenancy::class)->enter(1, function () use ($admin, $share, $logo): void {
        $lw = Livewire::actingAs($admin)->test(SeoSettings::class)
            ->call('removeShareImage')
            ->assertSet('shareKey', '')
            ->assertSee('1 thay đổi chưa lưu')
            ->assertSee('Chọn từ Thư viện ảnh hoặc tải ảnh mới lên');
        expect($lw->html())->toContain($logo->storage_key)->not->toContain($share->storage_key);

        $lw->call('save')->assertHasNoErrors()->assertSee('Chưa có thay đổi');
    });

    // The old picture stays in the library, untouched.
    expect(seoShareId())->toBeNull()
        ->and(app(Tenancy::class)->enter(1, fn () => Media::query()->whereKey($share->id)->exists()))->toBeTrue();
});

it('refuses another site’s picture and a picture in the trash, and then writes nothing', function (string $whose): void {
    $admin = seoAdmin(1, ['media.view']);
    $media = $whose === 'other site' ? seoMedia(2, 'cua-site-khac') : seoMedia(1, 'trong-thung-rac');
    if ($whose === 'trash') {
        app(Tenancy::class)->enter(1, fn () => $media->delete());
    }

    app(Tenancy::class)->enter(1, function () use ($admin, $media): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)
            // The library never hands it over…
            ->call('openImagePicker')->call('pickImage', $media->id)
            ->assertSet('shareKey', '')
            ->call('togglePickerItem', $media->id)->assertSet('pickerSelected', [])
            // …and its key sent by hand is refused on save, with the rest of the draft.
            ->set('shareKey', $media->storage_key)
            ->set('i18n.vi.title', 'Không được lưu')
            ->call('save')
            ->assertHasErrors('share_image')
            ->assertSee('Ảnh này không dùng được');
    });

    expect(seoStored())->toBeNull()
        ->and(seoShareId())->toBeNull();
})->with(['other site', 'trash']);

it('opens the library on pictures only, and refuses a file that is not a picture or an SVG', function (string $mime): void {
    $admin = seoAdmin(1, ['media.view']);
    $file = seoMedia(1, 'tep-khong-dung-duoc', $mime);

    app(Tenancy::class)->enter(1, function () use ($admin, $file): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->call('openImagePicker')->assertSet('pickerType', 'image')
            ->call('pickImage', $file->id)
            ->assertSet('showImagePicker', false)
            ->assertSet('shareKey', '')
            ->assertHasErrors('share_image')
            ->assertSee('Chỉ chọn được ảnh JPG, PNG, WebP hoặc GIF')
            ->assertSee('Chưa có thay đổi');
    });
})->with(['application/pdf', 'image/svg+xml']);

it('shows a share image that is still the older brand URL, keeps it on other saves and removes it with "Gỡ ảnh"', function (): void {
    $admin = seoAdmin(1, ['media.view']);
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'general', 'key' => 'brand', 'value' => ['name' => 'An Vinh', 'og_image' => 'https://old.test/anh-cu.png']]));
    $brand = fn (): array => (array) app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->where('group_name', 'general')->where('key', 'brand')->value('value'));

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(SeoSettings::class)
            ->assertSee('anh-cu.png')->assertSee('Ảnh đặt trước đây')->assertSeeHtml('aria-label="Gỡ ảnh"')
            ->assertSee('Chưa có thay đổi');
        expect($lw->html())->toContain('<img src="https://old.test/anh-cu.png" alt="Ảnh khi chia sẻ"');
        $lw->set('form.template', '%tieu-de%')->call('save')->assertHasNoErrors();
    });
    expect($brand()['og_image'] ?? null)->toBe('https://old.test/anh-cu.png');

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->call('removeShareImage')->assertSee('1 thay đổi chưa lưu')
            ->call('save')->assertHasNoErrors()
            ->assertSee('Chọn từ Thư viện ảnh hoặc tải ảnh mới lên')->assertDontSee('anh-cu.png');
    });
    expect($brand())->toBe(['name' => 'An Vinh'])
        ->and(seoShareId())->toBeNull();
});

it('re-checks the library permission when the picker opens', function (): void {
    $admin = seoAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->assertDontSeeHtml('wire:click="openImagePicker"')
            ->call('openImagePicker')->assertForbidden();
    });
});

it('gates the page — settings.manage on the route, in boot() and on every action', function (string $action): void {
    $admin = seoAdmin();
    $editor = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($editor, 'editor', ['entries.view', 'entries.edit.any']));

    $this->actingAs($admin)->get('http://a.test/admin/settings/seo')->assertOk()->assertSee('Google &amp; mạng xã hội', false);
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
})->with(['save', 'setLang', 'toggleIndexable', 'hideFromGoogle', 'cancelHide', 'toggleSitemap', 'resetChanges', 'dismissToast', 'removeShareImage']);

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

it('hands its change count to the leave dialog, which reads it to decide whether to ask', function (): void {
    $admin = seoAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)
            ->assertSeeHtml('data-bs-lazy="bsUnsaved"')
            ->assertSeeHtml('data-unsaved-count="0"')
            ->assertSee('“Google & mạng xã hội” có thay đổi chưa lưu. Rời trang này bây giờ thì các thay đổi này sẽ mất.')
            ->set('form.template', '%tieu-de% | %ten-website%')->assertSeeHtml('data-unsaved-count="1"')
            ->call('save')->assertHasNoErrors()->assertSeeHtml('data-unsaved-count="0"')
            ->set('form.robots', 'User-agent: *')->assertSeeHtml('data-unsaved-count="1"')
            ->call('resetChanges')->assertSeeHtml('data-unsaved-count="0"')->assertSet('form.robots', '');
    });
});

it('lists the page in the settings menu for settings.manage only', function (): void {
    $admin = seoAdmin();

    $page = $this->actingAs($admin)->get('http://a.test/admin/settings/general')->assertOk()
        ->assertSee(route('admin.settings.seo', [], false))->getContent();
    expect($page)->toMatch('~href="[^"]*/admin/settings/seo"[^>]*>\\s*(?:<[^>]+>\\s*)*Google &amp; mạng xã hội\\s*<~')
        ->not->toContain('Hiện trên Google &amp; mạng xã hội');
});
