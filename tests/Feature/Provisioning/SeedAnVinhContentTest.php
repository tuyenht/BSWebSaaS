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

        // 9 published project posts under du-an, each routed under /du-an/{slug}.
        expect($duAn->entries()->where('entries.type', 'post')->count())->toBe(9);
        expect(SiteRoute::query()->where('path', '/du-an')->exists())->toBeTrue();
        expect(SiteRoute::query()->where('path', 'like', '/du-an/%')->count())->toBe(9);

        // Admin nav section + storefront menu item.
        expect(ContentSection::query()->where('key', 'projects')->exists())->toBeTrue();
        expect(MenuItem::query()->where('routable_type', 'taxonomy')->where('routable_id', $duAn->id)->exists())->toBeTrue();
    });
});

it('seeds dich-vu services as posts with detail routes and glyph featured images', function () {
    $site = seedAnVinhSite();

    app(Tenancy::class)->enter($site->id, function () {
        app(SeedAnVinhContent::class)->handle();

        $dichVuTranslation = TaxonomyTranslation::query()->where('slug', 'dich-vu')->first();
        expect($dichVuTranslation)->not->toBeNull();
        $dichVu = Taxonomy::query()->findOrFail($dichVuTranslation->taxonomy_id);
        expect($dichVu->vocabulary)->toBe('post_cat');

        // 5 service-posts, each routed at /dich-vu/{slug} (its own detail page).
        expect(SiteRoute::query()->where('path', 'like', '/dich-vu/%')->count())->toBe(5);

        // A service carries a glyph key as its featured image (rendered as an inline SVG icon).
        $service = Entry::query()->whereHas('translations', fn ($q) => $q->where('slug', 'cho-thue-xe-nang'))->first();
        expect($service)->not->toBeNull();
        expect($service->meta['image_url'])->toBe('truck');

        expect(ContentSection::query()->where('key', 'services')->exists())->toBeTrue();
        expect(MenuItem::query()->where('routable_type', 'taxonomy')->where('routable_id', $dichVu->id)->exists())->toBeTrue();
    });
});

it('is idempotent (re-run does not duplicate)', function () {
    $site = seedAnVinhSite();

    app(Tenancy::class)->enter($site->id, function () {
        app(SeedAnVinhContent::class)->handle();
        app(SeedAnVinhContent::class)->handle();   // second run

        expect(Entry::query()->where('type', 'post')->count())->toBe(14); // 9 projects + 5 services
        expect(Taxonomy::query()->where('vocabulary', 'sector')->count())->toBe(5);
        expect(Taxonomy::query()->where('vocabulary', 'post_cat')->count())->toBe(3); // tin-tuc + du-an + dich-vu
        expect(SiteRoute::query()->where('path', 'like', '/du-an/%')->count())->toBe(9);
        expect(SiteRoute::query()->where('path', 'like', '/dich-vu/%')->count())->toBe(5);
    });
});
