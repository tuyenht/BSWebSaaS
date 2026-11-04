<?php

use App\Modules\Admin\Support\GeneralSettingsStore;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteProfileTranslation;
use App\Modules\Tenancy\Models\SiteSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * What the "Thông tin chung" screen saves, as the storefront prints it: the tagline, the opening
 * hours with their note, the Bộ Công Thương notice link, Zalo, the licence issuer and date in the
 * site's day format, and openingHoursSpecification in the JSON-LD. An empty value prints nothing,
 * and a site that only has the older brand setting looks the same until the screen is saved.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, routes, entry_translations, entries,
         site_profile_translations, site_profiles, site_settings, site_locales,
         site_modules, content_sections, domains, sites, tenants, tenant_users, users
         restart identity cascade'
    );
    truncateRbac();
    gisUnitsClear();
});

afterEach(function (): void {
    gisUnitsClear();
});

/** Drops the units of our own (codes Y…, which no official unit uses). */
function gisUnitsClear(): void
{
    $db = DB::connection('pgsql_admin');
    $db->table('site_profiles')->whereIn('province_id', $db->table('provinces')->where('code', 'like', 'Y%')->pluck('id'))->update(['province_id' => null, 'ward_id' => null]);
    $db->table('wards')->where('code', 'like', 'Y%')->delete();
    $db->table('provinces')->where('code', 'like', 'Y%')->delete();
}

function gisUnit(string $table, string $code): int
{
    return (int) DB::connection('pgsql_admin')->table($table)->where('code', $code)->value('id');
}

/**
 * An Vinh as it is today: the profile holds the full legal name and the split address, the
 * older brand setting the shortened name the site shows and the one-line address; the catalog
 * has "Thành phố Hà Nội" for the site's "TP Hà Nội", and another province to pick.
 */
function gisAnVinh(): int
{
    $siteId = gisSite();
    $db = DB::connection('pgsql_admin');
    $hanoi = $db->table('provinces')->insertGetId(['code' => 'Y1', 'name' => 'Thành phố Hà Nội', 'valid_from' => '2025-07-01']);
    $other = $db->table('provinces')->insertGetId(['code' => 'Y2', 'name' => 'Tỉnh Thử Khác', 'valid_from' => '2025-07-01']);
    $db->table('wards')->insert([
        ['province_id' => $hanoi, 'code' => 'Y101', 'name' => 'Xã Trung Giã', 'valid_from' => '2025-07-01'],
        ['province_id' => $other, 'code' => 'Y201', 'name' => 'Phường Thử Khác', 'valid_from' => '2025-07-01'],
    ]);

    app(Tenancy::class)->enter($siteId, function (): void {
        $profile = SiteProfile::query()->firstOrCreate([], []);
        $profile->fill([
            'brand_name' => 'Cơ Khí Chính Xác An Vinh', 'legal_name' => 'CÔNG TY TNHH THƯƠNG MẠI VÀ DỊCH VỤ THIẾT KẾ GIA CÔNG CƠ KHÍ AN VINH',
            'tax_code' => '0108649706', 'business_license_no' => '0108649706', 'phone' => '0915 66 83 83', 'hotline' => '0915 66 83 83',
            'email' => 'quybv@ckanvinh.vn', 'address_line' => 'Thôn Xuân Sơn', 'ward' => 'Xã Trung Giã', 'province' => 'TP Hà Nội',
        ])->save();
        $profile->translations()->updateOrCreate(['locale' => 'vi'], ['brand_name' => 'Cơ Khí Chính Xác An Vinh', 'short_description' => 'Dẫn đầu về giải pháp cơ khí chính xác.']);
        SiteSetting::query()->updateOrCreate(['group_name' => 'general', 'key' => 'brand'], ['value' => [
            'name' => 'Cơ Khí Chính Xác An Vinh', 'legal_name' => 'Công ty TNHH TM & DV Thiết Kế Gia Công Cơ Khí An Vinh',
            'logo' => 'https://files.baoson.net/logo.png', 'description' => 'Dẫn đầu về giải pháp cơ khí chính xác.',
            'address' => 'Thôn Xuân Sơn, Xã Trung Giã, TP Hà Nội', 'address_line' => 'Thôn Xuân Sơn', 'ward' => 'Xã Trung Giã', 'province' => 'TP Hà Nội',
            'phone' => '0915 66 83 83', 'email' => 'quybv@ckanvinh.vn', 'license' => '0108649706', 'tax_id' => '0108649706',
        ]]);
    });

    return $siteId;
}

/**
 * Replaces the older brand setting's values (the logo stays).
 *
 * @param  array<string, mixed>  $brand
 */
function gisBrand(int $siteId, array $brand): void
{
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->where('key', 'brand')->firstOrFail()
        ->update(['value' => [...$brand, 'logo' => 'https://files.baoson.net/logo.png']]));
}

/** @return array<string, mixed> */
function gisProfile(int $siteId): array
{
    return app(Tenancy::class)->enter($siteId, fn () => SiteProfile::query()->firstOrFail()->toArray());
}

/** A provisioned site with the older brand setting only, as An Vinh has it today. */
function gisSite(): int
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Cơ Khí Mẫu', siteName: 'Cơ Khí Mẫu', hostname: 'comau.platform.test',
        ownerEmail: 'owner@comau.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));
    $siteId = Site::query()->firstWhere('name', 'Cơ Khí Mẫu')->id;

    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'general', 'key' => 'brand'], ['value' => [
        'name' => 'Cơ Khí Mẫu', 'legal_name' => 'Công ty TNHH Cơ Khí Mẫu', 'address' => 'Số 1 Đường Thử, TP Hà Nội',
        'phone' => '0901 234 567', 'email' => 'lienhe@comau.test', 'tax_id' => '0312345678', 'license' => '0312345678',
        'logo' => 'https://files.baoson.net/logo.png',
    ]]));

    return $siteId;
}

/** Turns on the rich footer (storefront.footer), with an issuer of its own. */
function gisRichFooter(int $siteId): void
{
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'footer'], ['value' => [
        'legal' => ['license_issuer' => 'Sở cũ', 'show_contact' => true],
    ]]));
}

/**
 * Save through the screen's store, starting from what it loads, with some values changed.
 *
 * @param  array<string, string|bool>  $shared
 * @param  array<string, string>  $vi
 */
function gisSave(int $siteId, array $shared = [], array $vi = []): void
{
    app(Tenancy::class)->enter($siteId, function () use ($shared, $vi): void {
        $store = app(GeneralSettingsStore::class);
        $draft = $store->load();
        $draft['shared'] = array_replace($draft['shared'], $shared);
        $draft['i18n']['vi'] = array_replace($draft['i18n']['vi'], $vi);
        $date = DateTimeImmutable::createFromFormat('!d/m/Y', (string) $draft['shared']['licenseDate']);
        $draft['shared']['licenseDate'] = $date === false ? '' : $date->format('Y-m-d');
        $store->save($draft['i18n'], $draft['shared']);
    });
}

function gisHome(): string
{
    return test()->get('http://comau.platform.test/')->assertOk()->getContent();
}

function gisFooter(string $html): string
{
    preg_match('/<footer.*?<\/footer>/s', $html, $m);

    return $m[0] ?? '';
}

/** @return array<string, mixed> */
function gisLd(string $html): array
{
    preg_match('/<script type="application\/ld\+json">(\{"@context":"https:\/\/schema.org","@type":"(?:Organization|LocalBusiness)".*?)<\/script>/s', $html, $m);

    return json_decode($m[1] ?? '{}', true);
}

it('looks the same before any save, and after saving the form untouched', function (bool $rich): void {
    $siteId = gisSite();
    if ($rich) {
        gisRichFooter($siteId);
    }
    $before = gisHome();
    $footer = gisFooter($before);

    expect($footer)->toContain('Số 1 Đường Thử, TP Hà Nội')->toContain('0901 234 567')->toContain('lienhe@comau.test')
        ->not->toContain('Giờ làm việc')->not->toContain('Bộ Công Thương')->not->toContain('tagline');

    // Opening the screen writes nothing; saving it as loaded moves the values into the profile.
    app(Tenancy::class)->enter($siteId, fn () => app(GeneralSettingsStore::class)->load());
    expect(gisFooter(gisHome()))->toBe($footer);

    gisSave($siteId);
    $after = gisHome();
    expect(app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->where('key', 'brand')->first()->value))->toBe(['logo' => 'https://files.baoson.net/logo.png'])
        ->and(gisFooter($after))->toBe($footer)
        ->and(gisLd($after))->toBe(gisLd($before));
})->with(['classic footer' => false, 'rich footer' => true]);

it('prints the saved tagline, hours, notice link, Zalo and licence issuer in the footer', function (): void {
    $siteId = gisSite();
    gisRichFooter($siteId);
    gisSave($siteId, [
        'days' => 'mon-sat', 'opens' => '07:30', 'closes' => '17:00', 'zalo' => '0901 234 567',
        'moitOn' => true, 'moitUrl' => 'http://online.gov.vn/Home/WebDetails/12345', 'licenseDate' => '14/03/2019',
    ], ['tagline' => 'Chính xác trong từng chi tiết', 'hours_note' => 'Nghỉ trưa 12:00 – 13:00', 'license_text' => 'Sở KH&ĐT TP Hà Nội']);

    $footer = gisFooter(gisHome());

    expect($footer)
        ->toContain('<p class="sf-footer__tagline">Chính xác trong từng chi tiết</p>')
        ->toContain('Giờ làm việc: Thứ 2 – Thứ 7, 07:30 – 17:00 · Nghỉ trưa 12:00 – 13:00')
        ->toContain('<a href="http://online.gov.vn/Home/WebDetails/12345" rel="noopener">Đã thông báo Bộ Công Thương')
        ->toContain('href="https://zalo.me/0901234567"')
        ->toContain('GPĐKKD: 0312345678 do Sở KH&amp;ĐT TP Hà Nội cấp ngày 14/03/2019')
        ->not->toContain('Sở cũ');
});

it('prints nothing for the values left empty, and only a valid notice link', function (): void {
    $siteId = gisSite();
    gisRichFooter($siteId);
    gisSave($siteId, ['days' => '', 'moitOn' => true, 'moitUrl' => '', 'zalo' => '']);
    app(Tenancy::class)->enter($siteId, fn () => SiteProfile::query()->firstOrFail()->update(['moit_url' => 'javascript:alert(1)']));

    $footer = gisFooter(gisHome());

    expect($footer)->not->toContain('Giờ làm việc')->not->toContain('Bộ Công Thương')->not->toContain('zalo.me')
        ->not->toContain('sf-footer__tagline')->not->toContain('javascript:');
});

it('prints “Theo lịch hẹn” and the note alone when that is all there is', function (): void {
    $siteId = gisSite();
    gisSave($siteId, ['days' => 'appointment']);
    expect(gisFooter(gisHome()))->toContain('Giờ làm việc: Theo lịch hẹn<');

    gisSave($siteId, ['days' => ''], ['hours_note' => 'Gọi trước khi đến']);
    expect(gisFooter(gisHome()))->toContain('Giờ làm việc: Gọi trước khi đến<');
});

it('tells search engines the opening hours, as a LocalBusiness', function (): void {
    $siteId = gisSite();
    gisSave($siteId, ['days' => 'mon-fri', 'opens' => '08:00', 'closes' => '17:30', 'zalo' => '0901 234 567', 'facebook' => 'https://facebook.com/comau']);

    $ld = gisLd(gisHome());

    expect($ld['@type'])->toBe('LocalBusiness')
        ->and($ld['openingHoursSpecification'])->toBe([[
            '@type' => 'OpeningHoursSpecification',
            'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'opens' => '08:00', 'closes' => '17:30',
        ]])
        ->and($ld['sameAs'])->toEqualCanonicalizing(['https://facebook.com/comau', 'https://zalo.me/0901234567']);   // Zalo as its link, never the bare number

    gisSave($siteId, ['days' => 'appointment']);
    expect(gisLd(gisHome()))->toHaveKey('@type', 'Organization')->not->toHaveKey('openingHoursSpecification');
});

it('prints the licence date in the site’s day format; “Tự động” keeps day/month/year', function (string $format, string $shown): void {
    $siteId = gisSite();
    gisRichFooter($siteId);
    gisSave($siteId, ['licenseDate' => '14/03/2019', 'dateFormat' => $format]);

    expect(gisFooter(gisHome()))->toContain('cấp ngày '.$shown);
})->with([
    'auto' => ['auto', '14/03/2019'],
    'dmy' => ['dmy', '14/03/2019'],
    'mdy' => ['mdy', '03/14/2019'],
    'iso' => ['iso', '2019-03-14'],
    'long' => ['long', '14 tháng 3 năm 2019'],
]);

it('formats a day for each format and language', function (): void {
    $day = CarbonImmutable::parse('2026-12-31');

    expect(SiteTime::formatDay($day, 'auto', 'en'))->toBe('31/12/2026')
        ->and(SiteTime::formatDay($day, 'mdy', 'vi'))->toBe('12/31/2026')
        ->and(SiteTime::formatDay($day, 'iso', 'ja'))->toBe('2026-12-31')
        ->and(SiteTime::formatDay($day, 'long', 'en'))->toBe('December 31, 2026')
        ->and(SiteTime::formatDay($day, 'long', 'ja'))->toBe('2026年12月31日')
        ->and(SiteTime::formatDay($day, 'nonsense', 'vi'))->toBe('31/12/2026');
});

it('dates a stored instant as the site’s own day, in its format (the post lists’ dates)', function (): void {
    $siteId = gisSite();
    app(Tenancy::class)->enter($siteId, function (): void {
        SiteSetting::query()->create(['group_name' => 'general', 'key' => 'date_format', 'value' => ['format' => 'iso']]);
        app(SiteTime::class)->forget();
        expect(app(SiteTime::class)->day(CarbonImmutable::parse('2026-03-14 20:00:00', 'UTC'), 'vi'))->toBe('2026-03-15');   // the site's own day (UTC+7)
    });
});

it('saves An Vinh untouched without changing a byte: the shortened name moves into the Vietnamese text, the address keeps its words', function (bool $rich): void {
    $siteId = gisAnVinh();
    if ($rich) {
        gisRichFooter($siteId);
    }
    $before = gisHome();
    $footer = gisFooter($before);
    expect($footer)->toContain('Công ty TNHH TM &amp; DV Thiết Kế Gia Công Cơ Khí An Vinh')->toContain('Thôn Xuân Sơn, Xã Trung Giã, TP Hà Nội')
        ->and(gisLd($before)['legalName'])->toBe('Công ty TNHH TM & DV Thiết Kế Gia Công Cơ Khí An Vinh');

    gisSave($siteId);
    $after = gisHome();

    expect(gisFooter($after))->toBe($footer)
        ->and(gisLd($after))->toBe(gisLd($before))
        ->and(gisProfile($siteId))->toMatchArray([
            'legal_name' => 'CÔNG TY TNHH THƯƠNG MẠI VÀ DỊCH VỤ THIẾT KẾ GIA CÔNG CƠ KHÍ AN VINH',   // the full name stays the shared one
            'province' => 'TP Hà Nội', 'ward' => 'Xã Trung Giã',                                      // the words the site showed, not the catalog's
        ])
        ->and(gisProfile($siteId)['province_id'])->not->toBeNull()   // matched all the same (ours, or the official list where it is loaded)
        ->and(app(Tenancy::class)->enter($siteId, fn () => SiteProfileTranslation::query()->where('locale', 'vi')->value('legal_name')))
        ->toBe('Công ty TNHH TM & DV Thiết Kế Gia Công Cơ Khí An Vinh');

    // A second untouched save (the brand setting is gone by now) changes nothing either.
    gisSave($siteId);
    expect(gisFooter(gisHome()))->toBe($footer);
})->with(['classic footer' => false, 'rich footer' => true]);

it('writes the catalog’s names once another province and ward are picked', function (): void {
    $siteId = gisAnVinh();
    gisSave($siteId, ['provinceId' => (string) gisUnit('provinces', 'Y2'), 'wardId' => (string) gisUnit('wards', 'Y201')]);

    expect(gisFooter(gisHome()))->toContain('Thôn Xuân Sơn, Phường Thử Khác, Tỉnh Thử Khác')
        ->and(gisProfile($siteId))->toMatchArray(['province' => 'Tỉnh Thử Khác', 'ward' => 'Phường Thử Khác']);
});

it('never overwrites the company name a language already shows', function (): void {
    $siteId = gisAnVinh();
    app(Tenancy::class)->enter($siteId, fn () => SiteProfileTranslation::query()->where('locale', 'vi')->update(['legal_name' => 'Cơ Khí An Vinh (tên hiện)']));

    gisSave($siteId);

    expect(app(Tenancy::class)->enter($siteId, fn () => SiteProfileTranslation::query()->where('locale', 'vi')->value('legal_name')))->toBe('Cơ Khí An Vinh (tên hiện)')
        ->and(gisFooter(gisHome()))->toContain('Cơ Khí An Vinh (tên hiện)')->not->toContain('TM &amp; DV');
});

it('keeps a network the screen does not edit, from the brand setting too', function (): void {
    $siteId = gisSite();
    gisRichFooter($siteId);
    gisBrand($siteId, ['name' => 'Cơ Khí Mẫu', 'legal_name' => 'Công ty TNHH Cơ Khí Mẫu', 'address' => 'Số 1 Đường Thử, TP Hà Nội', 'phone' => '0901 234 567',
        'social_links' => ['facebook' => 'https://facebook.com/comau', 'linkedin' => 'https://www.linkedin.com/company/comau']]);
    $before = gisHome();
    expect(gisFooter($before))->toContain('https://www.linkedin.com/company/comau');

    gisSave($siteId);
    $after = gisHome();

    expect(gisFooter($after))->toBe(gisFooter($before))
        ->and(gisLd($after))->toBe(gisLd($before))
        ->and(gisProfile($siteId)['social_links'])->toMatchArray(['facebook' => 'https://facebook.com/comau', 'linkedin' => 'https://www.linkedin.com/company/comau']);
});

it('keeps a hotline of its own; one that was the phone follows it', function (): void {
    $siteId = gisSite();
    gisRichFooter($siteId);
    gisBrand($siteId, ['name' => 'Cơ Khí Mẫu', 'legal_name' => 'Công ty TNHH Cơ Khí Mẫu', 'phone' => '0901 234 567', 'hotline' => '1900 1234']);
    $footer = gisFooter(gisHome());
    expect($footer)->toContain('>1900 1234<');

    gisSave($siteId);
    expect(gisFooter(gisHome()))->toBe($footer)
        ->and(gisProfile($siteId))->toMatchArray(['phone' => '0901 234 567', 'hotline' => '1900 1234']);

    gisSave($siteId, ['phone' => '0902 222 333']);
    expect(gisProfile($siteId))->toMatchArray(['phone' => '0902 222 333', 'hotline' => '1900 1234']);

    // a site whose hotline was its phone: the two stay one number
    app(Tenancy::class)->enter($siteId, fn () => SiteProfile::query()->firstOrFail()->update(['hotline' => '0902 222 333']));
    gisSave($siteId, ['phone' => '0903 444 555']);
    expect(gisProfile($siteId))->toMatchArray(['phone' => '0903 444 555', 'hotline' => '0903 444 555'])
        ->and(gisFooter(gisHome()))->toContain('>0903 444 555<');
});

it('keeps the street of a one-line address that has a province, without repeating its ward or province', function (array $parts, string $street): void {
    $siteId = gisSite();
    gisBrand($siteId, ['name' => 'Cơ Khí Mẫu', 'legal_name' => 'Công ty TNHH Cơ Khí Mẫu', 'address' => 'Số 1 Đường Thử, Xã Mẫu, TP Thử Ba', ...$parts]);
    $before = gisHome();
    $footer = gisFooter($before);
    expect($footer)->toContain('Số 1 Đường Thử, Xã Mẫu, TP Thử Ba');
    expect(app(Tenancy::class)->enter($siteId, fn () => app(GeneralSettingsStore::class)->load()['i18n']['vi']['address_line']))->toBe($street);

    gisSave($siteId);

    expect(gisFooter(gisHome()))->toBe($footer)
        ->and(gisProfile($siteId)['address_line'])->toBe($street);
})->with([
    'province only' => [['province' => 'TP Thử Ba'], 'Số 1 Đường Thử, Xã Mẫu'],
    'ward and province' => [['ward' => 'Xã Mẫu', 'province' => 'TP Thử Ba'], 'Số 1 Đường Thử'],
]);
