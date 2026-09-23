<?php

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Province;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Tenancy\Models\Ward;
use Illuminate\Support\Facades\DB;

/*
 * Operator tools for moving business identity (NAP) into site_profiles (design.md §14):
 *  - site-profiles:sync-from-brand reports where general.brand and the profile disagree, and with
 *    --apply fills only EMPTY profile fields — it never overwrites what a site already holds;
 *  - admin-units:import loads the official two-level administrative units, idempotently, retiring
 *    (not deleting) units a newer list drops, since profiles may still point at them.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, routes, entry_translations, entries,
         site_profile_translations, site_profiles, site_settings, site_locales,
         site_modules, content_sections, domains, sites, tenants, tenant_users, users,
         wards, provinces
         restart identity cascade'
    );
    truncateRbac();
});

function napSite(): int
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Nap', siteName: 'Nap', hostname: 'nap.test',
        ownerEmail: 'owner@nap.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));
    $site = Site::query()->firstWhere('name', 'Nap')->id;

    app(Tenancy::class)->enter($site, function (): void {
        SiteSetting::query()->create(['group_name' => 'general', 'key' => 'brand', 'value' => [
            'name' => 'Nap Brand', 'legal_name' => 'Công ty TNHH Nap', 'phone' => '0900 000 000',
        ]]);
        SiteProfile::query()->firstOrFail()->update(['legal_name' => 'CÔNG TY TNHH NAP (đầy đủ)']);
    });

    return $site;
}

it('reports differences and empty fields without writing anything', function (): void {
    $site = napSite();

    $this->artisan('site-profiles:sync-from-brand')
        ->expectsOutputToContain('differs')
        ->expectsOutputToContain('would fill')
        ->expectsOutputToContain('Report only')
        ->assertSuccessful();

    $profile = app(Tenancy::class)->enter($site, fn () => SiteProfile::query()->firstOrFail());
    expect($profile->brand_name)->toBeNull()->and($profile->phone)->toBeNull();
});

it('fills only empty profile fields with --apply, leaving differences for a person to settle', function (): void {
    $site = napSite();

    $this->artisan('site-profiles:sync-from-brand', ['--apply' => true])->assertSuccessful();

    $profile = app(Tenancy::class)->enter($site, fn () => SiteProfile::query()->firstOrFail());
    expect($profile->brand_name)->toBe('Nap Brand')
        ->and($profile->phone)->toBe('0900 000 000')
        ->and($profile->legal_name)->toBe('CÔNG TY TNHH NAP (đầy đủ)');   // never overwritten
});

it('loads the official units, then retires what a newer list drops', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'units');
    file_put_contents($file, implode("\n", [
        'level,code,name,parent_code,valid_from',
        'province,01,Thành phố Hà Nội,,2025-07-01',
        'province,46,Thành phố Huế,,2025-07-01',
        'ward,00004,Phường Ba Đình,01,2025-07-01',
        'ward,00008,Phường Ngọc Hà,01,2025-07-01',
        'ward,19753,Phường Thuận Hóa,46,2025-07-01',
    ]));

    $this->artisan('admin-units:import', ['file' => $file])->expectsOutputToContain('Imported 2 provinces and 3 wards')->assertSuccessful();
    $this->artisan('admin-units:import', ['file' => $file])->assertSuccessful();   // idempotent

    expect(Province::query()->count())->toBe(2)
        ->and(Ward::query()->where('code', '19753')->firstOrFail()->province->name)->toBe('Thành phố Huế');

    file_put_contents($file, implode("\n", [
        'level,code,name,parent_code,valid_from',
        'province,01,Thành phố Hà Nội,,2025-07-01',
        'ward,00004,Phường Ba Đình,01,2025-07-01',
    ]));
    $this->artisan('admin-units:import', ['file' => $file, '--retire-missing' => true])->assertSuccessful();

    expect(Ward::query()->where('code', '00008')->firstOrFail()->valid_to)->not->toBeNull()
        ->and(Ward::query()->where('code', '00004')->firstOrFail()->valid_to)->toBeNull()
        ->and(Ward::query()->count())->toBe(3);   // retired, not deleted

    unlink($file);
});

it('rejects a file whose rows do not match the official layout', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'units');
    file_put_contents($file, "level,code,name,parent_code,valid_from\nward,00004,Phường Ba Đình,,2025-07-01\n");

    $this->artisan('admin-units:import', ['file' => $file])->assertFailed();
    expect(Ward::query()->count())->toBe(0);

    unlink($file);
});
