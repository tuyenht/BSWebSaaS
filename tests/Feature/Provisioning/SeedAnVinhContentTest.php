<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Actions\SeedAnVinhContent;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\DB;

/*
 * SeedAnVinhContent: An-Vinh-specific bootstrap (du-an category + 5 sector terms + 9 project
 * entries + nav + storefront route), kept out of the generic ProvisionSite. Idempotent.
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

function seedAnVinhSite(): Site
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'An Vinh', siteName: 'An Vinh', hostname: 'anvinh.test',
        ownerEmail: 'owner@anvinh.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));

    return Site::query()->firstWhere('name', 'An Vinh');
}

it('seeds the du-an category, sectors, projects, route and nav', function () {
    $site = seedAnVinhSite();

    app(Tenancy::class)->enter($site->id, function () {
        app(SeedAnVinhContent::class)->handle();

        $duAnTranslation = TaxonomyTranslation::query()->where('slug', 'du-an')->first();
        expect($duAnTranslation)->not->toBeNull();
        $duAn = Taxonomy::query()->findOrFail($duAnTranslation->taxonomy_id);
        expect($duAn->vocabulary)->toBe('post_cat');

        // 5 sector children under du-an.
        expect(Taxonomy::query()->where('vocabulary', 'sector')->where('parent_id', $duAn->id)->count())->toBe(5);

        // 9 published project posts, each routed under /du-an/{slug}.
        expect(Entry::query()->where('type', 'post')->where('status', 'published')->count())->toBe(9);
        expect(SiteRoute::query()->where('path', '/du-an')->exists())->toBeTrue();
        expect(SiteRoute::query()->where('path', 'like', '/du-an/%')->count())->toBe(9);

        // Admin nav section + storefront menu item.
        expect(ContentSection::query()->where('key', 'projects')->exists())->toBeTrue();
        expect(MenuItem::query()->where('routable_type', 'taxonomy')->where('routable_id', $duAn->id)->exists())->toBeTrue();
    });
});

it('is idempotent (re-run does not duplicate)', function () {
    $site = seedAnVinhSite();

    app(Tenancy::class)->enter($site->id, function () {
        app(SeedAnVinhContent::class)->handle();
        app(SeedAnVinhContent::class)->handle();   // second run

        expect(Entry::query()->where('type', 'post')->count())->toBe(9);
        expect(Taxonomy::query()->where('vocabulary', 'sector')->count())->toBe(5);
        expect(Taxonomy::query()->where('vocabulary', 'post_cat')->count())->toBe(2); // tin-tuc + du-an
        expect(SiteRoute::query()->where('path', 'like', '/du-an/%')->count())->toBe(9);
    });
});
