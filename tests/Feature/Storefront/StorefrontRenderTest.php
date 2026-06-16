<?php

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use Illuminate\Support\Facades\DB;

/*
 * P1.3 — full dynamic pipeline end-to-end: a site provisioned by the SAME action
 * that builds An Vinh is immediately reachable, rendering its DB-stored homepage as
 * SSR HTML, isolated per tenant.
 */

beforeEach(function () {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, routes, entry_translations, entries,
         site_profile_translations, site_profiles, site_settings, site_locales,
         site_modules, content_sections, domains, sites, tenants, tenant_users, users
         restart identity cascade'
    );
    truncateRbac();
});

function provision(string $name, string $host, string $email): void
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: $name,
        siteName: $name,
        hostname: $host,
        ownerEmail: $email,
        ownerName: 'Owner',
        ownerPassword: 'a-strong-password',
    ));
}

it('renders the provisioned An Vinh homepage as SSR HTML', function () {
    provision('An Vinh', 'anvinh.test', 'owner@anvinh.test');

    $html = $this->get('http://anvinh.test/')->assertOk()->getContent();

    expect($html)->toContain('<title>An Vinh</title>')
        ->toContain('<section class="hero">')
        ->toContain('An Vinh');
});

it('404s an unknown path on a real site', function () {
    provision('An Vinh', 'anvinh.test', 'owner@anvinh.test');

    $this->get('http://anvinh.test/khong-ton-tai')->assertNotFound();
});

it('isolates rendered content between provisioned sites', function () {
    provision('An Vinh', 'anvinh.test', 'owner@anvinh.test');
    provision('Other Co', 'other.test', 'owner@other.test');

    expect($this->get('http://anvinh.test/')->getContent())->toContain('An Vinh')->not->toContain('Other Co');
    expect($this->get('http://other.test/')->getContent())->toContain('Other Co')->not->toContain('An Vinh');
});
