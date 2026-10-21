<?php

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\SiteIdentity;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteLocale as SiteLocaleModel;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;

/*
 * The business identity (NAP) a storefront page shows comes from site_profiles + its translations
 * (design.md §14). While sites still carry the older one-language general.brand setting, it keeps
 * precedence in the default language — a live page must not change by a byte — and the profile
 * fills only what it lacks; any other language reads its own translation first. One query.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, routes, entry_translations, entries,
         site_profile_translations, site_profiles, site_settings, site_locales,
         site_modules, content_sections, domains, sites, tenants, tenant_users, users
         restart identity cascade'
    );
    truncateRbac();
});

function identitySite(): int
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Danh Tính', siteName: 'Danh Tính', hostname: 'danhtinh.platform.test',
        ownerEmail: 'owner@danhtinh.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));

    return Site::query()->firstWhere('name', 'Danh Tính')->id;
}

/** The legacy brand setting, as An Vinh has it today. */
function identityBrand(): void
{
    SiteSetting::query()->updateOrCreate(['group_name' => 'general', 'key' => 'brand'], ['value' => [
        'name' => 'Cơ Khí Mẫu',
        'legal_name' => 'Công ty TNHH Cơ Khí Mẫu',
        'address' => 'Số 1 Đường Thử, Xã Mẫu, TP Hà Nội',
        'phone' => '0900 000 000',
    ]]);
}

it('keeps every legacy brand value in the default language, filling only what it lacks', function (): void {
    $identity = app(Tenancy::class)->enter(identitySite(), function (): ?array {
        identityBrand();
        SiteProfile::query()->firstOrFail()->update([
            'legal_name' => 'CÔNG TY TNHH CƠ KHÍ MẪU (tên đầy đủ)',   // differs from the brand
            'hotline' => '1900 0000',                                  // the brand has none
        ]);

        return app(SiteIdentity::class)->for('vi');
    });

    expect($identity['legal_name'])->toBe('Công ty TNHH Cơ Khí Mẫu')   // brand wins — no live change
        ->and($identity['address'])->toBe('Số 1 Đường Thử, Xã Mẫu, TP Hà Nội')
        ->and($identity['hotline'])->toBe('1900 0000');                    // gap filled from the profile
});

it('shows another language its own translation of the identity', function (): void {
    $identity = app(Tenancy::class)->enter(identitySite(), function (): ?array {
        identityBrand();
        SiteLocaleModel::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        app(SiteLocale::class)->forget();

        $profile = SiteProfile::query()->firstOrFail();
        $profile->update(['ward' => 'Xã Mẫu', 'province' => 'TP Hà Nội']);
        $profile->translations()->create([
            'site_id' => $profile->site_id, 'locale' => 'en',
            'brand_name' => 'Sample Mechanical', 'legal_name' => 'Sample Mechanical Co., Ltd', 'address_line' => '1 Test Street',
        ]);

        return app(SiteIdentity::class)->for('en');
    });

    expect($identity['name'])->toBe('Sample Mechanical')
        ->and($identity['legal_name'])->toBe('Sample Mechanical Co., Ltd')
        ->and($identity['address'])->toBe('1 Test Street, Xã Mẫu, TP Hà Nội')
        ->and($identity['phone'])->toBe('0900 000 000');                   // untranslated values carry over
});

it('reads a site that has only a profile — no legacy setting', function (): void {
    $identity = app(Tenancy::class)->enter(identitySite(), function (): ?array {
        SiteProfile::query()->firstOrFail()->update([
            'brand_name' => 'Chỉ Có Hồ Sơ', 'phone' => '0911 111 111', 'address_line' => '2 Đường Mới', 'province' => 'TP Huế',
            'social_links' => ['facebook' => 'https://facebook.com/chicohoso', 'youtube' => ''],
        ]);

        return app(SiteIdentity::class)->for('vi');
    });

    expect($identity['name'])->toBe('Chỉ Có Hồ Sơ')
        ->and($identity['address'])->toBe('2 Đường Mới, TP Huế')
        ->and($identity['social_links'])->toBe(['https://facebook.com/chicohoso']);   // blanks dropped
});

it('is empty for a site with a blank profile and no setting', function (): void {
    expect(app(Tenancy::class)->enter(identitySite(), fn () => app(SiteIdentity::class)->for('vi')))->toBeNull();
});

it('reads the identity in one query', function (): void {
    $queries = app(Tenancy::class)->enter(identitySite(), function (): int {
        identityBrand();
        app(SiteLocale::class)->default();   // the storefront has resolved it already

        return countQueries(fn () => app(SiteIdentity::class)->for('vi'));
    });

    expect($queries)->toBe(1);
});

it('tells search engines about the site’s social profiles', function (): void {
    app(Tenancy::class)->enter(identitySite(), function (): void {
        identityBrand();
        SiteProfile::query()->firstOrFail()->update(['social_links' => ['facebook' => 'https://facebook.com/comau']]);
    });

    expect($this->get('http://danhtinh.platform.test/')->assertOk()->getContent())
        ->toContain('"sameAs":["https://facebook.com/comau"]');
});
