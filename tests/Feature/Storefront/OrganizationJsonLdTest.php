<?php

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;

/*
 * Organization JSON-LD (schema.org/Organization) in the storefront <head>: emitted
 * site-wide when the brand site-setting has a name; reuses the $brand payload already
 * loaded by StorefrontController::brand() → no extra DB query on the hot path.
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

/** Provisions a minimal test site. */
function orgProvision(): void
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Org Test',
        siteName: 'Org Test',
        hostname: 'org.test',
        ownerEmail: 'owner@org.test',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-password',
    ));
}

/** Upserts the brand setting with full NAP data inside the tenant context. */
function orgSeedBrand(): void
{
    $site = Site::query()->firstWhere('name', 'Org Test');

    app(Tenancy::class)->enter($site->id, function (): void {
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'general', 'key' => 'brand'],
            ['value' => [
                'name' => 'Công ty Kiểm Tra',
                'legal_name' => 'CÔNG TY TNHH KIỂM TRA',
                'phone' => '0900 00 00 00',
                'email' => 'test@example.vn',
                'address_line' => 'Số 1 Đường Thử Nghiệm',
                'ward' => 'Phường Trung Tâm',
                'province' => 'TP Hà Nội',
                'tax_id' => '1234567890',
            ]],
        );
    });
}

it('emits Organization JSON-LD with name, telephone, and addressRegion when brand is set', function (): void {
    orgProvision();
    orgSeedBrand();

    $html = $this->get('http://org.test/')->assertOk()->getContent();

    expect($html)
        ->toContain('"@type":"Organization"')
        ->toContain('"name":"Công ty Kiểm Tra"')
        ->toContain('"telephone":"0900 00 00 00"')
        ->toContain('"addressRegion":"TP Hà Nội"')
        ->toContain('"addressCountry":"VN"')
        ->toContain('"taxID":"1234567890"')
        ->toContain('application/ld+json');
});

it('emits email, legalName, streetAddress, and addressLocality in Organization JSON-LD', function (): void {
    orgProvision();
    orgSeedBrand();

    $html = $this->get('http://org.test/')->assertOk()->getContent();

    expect($html)
        ->toContain('"email":"test@example.vn"')
        ->toContain('"legalName":"CÔNG TY TNHH KIỂM TRA"')
        ->toContain('"streetAddress":"Số 1 Đường Thử Nghiệm"')
        ->toContain('"addressLocality":"Phường Trung Tâm"')
        ->toContain('"@type":"PostalAddress"');
});

it('omits null fields — legalName and address absent when not present in brand setting', function (): void {
    orgProvision();
    $site = Site::query()->firstWhere('name', 'Org Test');

    app(Tenancy::class)->enter($site->id, function (): void {
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'general', 'key' => 'brand'],
            ['value' => ['name' => 'Minimal Brand', 'phone' => '0900 11 22 33']],
        );
    });

    $html = $this->get('http://org.test/')->assertOk()->getContent();

    expect($html)
        ->toContain('"@type":"Organization"')
        ->toContain('"name":"Minimal Brand"')
        ->toContain('"telephone":"0900 11 22 33"')
        ->not->toContain('"legalName"')
        ->not->toContain('"address"')
        ->not->toContain('"taxID"');
});

it('falls back to combined address field when split NAP keys are absent', function (): void {
    orgProvision();
    $site = Site::query()->firstWhere('name', 'Org Test');

    app(Tenancy::class)->enter($site->id, function (): void {
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'general', 'key' => 'brand'],
            ['value' => [
                'name' => 'Legacy Brand',
                'address' => 'Thôn Xuân Sơn, Xã Trung Giã, TP Hà Nội',
                'phone' => '0900 99 88 77',
            ]],
        );
    });

    $html = $this->get('http://org.test/')->assertOk()->getContent();

    expect($html)
        ->toContain('"@type":"Organization"')
        ->toContain('"streetAddress":"Thôn Xuân Sơn, Xã Trung Giã, TP Hà Nội"')
        ->toContain('"addressCountry":"VN"');
});

it('emits no Organization JSON-LD and no error when no brand setting exists', function (): void {
    // ProvisionSite does not seed a brand setting; StorefrontController::brand() returns null.
    orgProvision();

    $html = $this->get('http://org.test/')->assertOk()->getContent();

    expect($html)->not->toContain('"@type":"Organization"');
});

it('adds no DB query for Organization JSON-LD — budget unchanged', function (): void {
    orgProvision();
    orgSeedBrand();

    $count = countQueries(function (): void {
        $html = $this->get('http://org.test/')->assertOk()->getContent();

        // Confirm the block is present inside the query-counted closure.
        expect($html)
            ->toContain('"@type":"Organization"')
            ->toContain('"name":"Công ty Kiểm Tra"');
    });

    // Organization JSON-LD reuses $brand already loaded by StorefrontController::brand()
    // (1 query already in the budget). Adding the JSON-LD emission touches no new query.
    expect($count)->toBeLessThanOrEqual(16);
});
