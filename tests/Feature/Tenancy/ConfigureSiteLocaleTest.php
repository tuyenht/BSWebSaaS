<?php

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Actions\ConfigureSiteLocale;
use App\Modules\Tenancy\Context\SiteLocale as SiteLocaleContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Exceptions\SiteLocaleChangeRejectedException;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Tenancy\Models\SiteLocale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * A site's languages: `enabled` = editors write in it, `is_public` = visitors can open it. The
 * write path keeps the default language enabled and public, hides a language from visitors when
 * editors lose it, and stops switching languages on at the plan's `locales.max` (default 1, the
 * default language included). The database refuses the same broken states on its own (CHECK).
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, routes, entry_translations, entries,
         site_profile_translations, site_profiles, site_settings, site_locales,
         site_modules, content_sections, domains, sites, tenants, tenant_users, users
         restart identity cascade'
    );
    DB::connection('pgsql_admin')->statement('delete from plans');
    truncateRbac();
});

/** A freshly provisioned site: Vietnamese is its only (default) language. */
function localeSite(): int
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Ngôn Ngữ', siteName: 'Ngôn Ngữ', hostname: 'ngonngu.test',
        ownerEmail: 'owner@ngonngu.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));

    return Site::query()->firstWhere('name', 'Ngôn Ngữ')->id;
}

/** Why a language change was refused, or null when it went through. */
function localeChangeRefusal(string $locale, bool $enabled, bool $public): ?string
{
    try {
        app(ConfigureSiteLocale::class)->handle($locale, $enabled, $public);
    } catch (SiteLocaleChangeRejectedException $e) {
        return $e->reason;
    }

    return null;
}

it('prepares a language for editors before showing it to visitors', function (): void {
    app(Tenancy::class)->enter(localeSite(), function (): void {
        $locales = app(SiteLocaleContext::class);
        SiteFeature::query()->create(['feature_key' => 'locales.max', 'value' => 2]);

        expect(localeChangeRefusal('en', enabled: true, public: false))->toBeNull()
            ->and($locales->enabled())->toBe(['vi', 'en'])
            ->and($locales->public())->toBe(['vi'])
            ->and($locales->isPublic('en'))->toBeFalse();

        expect(localeChangeRefusal('en', enabled: true, public: true))->toBeNull()
            ->and($locales->public())->toBe(['vi', 'en']);
    });
});

it('hides a language from visitors when editors lose it', function (): void {
    app(Tenancy::class)->enter(localeSite(), function (): void {
        SiteFeature::query()->create(['feature_key' => 'locales.max', 'value' => 2]);
        expect(localeChangeRefusal('en', enabled: true, public: true))->toBeNull();

        expect(localeChangeRefusal('en', enabled: false, public: true))->toBeNull();

        $row = SiteLocale::query()->firstWhere('locale', 'en');
        expect($row->enabled)->toBeFalse()->and($row->is_public)->toBeFalse();
    });
});

it('keeps the default language enabled and public', function (): void {
    app(Tenancy::class)->enter(localeSite(), function (): void {
        expect(localeChangeRefusal('vi', enabled: true, public: false))->toBe('default_locked')
            ->and(localeChangeRefusal('vi', enabled: false, public: false))->toBe('default_locked');

        $row = SiteLocale::query()->firstWhere('locale', 'vi');
        expect($row->enabled)->toBeTrue()->and($row->is_public)->toBeTrue();
    });
});

it('stops switching languages on at the plan limit, the default included', function (): void {
    app(Tenancy::class)->enter(localeSite(), function (): void {
        // Registry default locales.max = 1: a website starts with its default language only;
        // more languages are a separate grant (plan or per-site override).
        expect(localeChangeRefusal('en', enabled: true, public: false))->toBe('limit_reached')
            ->and(SiteLocale::query()->where('locale', 'en')->exists())->toBeFalse();

        SiteFeature::query()->create(['feature_key' => 'locales.max', 'value' => 2]);

        expect(localeChangeRefusal('en', enabled: true, public: true))->toBeNull()
            ->and(localeChangeRefusal('ja', enabled: true, public: false))->toBe('limit_reached');
    });
});

it('lets a per-site override raise the limit', function (): void {
    app(Tenancy::class)->enter(localeSite(), function (): void {
        SiteFeature::query()->create(['feature_key' => 'locales.max', 'value' => 3]);

        expect(localeChangeRefusal('en', enabled: true, public: true))->toBeNull()
            ->and(localeChangeRefusal('ja', enabled: true, public: true))->toBeNull()
            ->and(localeChangeRefusal('ko', enabled: true, public: true))->toBe('limit_reached');
    });
});

it('lets a site already above its limit keep and re-show what it has', function (): void {
    app(Tenancy::class)->enter(localeSite(), function (): void {
        // Enabled before the limit existed: three languages on a one-language plan.
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        SiteLocale::query()->create(['locale' => 'ja', 'is_default' => false, 'enabled' => true, 'is_public' => true]);

        expect(localeChangeRefusal('ja', enabled: true, public: false))->toBeNull()
            ->and(localeChangeRefusal('ja', enabled: true, public: true))->toBeNull()
            ->and(localeChangeRefusal('ko', enabled: true, public: false))->toBe('limit_reached');
    });
});

it('refuses a language code the storefront cannot carry in a URL', function (string $code): void {
    app(Tenancy::class)->enter(localeSite(), function () use ($code): void {
        expect(localeChangeRefusal($code, enabled: true, public: false))->toBe('locale_invalid');
    });
})->with(['EN', 'english', 'en_US', '', 'e']);

it('refuses a hidden or disabled default language at the database too', function (): void {
    $site = localeSite();
    $admin = DB::connection('pgsql_admin');

    expect(fn () => $admin->table('site_locales')->where('site_id', $site)->where('locale', 'vi')->update(['is_public' => false]))
        ->toThrow(QueryException::class, 'site_locales_default_visible_check');
});

it('refuses a public language that editors cannot write in, at the database too', function (): void {
    $site = localeSite();
    $admin = DB::connection('pgsql_admin');

    expect(fn () => $admin->table('site_locales')->insert([
        'site_id' => $site, 'locale' => 'en', 'is_default' => false, 'enabled' => false, 'is_public' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'site_locales_public_requires_enabled_check');
});

it('keeps a language switched off before is_public existed hidden from visitors', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2018_01_01_000000_add_is_public_to_site_locales.php');
    $site = localeSite();
    $admin = DB::connection('pgsql_admin');
    $admin->table('site_locales')->insert([
        'site_id' => $site, 'locale' => 'en', 'is_default' => false, 'enabled' => false, 'is_public' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Step back to the schema a live database meets this migration with (no column, no CHECKs),
    // then forward again. Migrations run on the privileged connection.
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $migration->down();
        $migration->up();
    } finally {
        DB::setDefaultConnection($original);
    }

    $public = $admin->table('site_locales')->where('site_id', $site)->orderBy('locale')->pluck('is_public', 'locale');
    $validated = $admin->table('pg_constraint')
        ->whereIn('conname', ['site_locales_default_visible_check', 'site_locales_public_requires_enabled_check'])
        ->orderBy('conname')
        ->pluck('convalidated', 'conname');

    expect($public->all())->toBe(['en' => false, 'vi' => true])
        ->and($validated->all())->toBe([
            'site_locales_default_visible_check' => true,
            'site_locales_public_requires_enabled_check' => true,
        ]);
});
