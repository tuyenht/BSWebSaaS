<?php

use App\Modules\Admin\Navigation\AdminNav;
use App\Modules\Admin\Navigation\NavItem;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use Illuminate\Support\Facades\DB;

/*
 * P1 slice-1: the Catalog feature leaf (design.md §11, plan Q5). Products become a feature module
 * (ModuleCatalog key `catalog`, entitled to corporate) with its own admin surface — replacing the
 * legacy products-as-posts content_section, which AdminNav filters to avoid a double surface until
 * the P3 ETL removes the data. Read access is gated by catalog.view (route + policy).
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         platform_settings, site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();
    $this->artisan('rbac:sync')->assertSuccessful();

    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'An Vinh', siteName: 'An Vinh', hostname: 'anvinh.test',
        ownerEmail: 'owner@anvinh.test', ownerName: 'An Vinh Owner',
        ownerPassword: 'a-strong-password', locales: ['vi'], modules: ['cms', 'media'],
    ));
});

it('surfaces Catalog as a content-style group — Thêm mới · Danh sách · Chuyên mục', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');
    $items = app(Tenancy::class)->enter(1, fn (): array => (new AdminNav)->items($owner));

    $catalog = collect($items)->firstWhere('key', 'catalog');
    expect($catalog)->not->toBeNull()
        ->and($catalog->icon)->toBe('cube')
        ->and($catalog->hasChildren())->toBeTrue()
        ->and(collect($catalog->children)->pluck('key')->all())
        ->toBe(['catalog.create', 'catalog.list', 'catalog.taxonomy'])
        ->and(collect($catalog->children)->firstWhere('key', 'catalog.list')?->url)->toContain('/admin/catalog');
});

it('trims the Catalog group to just the list for a view-only member', function (): void {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'cat-viewer', ['catalog.view']));

    $items = app(Tenancy::class)->enter(1, fn (): array => (new AdminNav)->items($viewer->fresh()));

    $catalog = collect($items)->firstWhere('key', 'catalog');
    expect($catalog)->not->toBeNull()
        ->and(collect($catalog->children)->pluck('key')->all())->toBe(['catalog.list']); // no dead-end create/categories
});

it('filters the legacy products content_section so it does not double-surface', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');
    // An Vinh's pre-migration products-as-posts section (removed for good by the P3 ETL).
    app(Tenancy::class)->enter(1, fn () => ContentSection::query()->create([
        'key' => 'products', 'label' => 'Sản phẩm', 'entry_type' => 'post',
        'permission_prefix' => 'entries', 'sort_order' => 3,
    ]));

    $items = app(Tenancy::class)->enter(1, fn (): array => (new AdminNav)->items($owner));
    $productsSection = collect($items)->first(fn (NavItem $i): bool => $i->key === 'products' && $i->hasChildren());

    expect($productsSection)->toBeNull()                                    // legacy section hidden
        ->and(collect($items)->firstWhere('key', 'catalog'))->not->toBeNull(); // catalog leaf present
});

it('gates the catalog index route — 200 with catalog.view, 403 without', function (): void {
    $viewer = User::factory()->create();
    $noperm = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($viewer, $noperm): void {
        grantRole($viewer, 'cat-viewer', ['catalog.view']);
        grantRole($noperm, 'no-catalog', ['entries.view']);
    });

    $this->actingAs($viewer->fresh())->get('http://anvinh.test/admin/catalog')->assertOk();
    $this->actingAs($noperm->fresh())->get('http://anvinh.test/admin/catalog')->assertForbidden();
});
