<?php

use App\Modules\Admin\Livewire\GeneralSettings;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteIdentity;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale as SiteLocaleModel;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteProfileTranslation;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * "Thông tin chung" settings: the site's business identity (site_profiles + one translation row
 * per language), its timezone and day format (site_settings general). The form starts from what
 * the storefront shows; a save writes the profile and takes the NAP keys out of the older
 * general.brand setting. Fields are checked when left and again on save, with the design's
 * plain messages. settings.manage-gated (route + boot() + the save action).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    gsUnits();
});

afterEach(function (): void {
    $db = DB::connection('pgsql_admin');
    $db->table('site_profiles')->whereIn('province_id', $db->table('provinces')->where('code', 'like', 'Z%')->pluck('id'))->update(['province_id' => null, 'ward_id' => null]);
    $db->table('wards')->where('code', 'like', 'Z%')->delete();
    $db->table('provinces')->where('code', 'like', 'Z%')->delete();
    Livewire::flushState();   // a full-page GET leaves Livewire's asset-injection flag set for the next file's pages
});

/** Two provinces of our own (codes no official unit uses) and their wards. */
function gsUnits(): void
{
    $db = DB::connection('pgsql_admin');
    $db->table('wards')->where('code', 'like', 'Z%')->delete();
    $db->table('provinces')->where('code', 'like', 'Z%')->delete();
    $one = $db->table('provinces')->insertGetId(['code' => 'Z1', 'name' => 'Thành phố Thử Một', 'valid_from' => '2025-07-01']);
    $two = $db->table('provinces')->insertGetId(['code' => 'Z2', 'name' => 'Tỉnh Thử Hai', 'valid_from' => '2025-07-01']);
    $db->table('wards')->insert([
        ['province_id' => $one, 'code' => 'Z101', 'name' => 'Phường Một', 'valid_from' => '2025-07-01'],
        ['province_id' => $one, 'code' => 'Z102', 'name' => 'Xã Hai', 'valid_from' => '2025-07-01'],
        ['province_id' => $two, 'code' => 'Z201', 'name' => 'Xã Ba', 'valid_from' => '2025-07-01'],
    ]);
}

function gsUnit(string $table, string $code): int
{
    return (int) DB::connection('pgsql_admin')->table($table)->where('code', $code)->value('id');
}

function gsAdmin(int $siteId = 1): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter($siteId, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    return $admin->fresh();
}

/** The older one-language brand setting, as An Vinh has it. */
function gsBrand(int $siteId = 1): void
{
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->create(['group_name' => 'general', 'key' => 'brand', 'value' => [
        'name' => 'Cơ Khí Mẫu', 'legal_name' => 'Công ty TNHH Cơ Khí Mẫu', 'address' => 'Số 1 Đường Thử, Xã Mẫu, TP Hà Nội',
        'phone' => '0900 000 000', 'email' => 'lienhe@mau.test', 'tax_id' => '0312345678', 'logo' => 'https://cdn.test/logo.png',
    ]]));
}

/** A valid draft: the required fields filled. */
function gsValid(Testable $lw): Testable
{
    return $lw->set('i18n.vi.name', 'Cơ Khí Mẫu')->set('form.legalName', 'CÔNG TY TNHH CƠ KHÍ MẪU');
}

/** @return array<string, mixed> */
function gsProfile(int $siteId = 1): array
{
    return app(Tenancy::class)->enter($siteId, fn () => SiteProfile::query()->firstOrFail()->toArray());
}

/** @return array<string, mixed> */
function gsTranslation(string $locale, int $siteId = 1): array
{
    return app(Tenancy::class)->enter($siteId, fn () => SiteProfileTranslation::query()->where('locale', $locale)->firstOrFail()->toArray());
}

it('starts from what the site shows, and opening the page writes nothing', function (): void {
    gsBrand();
    $admin = gsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(GeneralSettings::class)
            ->assertOk()
            ->assertSee('Tên & giới thiệu')
            ->assertSee('Chưa có thay đổi')
            ->assertSet('i18n.vi.name', 'Cơ Khí Mẫu')
            ->assertSet('form.legalName', 'Công ty TNHH Cơ Khí Mẫu')
            ->assertSet('form.phone', '0900 000 000')
            ->assertSet('form.taxCode', '0312345678')
            // A one-line address with no catalog province is kept in "Số nhà, đường".
            ->assertSet('i18n.vi.address_line', 'Số 1 Đường Thử, Xã Mẫu, TP Hà Nội')
            ->assertSet('form.timezone', 'Asia/Ho_Chi_Minh')
            ->assertSet('form.dateFormat', 'auto');

        expect(SiteProfile::query()->count())->toBe(0)
            ->and(SiteSetting::query()->where('key', 'brand')->first()->value)->toHaveKey('address');
    });
});

it('saves and loads back each group of fields', function (array $set, array $profile, array $vi): void {
    $admin = gsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $set): void {
        $lw = gsValid(Livewire::actingAs($admin)->test(GeneralSettings::class));
        foreach ($set as $field => $value) {
            $lw->set($field, is_string($value) && str_starts_with($value, 'unit:') ? (string) gsUnit(...explode(':', substr($value, 5))) : $value);
        }
        $lw->call('save')->assertHasNoErrors()->assertSet('toast', 'Đã lưu thông tin chung')->assertSee('Chưa có thay đổi');

        // Reloaded from the database: the same values come back.
        $again = Livewire::actingAs($admin)->test(GeneralSettings::class);
        foreach ($set as $field => $value) {
            $again->assertSet($field, is_string($value) && str_starts_with($value, 'unit:') ? (string) gsUnit(...explode(':', substr($value, 5))) : $value);
        }
    });

    $row = gsProfile();
    foreach ($profile as $column => $value) {
        expect($row[$column])->toEqual(is_string($value) && str_starts_with($value, 'unit:') ? gsUnit(...explode(':', substr($value, 5))) : $value);
    }
    $t = gsTranslation('vi');
    foreach ($vi as $column => $value) {
        expect($t[$column])->toBe($value);
    }
})->with([
    'name' => [['i18n.vi.tagline' => 'Chính xác trong từng chi tiết', 'i18n.vi.description' => 'Xưởng gia công CNC.'],
        ['brand_name' => 'Cơ Khí Mẫu'], ['brand_name' => 'Cơ Khí Mẫu', 'tagline' => 'Chính xác trong từng chi tiết', 'short_description' => 'Xưởng gia công CNC.']],
    'legal' => [['form.taxCode' => '0312345678-001', 'form.representative' => 'Nguyễn Văn An', 'form.licenseNo' => '0312345678',
        'form.licenseDate' => '14/03/2019', 'i18n.vi.license_text' => 'Sở KH&ĐT', 'form.moitOn' => true, 'form.moitUrl' => 'http://online.gov.vn/Home/WebDetails/12345'],
        ['legal_name' => 'CÔNG TY TNHH CƠ KHÍ MẪU', 'tax_code' => '0312345678-001', 'representative' => 'Nguyễn Văn An', 'business_license_no' => '0312345678',
            'license_issued_at' => '2019-03-14T00:00:00.000000Z', 'moit_registered' => true, 'moit_url' => 'http://online.gov.vn/Home/WebDetails/12345'],
        ['license_text' => 'Sở KH&ĐT', 'legal_name' => null, 'representative' => null]],
    'contact' => [['form.phone' => '0901 234 567', 'form.email' => 'lienhe@congty.vn', 'form.provinceId' => 'unit:provinces:Z1',
        'form.wardId' => 'unit:wards:Z102', 'i18n.vi.address_line' => 'Lô A12, đường số 3'],
        ['phone' => '0901 234 567', 'hotline' => '0901 234 567', 'email' => 'lienhe@congty.vn', 'province_id' => 'unit:provinces:Z1',
            'ward_id' => 'unit:wards:Z102', 'province' => 'Thành phố Thử Một', 'ward' => 'Xã Hai', 'address_line' => 'Lô A12, đường số 3'],
        ['address_line' => 'Lô A12, đường số 3']],
    'hours' => [['form.days' => 'mon-sat', 'form.opens' => '07:30', 'form.closes' => '17:30', 'i18n.vi.hours_note' => 'Nghỉ trưa 12:00 – 13:00'],
        ['business_hours' => ['days' => 'mon-sat', 'opens' => '07:30', 'closes' => '17:30']], ['opening_hours_text' => 'Nghỉ trưa 12:00 – 13:00']],
    'social' => [['form.facebook' => 'https://facebook.com/comau', 'form.youtube' => 'https://youtube.com/@comau', 'form.tiktok' => 'https://tiktok.com/@comau', 'form.zalo' => '0901 234 567'],
        ['social_links' => ['facebook' => 'https://facebook.com/comau', 'youtube' => 'https://youtube.com/@comau', 'tiktok' => 'https://tiktok.com/@comau', 'zalo' => '0901 234 567']], []],
]);

it('saves the timezone and the day format to the site settings', function (): void {
    $admin = gsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        gsValid(Livewire::actingAs($admin)->test(GeneralSettings::class))
            ->set('form.timezone', 'Asia/Tokyo')->set('form.dateFormat', 'iso')
            ->call('save')->assertHasNoErrors();

        expect(SiteSetting::query()->where('group_name', 'general')->where('key', 'timezone')->first()->value)->toBe(['tz' => 'Asia/Tokyo'])
            ->and(SiteSetting::query()->where('group_name', 'general')->where('key', 'date_format')->first()->value)->toBe(['format' => 'iso']);
        Livewire::actingAs(User::query()->latest('id')->first())->test(GeneralSettings::class)
            ->assertSet('form.timezone', 'Asia/Tokyo')->assertSet('form.dateFormat', 'iso');
    });
});

it('makes the profile the source on save: the NAP keys leave the brand setting, the logo stays', function (): void {
    gsBrand();
    $admin = gsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(GeneralSettings::class)->set('form.email', '')->call('save')->assertHasNoErrors();

        expect(SiteSetting::query()->where('key', 'brand')->first()->value)->toBe(['logo' => 'https://cdn.test/logo.png'])
            // a value cleared on the screen stays cleared (the old setting no longer fills it)
            ->and(app(SiteIdentity::class)->for('vi'))->not->toHaveKey('email')
            ->and(app(SiteIdentity::class)->for('vi')['name'])->toBe('Cơ Khí Mẫu');
    });
});

it('keeps each language’s own text: English never overwrites Vietnamese, and an empty one falls back', function (): void {
    $admin = gsAdmin();
    app(Tenancy::class)->enter(1, function (): void {
        SiteLocaleModel::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        app(SiteLocale::class)->forget();
    });

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        gsValid(Livewire::actingAs($admin)->test(GeneralSettings::class))
            ->set('i18n.vi.tagline', 'Chính xác trong từng chi tiết')
            ->call('setLang', 'en')
            ->assertSet('lang', 'en')
            ->assertSee('Chung')                              // shared fields are marked and read-only
            ->set('i18n.en.name', 'Sample Engineering')
            ->call('save')->assertHasNoErrors();

        app(SiteLocale::class)->forget();
        $en = app(SiteIdentity::class)->for('en');
        $vi = app(SiteIdentity::class)->for('vi');
        expect($vi['name'])->toBe('Cơ Khí Mẫu')
            ->and($en['name'])->toBe('Sample Engineering')
            ->and($en['tagline'])->toBe('Chính xác trong từng chi tiết');   // no English tagline → the Vietnamese one
    });

    expect(gsTranslation('en')['tagline'])->toBeNull()
        ->and(gsTranslation('vi')['brand_name'])->toBe('Cơ Khí Mẫu');
});

it('shows the design’s message on the field when it is left', function (string $field, mixed $value, string $message): void {
    $admin = gsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $field, $value, $message): void {
        $lw = gsValid(Livewire::actingAs($admin)->test(GeneralSettings::class));
        if ($field === 'form.moitUrl') {
            $lw->call('toggleMoit');
        }
        $lw->set($field, $value)
            ->assertHasErrors([$field])
            ->assertSee($message);
        expect($lw->errors()->first($field))->toBe($message);

        $lw->call('save')->assertSee('Còn 1 mục chưa hợp lệ — hãy sửa trước khi lưu.')->assertDispatched('gs-first-error');
        expect(SiteProfile::query()->count())->toBe(0);
    });
})->with([
    'name required' => ['i18n.vi.name', '', 'Nhập tên khách sẽ thấy, ví dụ “Cơ Khí An Vinh”.'],
    'legal name required' => ['form.legalName', '', 'Nhập đúng tên trên giấy phép kinh doanh, ví dụ “CÔNG TY TNHH CƠ KHÍ CHÍNH XÁC AN VINH”.'],
    'tax code' => ['form.taxCode', '03123', 'Mã số thuế gồm 10 số, chi nhánh thêm “-001”. Ví dụ 0312345678.'],
    'date shape' => ['form.licenseDate', '2019-03-14', 'Ghi ngày theo ngày/tháng/năm, ví dụ 14/03/2019.'],
    'no such day' => ['form.licenseDate', '31/02/2019', 'Ghi ngày theo ngày/tháng/năm, ví dụ 14/03/2019.'],
    'url scheme' => ['form.facebook', 'javascript:alert(1)', 'Đường dẫn phải bắt đầu bằng https:// và là một địa chỉ hợp lệ.'],
    'url ftp' => ['form.youtube', 'ftp://youtube.com/x', 'Đường dẫn phải bắt đầu bằng https:// và là một địa chỉ hợp lệ.'],
    'phone' => ['form.phone', '12345', 'Số điện thoại Việt Nam bắt đầu bằng 0 hoặc +84.'],
    'phone of dots' => ['form.phone', '0.........', 'Số điện thoại Việt Nam bắt đầu bằng 0 hoặc +84.'],
    'phone too few digits' => ['form.phone', '0 12 34 56', 'Số điện thoại Việt Nam bắt đầu bằng 0 hoặc +84.'],
    'email' => ['form.email', 'lienhe@', 'Địa chỉ email chưa đúng định dạng.'],
    'moit url required when on' => ['form.moitUrl', '', 'Dán liên kết trang xác nhận, ví dụ http://online.gov.vn/Home/WebDetails/12345.'],
    'zalo' => ['form.zalo', 'abc', 'Ghi số điện thoại di động dùng Zalo, mã Zalo OA (chỉ gồm số) hoặc liên kết https://zalo.me/….'],
    'too long' => ['i18n.vi.tagline', str_repeat('a', 256), 'Tối đa 255 ký tự.'],
]);

it('wants the closing time after the opening time, and keeps the error until it is fixed', function (): void {
    $admin = gsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = gsValid(Livewire::actingAs($admin)->test(GeneralSettings::class))
            ->set('form.days', 'mon-fri')->set('form.opens', '09:00')->set('form.closes', '08:00')
            ->assertHasErrors(['form.closes'])
            ->assertSee('Giờ đóng cửa phải sau giờ mở cửa (09:00).')
            ->set('form.phone', '0901 234 567')
            ->assertHasErrors(['form.closes']);      // another field left: this error stays

        $lw->set('form.closes', '17:00')->assertHasNoErrors(['form.closes']);
        // "Theo lịch hẹn" has no times to compare
        $lw->set('form.days', 'appointment')->set('form.closes', '06:00')->assertHasNoErrors(['form.closes'])
            ->call('save')->assertHasNoErrors();
    });

    expect(gsProfile()['business_hours'])->toBe(['days' => 'appointment']);
});

it('needs the MOIT link only while the switch is on, and forgets it when switched off', function (): void {
    $admin = gsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        gsValid(Livewire::actingAs($admin)->test(GeneralSettings::class))
            ->assertDontSee('Liên kết xác nhận của Bộ Công Thương')
            ->call('toggleMoit')->assertSee('Liên kết xác nhận của Bộ Công Thương')
            ->call('save')->assertHasErrors(['form.moitUrl'])
            ->call('toggleMoit')->assertHasNoErrors(['form.moitUrl'])
            ->call('save')->assertHasNoErrors();
    });

    expect(gsProfile())->moit_registered->toBeFalse()->moit_url->toBeNull();
});

it('refuses a ward of another province, checked on the server', function (): void {
    $admin = gsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = gsValid(Livewire::actingAs($admin)->test(GeneralSettings::class))
            ->set('form.provinceId', (string) gsUnit('provinces', 'Z1'))
            ->assertSee('Phường Một')->assertDontSee('Xã Ba')           // only that province's wards are listed
            ->set('form.wardId', (string) gsUnit('wards', 'Z201'))      // a ward of Tỉnh Thử Hai, sent by hand
            ->assertHasErrors(['form.wardId'])
            ->assertSee('Chọn Phường/Xã thuộc Thành phố Thử Một.');
        $lw->call('save')->assertHasErrors(['form.wardId']);

        // a ward without a province, and a province that is not in the list
        $lw->set('form.provinceId', '')->set('form.wardId', (string) gsUnit('wards', 'Z101'))->assertSee('Chọn Tỉnh/Thành phố trước.');
        $lw->set('form.provinceId', '999999')->assertSee('Chọn Tỉnh/Thành phố trong danh sách.');

        // changing the province empties the ward
        $lw->set('form.provinceId', (string) gsUnit('provinces', 'Z2'))->assertSet('form.wardId', '');
    });

    expect(app(Tenancy::class)->enter(1, fn () => SiteProfile::query()->count()))->toBe(0);
});

it('refuses a retired ward, checked on the server', function (): void {
    $admin = gsAdmin();
    DB::connection('pgsql_admin')->table('wards')->where('code', 'Z102')->update(['valid_to' => '2025-07-01']);

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        gsValid(Livewire::actingAs($admin)->test(GeneralSettings::class))
            ->set('form.provinceId', (string) gsUnit('provinces', 'Z1'))
            ->assertDontSee('Xã Hai')
            ->set('form.wardId', (string) gsUnit('wards', 'Z102'))
            ->assertHasErrors(['form.wardId'])
            ->call('save')->assertHasErrors(['form.wardId']);
        expect(SiteProfile::query()->count())->toBe(0);
    });
});

it('refuses a tampered draft: a value no control can produce', function (string $field, mixed $value): void {
    $admin = gsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $field, $value): void {
        gsValid(Livewire::actingAs($admin)->test(GeneralSettings::class))
            ->set($field, $value)->call('save')
            ->assertHasErrors([$field]);
        expect(SiteProfile::query()->count())->toBe(0);
    });
})->with([
    'timezone' => ['form.timezone', 'Mars/Olympus'],
    'date format' => ['form.dateFormat', 'yyyy'],
    'days' => ['form.days', 'weekends'],
    'opening time' => ['form.opens', '08:15'],
    'an array for a text' => ['form.taxCode', ['x']],
]);

it('saves for the current site only', function (): void {
    gsBrand(2);
    $admin = gsAdmin();
    $before = app(Tenancy::class)->enter(2, fn () => SiteSetting::query()->orderBy('id')->get(['key', 'value'])->toArray());

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        gsValid(Livewire::actingAs($admin)->test(GeneralSettings::class))
            ->set('form.timezone', 'Asia/Tokyo')->call('save')->assertHasNoErrors();
    });

    expect(DB::connection('pgsql_admin')->table('site_profiles')->pluck('site_id')->all())->toBe([1])
        ->and(DB::connection('pgsql_admin')->table('site_profile_translations')->pluck('site_id')->unique()->values()->all())->toBe([1])
        ->and(app(Tenancy::class)->enter(2, fn () => SiteSetting::query()->orderBy('id')->get(['key', 'value'])->toArray()))->toBe($before);
});

it('keeps the address when the province list is not loaded', function (): void {
    gsBrand();
    $admin = gsAdmin();
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->where('key', 'brand')->update(['value' => json_encode([
        'name' => 'Cơ Khí Mẫu', 'legal_name' => 'Công ty TNHH Cơ Khí Mẫu', 'address_line' => 'Số 1 Đường Thử', 'ward' => 'Xã Mẫu', 'province' => 'TP Không Có',
    ])]));

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(GeneralSettings::class)
            ->assertSet('form.provinceId', '')
            ->call('save')->assertHasNoErrors();

        expect(app(SiteIdentity::class)->for('vi')['address'])->toBe('Số 1 Đường Thử, Xã Mẫu, TP Không Có');
    });
});

it('discards the draft back to the saved values', function (): void {
    gsBrand();
    $admin = gsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(GeneralSettings::class)
            ->set('form.phone', '0911 111 111')->assertSee('1 thay đổi chưa lưu')
            ->call('resetChanges')
            ->assertSet('form.phone', '0900 000 000')->assertSee('Chưa có thay đổi');
    });
});

it('gates the page — settings.manage on the route, in boot() and on save', function (): void {
    $admin = gsAdmin();
    $editor = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($editor, 'editor', ['entries.view', 'entries.edit.any']));

    $this->actingAs($admin)->get('http://a.test/admin/settings/general')->assertOk()->assertSee('Thông tin chung');
    $this->actingAs($editor->fresh())->get('http://a.test/admin/settings/general')->assertForbidden();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        Livewire::actingAs($editor->fresh())->test(GeneralSettings::class)->assertForbidden();
    });

    expect(DB::connection('pgsql_admin')->table('site_profiles')->count())->toBe(0);
});

it('lists the page in the settings menu for settings.manage only', function (): void {
    $admin = gsAdmin();

    $this->actingAs($admin)->get('http://a.test/admin/settings/floating-contact')->assertOk()
        ->assertSee(route('admin.settings.general', [], false));
});
