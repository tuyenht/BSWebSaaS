<?php

use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;

/*
 * `site:link-contact-page` records, for sites made before the contact page was a setting, which page
 * is theirs: the published page at /lien-he in the default language. It fills a missing value, never
 * replaces one, writes nothing when there is no such page, and keeps to the site it is working on.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, routes, entry_translations, entries,
         site_profile_translations, site_profiles, site_settings, site_locales,
         site_modules, content_sections, domains, sites, tenants, tenant_users, users, audit_logs
         restart identity cascade'
    );
    truncateRbac();
});

function contactSite(string $name, string $host): int
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: $name, siteName: $name, hostname: $host,
        ownerEmail: 'owner@'.$host, ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));

    return (int) Site::query()->where('name', $name)->value('id');
}

/** @return array<string, mixed>|null */
function contactSetting(int $siteId): ?array
{
    return app(Tenancy::class)->enter($siteId, function (): ?array {
        $value = SiteSetting::query()->where('group_name', 'catalog')->where('key', 'contact_page')->value('value');

        return is_array($value) ? $value : null;
    });
}

function contactUlid(int $siteId): string
{
    return app(Tenancy::class)->enter($siteId, fn (): string => SiteRoute::query()->where('path', '/lien-he')->firstOrFail()->entry->ulid);
}

function forgetContactSetting(int $siteId): void
{
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->where('key', 'contact_page')->delete());
}

it('has the provisioning write the setting for the contact page it creates', function (): void {
    $id = contactSite('Liên Hệ Một', 'mot.platform.test');

    expect(contactSetting($id))->toBe(['entry' => contactUlid($id)]);
});

it('fills a missing value with the page at /lien-he', function (): void {
    $id = contactSite('Liên Hệ Một', 'mot.platform.test');
    forgetContactSetting($id);

    $this->artisan('site:link-contact-page', ['--site' => (string) $id])->assertSuccessful();

    expect(contactSetting($id))->toBe(['entry' => contactUlid($id)]);
});

it('leaves an existing value alone', function (): void {
    $id = contactSite('Liên Hệ Một', 'mot.platform.test');
    app(Tenancy::class)->enter($id, fn () => SiteSetting::query()->where('key', 'contact_page')->update(['value' => ['entry' => 'giữ-nguyên']]));

    $this->artisan('site:link-contact-page')->assertSuccessful();

    expect(contactSetting($id))->toBe(['entry' => 'giữ-nguyên']);
});

it('writes nothing when the site has no contact page or only a draft of it', function (): void {
    $id = contactSite('Liên Hệ Một', 'mot.platform.test');
    forgetContactSetting($id);
    app(Tenancy::class)->enter($id, fn () => SiteRoute::query()->where('path', '/lien-he')->firstOrFail()->entry->update(['status' => 'draft']));

    $this->artisan('site:link-contact-page')->assertSuccessful();
    expect(contactSetting($id))->toBeNull();

    app(Tenancy::class)->enter($id, fn () => SiteRoute::query()->where('path', '/lien-he')->delete());
    $this->artisan('site:link-contact-page')->assertSuccessful();
    expect(contactSetting($id))->toBeNull();
});

it('works one site at a time, writing to the chosen site only', function (): void {
    $one = contactSite('Liên Hệ Một', 'mot.platform.test');
    $two = contactSite('Liên Hệ Hai', 'hai.platform.test');
    forgetContactSetting($one);
    forgetContactSetting($two);

    $this->artisan('site:link-contact-page', ['--site' => (string) $one])->assertSuccessful();

    expect(contactSetting($one))->toBe(['entry' => contactUlid($one)])
        ->and(contactSetting($two))->toBeNull();

    $this->artisan('site:link-contact-page')->assertSuccessful();

    expect(contactSetting($two))->toBe(['entry' => contactUlid($two)])
        ->and(contactSetting($two)['entry'])->not->toBe(contactSetting($one)['entry']);
});
