<?php

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteModule;
use Illuminate\Support\Facades\DB;

/*
 * P0.6 — ProvisionSite is the SaaS control-plane primitive: it builds An Vinh (and
 * every future site) end-to-end and idempotently. It exercises all three defense
 * layers (global identity/site → per-site entitlement + RBAC under tenant context).
 */

beforeEach(function () {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();
});

function provisionAnVinh(): Site
{
    return app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'An Vinh',
        siteName: 'An Vinh',
        hostname: 'anvinh.test',
        ownerEmail: 'owner@anvinh.test',
        ownerName: 'An Vinh Owner',
        ownerPassword: 'a-strong-password',
        locales: ['vi', 'zh-Hans'],
        modules: ['cms', 'media'],
    ));
}

it('provisions a full, active site stack', function () {
    $site = provisionAnVinh();
    $tenancy = app(Tenancy::class);

    expect($site->status)->toBe('active')
        ->and(Domain::query()->where('hostname', 'anvinh.test')->where('site_id', $site->id)->exists())->toBeTrue()
        ->and($tenancy->enter($site->id, fn () => SiteModule::query()->where('enabled', true)->pluck('module_key')->sort()->values()->all()))->toBe(['cms', 'media'])
        ->and($tenancy->enter($site->id, fn () => SiteLocale::query()->where('is_default', true)->pluck('locale')->all()))->toBe(['vi']);

    // Owner can manage content within the site (owner role = all tenant permissions).
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');
    expect($tenancy->enter($site->id, fn () => $owner->fresh()->can('entries.publish')))->toBeTrue();
});

it('is idempotent — re-running provisions nothing new', function () {
    $first = provisionAnVinh();
    $second = provisionAnVinh();

    expect($second->id)->toBe($first->id)
        ->and(Site::query()->count())->toBe(1)
        ->and(Domain::query()->where('hostname', 'anvinh.test')->count())->toBe(1)
        ->and(User::query()->where('email', 'owner@anvinh.test')->count())->toBe(1);
});

it('seeds a "posts" nav section bound to a tin-tuc post category', function () {
    $site = provisionAnVinh();
    $tenancy = app(Tenancy::class);

    $tenancy->enter($site->id, function () {
        $section = ContentSection::query()->where('key', 'posts')->first();
        $category = Taxonomy::query()->where('vocabulary', 'post_cat')->first();

        expect($section)->not->toBeNull()
            ->and($section->entry_type)->toBe('post')
            ->and($section->permission_prefix)->toBe('entries')
            ->and($section->sort_order)->toBe(1)
            ->and($category)->not->toBeNull()
            ->and($section->taxonomy_id)->toBe($category->id);

        $translation = $category->translationFor('vi');
        expect($translation)->not->toBeNull()
            ->and($translation->slug)->toBe('tin-tuc')
            ->and($translation->name)->toBe('Tin tức');
    });
});

it('seeds the posts section + category idempotently', function () {
    $site = provisionAnVinh();
    provisionAnVinh();
    $tenancy = app(Tenancy::class);

    $tenancy->enter($site->id, function () {
        expect(ContentSection::query()->where('key', 'posts')->count())->toBe(1)
            ->and(Taxonomy::query()->where('vocabulary', 'post_cat')->count())->toBe(1)
            ->and(TaxonomyTranslation::query()->where('slug', 'tin-tuc')->count())->toBe(1);
    });
});

it('isolates the provisioned site from another tenant', function () {
    provisionAnVinh();
    $other = app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Other Co',
        siteName: 'Other',
        hostname: 'other.test',
        ownerEmail: 'owner@other.test',
        ownerName: 'Other Owner',
        ownerPassword: 'another-password',
    ));

    $tenancy = app(Tenancy::class);
    $anVinhOwner = User::query()->firstWhere('email', 'owner@anvinh.test');

    // An Vinh's owner has NO authority on the other site.
    expect($tenancy->enter($other->id, fn () => $anVinhOwner->fresh()->can('entries.publish')))->toBeFalse();
});
