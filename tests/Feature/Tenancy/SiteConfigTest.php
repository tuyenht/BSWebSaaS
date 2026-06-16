<?php

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;

/*
 * P0.4 — per-site config tables auto-stamp site_id (BelongsToSite) and are isolated
 * across tenants (RLS). RlsCoverageTest already proves RLS exists; this proves the
 * app-layer behaviour: write under one site, invisible under another.
 */

beforeEach(fn () => seedTenancy());

it('auto-stamps site_id and isolates settings across tenants', function () {
    $tenancy = app(Tenancy::class);

    $tenancy->enter(1, fn () => SiteSetting::create([
        'group_name' => 'general',
        'key' => 'site_name',
        'value' => ['vi' => 'An Vinh'],
    ]));

    $row = $tenancy->enter(1, fn () => SiteSetting::sole());

    expect($row->site_id)->toBe(1)
        ->and($row->value)->toBe(['vi' => 'An Vinh'])
        ->and($tenancy->enter(1, fn () => SiteSetting::count()))->toBe(1)
        ->and($tenancy->enter(2, fn () => SiteSetting::count()))->toBe(0);
});

it('stores a localized site profile within its site', function () {
    $tenancy = app(Tenancy::class);

    $tenancy->enter(1, function () {
        $profile = SiteProfile::create(['legal_name' => 'Cong ty An Vinh', 'tax_code' => '0101234567']);
        $profile->translations()->create(['locale' => 'vi', 'brand_name' => 'An Vinh', 'description' => 'Co khi']);
    });

    expect($tenancy->enter(1, fn () => SiteProfile::sole()->translations()->count()))->toBe(1)
        ->and($tenancy->enter(2, fn () => SiteProfile::count()))->toBe(0);
});

it('records enabled locales per site', function () {
    $tenancy = app(Tenancy::class);

    $tenancy->enter(1, function () {
        SiteLocale::create(['locale' => 'vi', 'is_default' => true, 'enabled' => true]);
        SiteLocale::create(['locale' => 'zh-Hans', 'is_default' => false, 'enabled' => true]);
    });

    expect($tenancy->enter(1, fn () => SiteLocale::where('is_default', true)->pluck('locale')->all()))->toBe(['vi']);
});
