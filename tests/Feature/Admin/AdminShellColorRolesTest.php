<?php

use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use Illuminate\Support\Facades\DB;

/*
 * The admin shell around every page — sidebar, footer, Customizer, list headers — paints text a
 * reader needs with roles that stay readable in both schemes (values + ratios:
 * scripts/contrast-check.mjs). Rendered through real pages, as the site owner.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         platform_settings, site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();

    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Demo', siteName: 'Demo', hostname: 'demo.test',
        ownerEmail: 'owner@demo.test', ownerName: 'Demo Owner',
        ownerPassword: 'a-strong-password', locales: ['vi'], modules: ['cms', 'media'],
    ));
});

function shellOwner(): User
{
    return User::query()->where('email', 'owner@demo.test')->firstOrFail();
}

it('titles every Customizer group in the muted role', function (): void {
    $html = $this->actingAs(shellOwner())->get('http://demo.test/admin')->assertOk()->getContent();

    preg_match_all('/<legend class="([^"]*)"/', (string) $html, $legends);

    expect($legends[1])->not->toBeEmpty()
        ->each->toContain('text-body-muted');
});

it('rings focus in the white ink of the navy Customizer header, not the navy accent', function (): void {
    $html = $this->actingAs(shellOwner())->get('http://demo.test/admin')->assertOk()->getContent();

    expect((string) $html)->toMatch('/<header class="[^"]*bg-primary[^"]*text-white[^"]*\[--color-focus:currentColor\]/');
});

it('heads a list table in the muted role', function (): void {
    $html = $this->actingAs(shellOwner())->get('http://demo.test/admin/users')->assertOk()->getContent();

    expect((string) $html)->toMatch('/<thead class="[^"]*text-body-muted[^"]*">/')
        ->not->toMatch('/<thead class="[^"]*text-body-faint/');
});
