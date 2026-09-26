<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Tenancy\Models\SiteLocale;
use Illuminate\Support\Facades\DB;

/*
 * A website starts with ONE content language; more are granted per site at the SaaS tier. Until the
 * platform console has a screen for it, the operator does both with `site:locale`: --limit grants the
 * languages (a per-site override of the plan), a locale argument switches one on / public / hidden /
 * off through ConfigureSiteLocale — so the default-language lock and the limit hold here too.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, routes, entry_translations, entries,
         site_profile_translations, site_profiles, site_settings, site_locales,
         site_modules, content_sections, domains, sites, tenants, tenant_users, users, audit_logs
         restart identity cascade'
    );
    DB::connection('pgsql_admin')->statement('delete from plans');
    truncateRbac();
});

/** A freshly provisioned site on grant.test: Vietnamese is its only (default) language. */
function grantSite(): int
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Cấp Ngôn Ngữ', siteName: 'Cấp Ngôn Ngữ', hostname: 'grant.test',
        ownerEmail: 'owner@grant.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));

    return Site::query()->firstWhere('name', 'Cấp Ngôn Ngữ')->id;
}

/** @return array<string, array{enabled: bool, public: bool}> */
function grantSiteLocales(int $siteId): array
{
    return app(Tenancy::class)->enter($siteId, fn (): array => SiteLocale::query()->orderBy('locale')->get()
        ->mapWithKeys(fn (SiteLocale $row): array => [$row->locale => ['enabled' => $row->enabled, 'public' => $row->is_public]])
        ->all());
}

it('starts a website with one language, so a second one needs a grant', function (): void {
    $siteId = grantSite();

    $this->artisan('site:locale', ['host' => 'grant.test', 'locale' => 'en'])
        ->expectsOutputToContain('limit_reached')
        ->assertFailed();

    expect(grantSiteLocales($siteId))->toBe(['vi' => ['enabled' => true, 'public' => true]]);
});

it('grants more languages per site and switches one on for editors first, then for visitors', function (): void {
    $siteId = grantSite();

    $this->artisan('site:locale', ['host' => 'grant.test', 'locale' => 'en', '--limit' => '3'])->assertSuccessful();

    expect(app(Tenancy::class)->enter($siteId, fn (): mixed => SiteFeature::query()->firstWhere('feature_key', 'locales.max')?->value))
        ->toBe(3)
        ->and(grantSiteLocales($siteId))->toBe([
            'en' => ['enabled' => true, 'public' => false],
            'vi' => ['enabled' => true, 'public' => true],
        ]);

    $this->artisan('site:locale', ['host' => 'grant.test', 'locale' => 'en', '--public' => true])->assertSuccessful();
    expect(grantSiteLocales($siteId)['en'])->toBe(['enabled' => true, 'public' => true]);

    // Re-running without a flag keeps what visitors see; --hide and --off take it away step by step.
    $this->artisan('site:locale', ['host' => 'grant.test', 'locale' => 'en'])->assertSuccessful();
    expect(grantSiteLocales($siteId)['en'])->toBe(['enabled' => true, 'public' => true]);

    $this->artisan('site:locale', ['host' => 'grant.test', 'locale' => 'en', '--hide' => true])->assertSuccessful();
    expect(grantSiteLocales($siteId)['en'])->toBe(['enabled' => true, 'public' => false]);

    $this->artisan('site:locale', ['host' => 'grant.test', 'locale' => 'en', '--off' => true])->assertSuccessful();
    expect(grantSiteLocales($siteId)['en'])->toBe(['enabled' => false, 'public' => false]);

    expect(AuditLog::query()->where('site_id', $siteId)->where('action', 'provisioning.site.locale_changed')->count())->toBe(5)
        ->and(AuditLog::query()->where('site_id', $siteId)->where('action', 'provisioning.site.feature_overridden')->count())->toBe(1);
});

it('keeps the default language on and public', function (): void {
    $siteId = grantSite();

    $this->artisan('site:locale', ['host' => 'grant.test', 'locale' => 'vi', '--hide' => true])
        ->expectsOutputToContain('default_locked')
        ->assertFailed();

    expect(grantSiteLocales($siteId))->toBe(['vi' => ['enabled' => true, 'public' => true]]);
});

it('lists the languages and the limit when given only a host', function (): void {
    grantSite();

    $this->artisan('site:locale', ['host' => 'grant.test'])
        ->expectsOutputToContain('Language limit: 1')
        ->assertSuccessful();
});

it('refuses an unknown host, a limit below one and contradictory flags', function (array $arguments): void {
    grantSite();

    $this->artisan('site:locale', $arguments)->assertFailed();
})->with([
    'unknown host' => [['host' => 'nowhere.test', 'locale' => 'en']],
    'limit zero' => [['host' => 'grant.test', '--limit' => '0']],
    'limit not a number' => [['host' => 'grant.test', '--limit' => 'many']],
    'public and hide' => [['host' => 'grant.test', 'locale' => 'en', '--public' => true, '--hide' => true]],
    'flag without a language' => [['host' => 'grant.test', '--public' => true]],
]);
