<?php

use App\Modules\Admin\Livewire\AdminUiSettings;
use App\Modules\Admin\Livewire\WebsiteAppearanceSettings;
use App\Modules\Admin\Support\Settings\SetBrandImages;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * "Giao diện website": the logo, the logo on a dark background and the favicon, each one picture of
 * the site's Image library, kept in a draft and saved in one step through SetBrandImages. Another
 * site's picture is refused; "Gỡ ảnh" empties a slot; the row above the fields says who saved last
 * and offers "Khôi phục mặc định" with "Hoàn tác". The admin panel's own look moved to
 * "Giao diện trang quản trị" (/settings/admin-ui), freeing the `appearance` key for this page.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    config(['media.driver' => 'cloudflare', 'media.cloudflare.base' => 'https://files.test']);
});

afterEach(function (): void {
    Livewire::flushState();   // a full-page GET leaves Livewire's asset-injection flag set for the next file's pages
});

function lookAdmin(int $siteId = 1, string $name = 'Chị Lan'): User
{
    $admin = User::factory()->create(['name' => $name]);
    app(Tenancy::class)->enter($siteId, fn () => grantRole($admin, 'site-admin', ['settings.manage', 'media.view']));

    return $admin->fresh();
}

function lookMedia(int $siteId, string $slug, string $mime = 'image/png'): Media
{
    return app(Tenancy::class)->enter($siteId, function () use ($slug, $mime): Media {
        $hash = hash('sha256', $slug.'-'.$mime);

        return Media::query()->create([
            'collection' => 'brand', 'slug' => $slug, 'hash' => $hash, 'original_filename' => $slug.'.png',
            'storage_key' => 's/brand/'.$slug.'-'.substr($hash, 0, 12).'.png',
            'mime' => $mime, 'size' => 1_000, 'width' => 512, 'height' => 512,
        ]);
    });
}

/** @return array<string, mixed> the site's profile row, read past RLS */
function lookProfile(int $siteId = 1): array
{
    return (array) DB::connection('pgsql_admin')->table('site_profiles')->where('site_id', $siteId)->first();
}

it('is refused without settings.manage, the website look and the admin panel look alike', function (): void {
    $member = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($member): void {
        grantRole($member, 'editor', ['entries.view', 'media.view']);
        Livewire::actingAs($member->fresh())->test(WebsiteAppearanceSettings::class)->assertForbidden();
        Livewire::actingAs($member->fresh())->test(AdminUiSettings::class)->assertForbidden();
    });

    $this->actingAs($member->fresh())->get('http://a.test/admin/settings/appearance')->assertForbidden();
    $this->actingAs($member->fresh())->get('http://a.test/admin/settings/admin-ui')->assertForbidden();
});

it('refuses every action to a member who loses settings.manage while the page is open', function (string $method, array $args): void {
    $admin = lookAdmin();
    app(Tenancy::class)->enter(1, function () use ($admin, $method, $args): void {
        $page = Livewire::actingAs($admin)->test(WebsiteAppearanceSettings::class);
        $admin->syncPermissions([]);
        $admin->syncRoles([]);
        app(TeamContext::class)->forget();
        $page->call($method, ...$args)->assertForbidden();
    });
})->with([
    'save' => ['save', []],
    'pick' => ['pickFor', ['logo']],
    'remove' => ['removeImage', ['logo']],
    'restore' => ['restoreDefaults', []],
    'undo' => ['undoRestore', []],
]);

it('draws the three images of the design in order, and nothing of the parts not built yet', function (): void {
    $this->actingAs(lookAdmin())->get('http://a.test/admin/settings/appearance')->assertOk()
        ->assertSeeInOrder(['Giao diện website', 'Logo và biểu tượng trên thẻ trình duyệt của website.', 'Chưa có thay đổi nào được lưu ở mục này', 'Khôi phục mặc định', 'Logo &amp; ảnh nhận diện',
            'Logo', 'PNG nền trong suốt, cao tối thiểu 80px. Ảnh ngang, vuông hay dọc đều được — tự canh theo tỷ lệ gốc.',
            'Logo nền tối', 'Dùng ở chân trang nền tối. Bỏ trống thì dùng logo thường.',
            'Biểu tượng trang (favicon)', 'Ảnh vuông 512×512 — hiện trên thẻ trình duyệt và khi khách lưu website ra màn hình điện thoại.',
            'Ảnh khi chia sẻ lên Facebook, Zalo', 'Đặt ở mục Google &amp; mạng xã hội, ngay cạnh phần xem trước khi chia sẻ.', 'Mở mục Google &amp; mạng xã hội',
            'Lưu thay đổi'], false)
        ->assertSee('Chọn từ Thư viện ảnh hoặc tải ảnh mới lên')
        ->assertSee(route('admin.settings.seo'), false)
        ->assertDontSee('Màu thương hiệu')
        ->assertDontSee('Cặp phông chữ')
        ->assertDontSee('Mật độ')
        ->assertDontSee('Xem trước')
        ->assertDontSee('Chờ backend');
});

it('lists both looks in the settings menus under their own names and addresses, and the quick sign-in under its new name', function (): void {
    $html = (string) $this->actingAs(lookAdmin())->get('http://a.test/admin/settings/general')->assertOk()->getContent();

    expect($html)->toContain('href="'.route('admin.settings.appearance').'"', 'href="'.route('admin.settings.admin-ui').'"', 'Giao diện website', 'Giao diện trang quản trị', 'Đăng nhập nhanh')
        ->and(route('admin.settings.admin-ui'))->toEndWith('/admin/settings/admin-ui')
        ->and(route('admin.settings.appearance'))->toEndWith('/admin/settings/appearance')
        ->and($html)->not->toContain('Đăng nhập Social');
    // Each page marks its own entry as the one open (the shell's key).
    $look = (string) $this->get('http://a.test/admin/settings/appearance')->assertOk()->getContent();
    $panel = (string) $this->get('http://a.test/admin/settings/admin-ui')->assertOk()->getContent();
    expect($look)->toMatch('~href="'.preg_quote(route('admin.settings.appearance'), '~').'"[^>]*class="[^"]*bg-admin-primary/10~')
        ->and($panel)->toMatch('~href="'.preg_quote(route('admin.settings.admin-ui'), '~').'"[^>]*class="[^"]*bg-admin-primary/10~');
});

it('still saves the admin panel look to admin_theme.config on its new address', function (): void {
    $admin = lookAdmin();
    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(AdminUiSettings::class)->set('scheme', 'dark')->call('save')->assertHasNoErrors();

        expect(SiteSetting::query()->where('group_name', 'admin_theme')->where('key', 'config')->value('value'))->toBe(['scheme' => 'dark']);
    });
    $this->actingAs($admin)->get('http://a.test/admin/settings/admin-ui')->assertOk()->assertSee('Giao diện trang quản trị');
});

it('picks each image from the library into the draft and saves the three in one step', function (): void {
    $admin = lookAdmin();
    $logo = lookMedia(1, 'logo');
    $dark = lookMedia(1, 'logo-toi');
    $icon = lookMedia(1, 'bieu-tuong');

    app(Tenancy::class)->enter(1, function () use ($admin, $logo, $dark, $icon): void {
        $page = Livewire::actingAs($admin)->test(WebsiteAppearanceSettings::class);
        foreach (['logo' => $logo, 'logo_dark' => $dark, 'favicon' => $icon] as $slot => $media) {
            $page->call('pickFor', $slot)->assertSet('showImagePicker', true)->assertSet('pickerFor', $slot)
                ->call('pickImage', $media->id)->assertSet('showImagePicker', false)->assertSet('images.'.$slot, $media->storage_key);
        }
        // Nothing is written before "Lưu thay đổi".
        expect(lookProfile()['logo_media_id'] ?? null)->toBeNull();
        $page->assertSee('3 thay đổi chưa lưu')->assertSee('logo.png')->assertSee('512×512 px')
            ->call('save')->assertHasNoErrors()->assertSet('toast', 'Đã lưu giao diện website')->assertSee('Chưa có thay đổi');
    });

    expect(lookProfile())->toMatchArray(['logo_media_id' => $logo->id, 'logo_dark_media_id' => $dark->id, 'favicon_media_id' => $icon->id, 'og_image_media_id' => null]);
});

it('removes each image on save, leaving the pictures in the library and the share image alone', function (): void {
    $admin = lookAdmin();
    $logo = lookMedia(1, 'logo');
    $dark = lookMedia(1, 'logo-toi');
    $icon = lookMedia(1, 'bieu-tuong');
    $share = lookMedia(1, 'chia-se');
    $this->actingAs($admin);
    app(Tenancy::class)->enter(1, fn () => app(SetBrandImages::class)->handle([
        'logo' => $logo->storage_key, 'logo_dark' => $dark->storage_key, 'favicon' => $icon->storage_key, 'og_image' => $share->storage_key,
    ]));

    foreach (['logo' => 'logo_media_id', 'logo_dark' => 'logo_dark_media_id', 'favicon' => 'favicon_media_id'] as $slot => $column) {
        app(Tenancy::class)->enter(1, function () use ($admin, $slot): void {
            Livewire::actingAs($admin)->test(WebsiteAppearanceSettings::class)
                ->call('removeImage', $slot)->assertSet('images.'.$slot, '')->assertSee('1 thay đổi chưa lưu')
                ->call('save')->assertHasNoErrors();
        });
        expect(lookProfile()[$column])->toBeNull();
    }

    expect(lookProfile()['og_image_media_id'])->toBe($share->id)
        ->and(DB::connection('pgsql_admin')->table('media')->whereIn('id', [$logo->id, $dark->id, $icon->id])->whereNull('deleted_at')->count())->toBe(3);
});

it('refuses another site\'s picture, from the library and from a tampered draft, and writes no slot', function (): void {
    $admin = lookAdmin();
    $mine = lookMedia(1, 'cua-toi');
    $theirs = lookMedia(2, 'cua-ho');

    app(Tenancy::class)->enter(1, function () use ($admin, $mine, $theirs): void {
        // The library never hands it over: the id does not resolve in this site's scope.
        Livewire::actingAs($admin)->test(WebsiteAppearanceSettings::class)
            ->call('pickFor', 'logo')->call('pickImage', $theirs->id)->assertSet('images.logo', '');

        // A draft sent with its key is refused under the slot, and the valid one beside it waits too.
        Livewire::actingAs($admin)->test(WebsiteAppearanceSettings::class)
            ->set('images.logo', $theirs->storage_key)->set('images.favicon', $mine->storage_key)
            ->call('save')->assertHasErrors(['images.logo'])->assertSet('toastTone', 'error')
            ->assertSee('Ảnh này không dùng được: ảnh đã bị xóa, đang ở thùng rác hoặc không thuộc website này. Hãy chọn ảnh khác.');

        // A draft of another shape is refused whole.
        Livewire::actingAs($admin)->test(WebsiteAppearanceSettings::class)
            ->set('images.og_image', $mine->storage_key)->call('save')->assertHasErrors(['images'])->assertDispatched('look-first-error');
    });

    $profile = lookProfile();
    expect([$profile['logo_media_id'] ?? null, $profile['favicon_media_id'] ?? null, $profile['og_image_media_id'] ?? null])->toBe([null, null, null]);
});

it('refuses a file that is not a picture under the slot it was picked for', function (): void {
    $admin = lookAdmin();
    $pdf = lookMedia(1, 'ho-so', 'application/pdf');

    app(Tenancy::class)->enter(1, function () use ($admin, $pdf): void {
        Livewire::actingAs($admin)->test(WebsiteAppearanceSettings::class)
            ->call('pickFor', 'favicon')->call('pickImage', $pdf->id)
            ->assertSet('images.favicon', '')->assertHasErrors(['images.favicon']);
    });
});

it('shows a logo or favicon still held as the older URL, keeps it on save and removes it with "Gỡ ảnh"', function (): void {
    $admin = lookAdmin();
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'general', 'key' => 'brand'], ['value' => ['name' => 'An Vinh', 'logo' => 'https://old.test/logo-an-vinh.png']]));

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $page = Livewire::actingAs($admin)->test(WebsiteAppearanceSettings::class)
            ->assertSet('images.logo', WebsiteAppearanceSettings::OLD_IMAGE)
            ->assertSee('logo-an-vinh.png')->assertSee('Ảnh đặt trước đây')->assertSee('https://old.test/logo-an-vinh.png', false);
        $page->call('save')->assertHasNoErrors();
        expect(SiteSetting::query()->where('key', 'brand')->value('value'))->toMatchArray(['logo' => 'https://old.test/logo-an-vinh.png']);

        // The marker on a slot that holds no older URL is refused.
        Livewire::actingAs($admin)->test(WebsiteAppearanceSettings::class)
            ->set('images.logo_dark', WebsiteAppearanceSettings::OLD_IMAGE)->call('save')->assertHasErrors(['images']);

        $page->call('removeImage', 'logo')->call('save')->assertHasNoErrors()->assertSet('images.logo', '');
        expect(SiteSetting::query()->where('key', 'brand')->value('value'))->toBe(['name' => 'An Vinh']);
    });
});

it('says when the section was last saved and by whom', function (): void {
    $this->freezeSecond();   // the "HH:MM" read after the save is the minute of the save
    $admin = lookAdmin(1, 'Chị Lan');
    $logo = lookMedia(1, 'logo');

    $this->actingAs($admin)->get('http://a.test/admin/settings/appearance')->assertOk()->assertSee('Chưa có thay đổi nào được lưu ở mục này');

    app(Tenancy::class)->enter(1, function () use ($admin, $logo): void {
        $page = Livewire::actingAs($admin)->test(WebsiteAppearanceSettings::class)
            ->call('pickFor', 'logo')->call('pickImage', $logo->id)->call('save');
        $now = app(SiteTime::class)->now()->format('H:i');
        $page->assertSee('Cập nhật lần cuối '.$now.' · Chị Lan')->assertDontSee('Chưa có thay đổi nào được lưu ở mục này');
    });

    // Another day: the day, in the site's format.
    $this->travel(2)->days();
    $day = app(Tenancy::class)->enter(1, fn (): string => app(SiteTime::class)->day(now()->subDays(2), 'vi'));
    $this->actingAs($admin)->get('http://a.test/admin/settings/appearance')->assertOk()->assertSee('Cập nhật lần cuối '.$day.' · Chị Lan');
});

it('restores the defaults, leaving the share image alone, and brings the images back with "Hoàn tác"', function (): void {
    $admin = lookAdmin();
    $logo = lookMedia(1, 'logo');
    $icon = lookMedia(1, 'bieu-tuong');
    $share = lookMedia(1, 'chia-se');
    $this->actingAs($admin);
    app(Tenancy::class)->enter(1, fn () => app(SetBrandImages::class)->handle(['logo' => $logo->storage_key, 'favicon' => $icon->storage_key, 'og_image' => $share->storage_key]));

    app(Tenancy::class)->enter(1, function () use ($admin, $logo, $icon, $share): void {
        $page = Livewire::actingAs($admin)->test(WebsiteAppearanceSettings::class)
            ->call('restoreDefaults')
            ->assertSet('toast', 'Đã khôi phục thiết lập mặc định')->assertSet('images', ['logo' => '', 'logo_dark' => '', 'favicon' => ''])
            ->assertSee('Hoàn tác');
        // Read inside the request's transaction: the reset is not committed yet.
        expect(SiteProfile::query()->first(['logo_media_id', 'favicon_media_id', 'og_image_media_id'])?->only(['logo_media_id', 'favicon_media_id', 'og_image_media_id']))
            ->toBe(['logo_media_id' => null, 'favicon_media_id' => null, 'og_image_media_id' => $share->id]);

        $page->call('undoRestore')->assertSet('undo', null)->assertSet('images.logo', $logo->storage_key)->assertSet('images.favicon', $icon->storage_key);
    });

    expect(lookProfile())->toMatchArray(['logo_media_id' => $logo->id, 'favicon_media_id' => $icon->id, 'og_image_media_id' => $share->id]);
});

it('lets a member without the Image library save the page but not choose a picture', function (): void {
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'site-admin', ['settings.manage']));
    $admin = $admin->fresh();
    $logo = lookMedia(1, 'logo');

    app(Tenancy::class)->enter(1, function () use ($admin, $logo): void {
        Livewire::actingAs($admin)->test(WebsiteAppearanceSettings::class)
            ->assertSee('Chưa chọn ảnh.')->assertDontSee('Chọn từ Thư viện ảnh hoặc tải ảnh mới lên')
            ->call('pickFor', 'logo')->assertForbidden();
        // A key sent straight into the draft is not looked up for the page, and saving it is still a choice from the library.
        Livewire::actingAs($admin)->test(WebsiteAppearanceSettings::class)
            ->set('images.logo', $logo->storage_key)->assertViewHas('pictures', fn (array $p): bool => $p['logo'] === null)
            ->call('save')->assertForbidden();
    });

    expect(lookProfile()['logo_media_id'] ?? null)->toBeNull();
});

it('shows an older plain-http logo by name only, without loading it on the admin', function (): void {
    $admin = lookAdmin();
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'general', 'key' => 'brand'], ['value' => ['logo' => 'http://old.test/logo-cu.png']]));

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(WebsiteAppearanceSettings::class)
            ->assertSet('images.logo', WebsiteAppearanceSettings::OLD_IMAGE)
            ->assertSee('logo-cu.png')->assertDontSee('src="http://old.test/logo-cu.png"', false);
    });
});

it('clears a slot whose picture went to the trash on the next save', function (): void {
    $admin = lookAdmin();
    $logo = lookMedia(1, 'logo');
    $this->actingAs($admin);
    app(Tenancy::class)->enter(1, fn () => app(SetBrandImages::class)->handle(['logo' => $logo->storage_key]));
    app(Tenancy::class)->enter(1, fn () => Media::query()->whereKey($logo->id)->first()?->delete());
    expect(lookProfile()['logo_media_id'])->toBe($logo->id);   // the trash keeps the reference

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(WebsiteAppearanceSettings::class)
            ->assertSet('images.logo', '')->assertSet('stale', ['logo'])
            ->call('save')->assertHasNoErrors()->assertSet('stale', []);
    });

    expect(lookProfile()['logo_media_id'])->toBeNull();
});

it('drops the "Hoàn tác" offer once the draft changes after a restore', function (): void {
    $admin = lookAdmin();
    $logo = lookMedia(1, 'logo');
    $this->actingAs($admin);
    app(Tenancy::class)->enter(1, fn () => app(SetBrandImages::class)->handle(['logo' => $logo->storage_key]));

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(WebsiteAppearanceSettings::class)
            ->call('restoreDefaults')->assertNotSet('undo', null)
            ->call('pickFor', 'favicon')->assertSet('undo', null);
    });
});

it('keeps the "Hoàn tác" offer when another save holds the settings lock, so the next try can still undo', function (): void {
    $admin = lookAdmin();
    $logo = lookMedia(1, 'logo');
    $this->actingAs($admin);
    app(Tenancy::class)->enter(1, fn () => app(SetBrandImages::class)->handle(['logo' => $logo->storage_key]));

    // each step in its own tenant transaction, so the restore's own locks are gone before the other writer comes
    $page = app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(WebsiteAppearanceSettings::class)
        ->call('restoreDefaults')->assertNotSet('undo', null)->assertSet('images.logo', ''));

    $other = DB::connection('pgsql_admin');
    $other->beginTransaction();
    try {
        $other->statement("set local lock_timeout = '2s'");
        $other->select('select id from sites where id = 1 for no key update');   // a long writer
        app(Tenancy::class)->enter(1, fn () => $page->call('undoRestore')->assertNotSet('undo', null)->assertSet('images.logo', ''));
    } finally {
        $other->rollBack();
    }

    app(Tenancy::class)->enter(1, fn () => $page->call('undoRestore')->assertSet('undo', null)->assertSet('images.logo', $logo->storage_key));
});

it('asks before leaving with unsaved changes, and discards them with "Bỏ thay đổi"', function (): void {
    $admin = lookAdmin();
    $logo = lookMedia(1, 'logo');

    app(Tenancy::class)->enter(1, function () use ($admin, $logo): void {
        Livewire::actingAs($admin)->test(WebsiteAppearanceSettings::class)
            ->call('pickFor', 'logo')->call('pickImage', $logo->id)
            ->assertSeeHtml('data-unsaved-count="1"')
            ->call('resetChanges')->assertSet('images.logo', '')->assertSeeHtml('data-unsaved-count="0"');
    });
});
