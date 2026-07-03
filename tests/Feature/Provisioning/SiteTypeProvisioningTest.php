<?php

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Rbac\Models\Role;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteType;
use Illuminate\Support\Facades\DB;

/*
 * T2 increment-2 — ProvisionSite is wired to the site_type axis (design.md §15.2). New sites are
 * stamped `corporate` and their roles resolve through the type-keyed template registry. corporate's
 * template == the existing 6 roles, so this is behavior-preserving (regression guard on the
 * tenantRoleTemplates() signature change).
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();
});

function provisionCorporate(): Site
{
    return app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Corp Co',
        siteName: 'Corp',
        hostname: 'corp.test',
        ownerEmail: 'owner@corp.test',
        ownerName: 'Corp Owner',
        ownerPassword: 'a-strong-password',
    ));
}

it('stamps a newly provisioned site with the corporate site_type', function (): void {
    $site = provisionCorporate();
    $corporate = SiteType::query()->where('key', 'corporate')->first();

    expect($corporate)->not->toBeNull()
        ->and($site->fresh()->site_type_id)->toBe($corporate->id);
});

it('materializes the 6 corporate role templates with their canonical levels', function (): void {
    $site = provisionCorporate();

    $levels = app(Tenancy::class)->enter($site->id, fn () => Role::query()
        ->whereIn('name', ['owner', 'admin', 'editor', 'author', 'contributor', 'viewer'])
        ->pluck('level', 'name')
        ->all());

    expect($levels)->toHaveCount(6)
        ->and($levels['owner'])->toBe(90)
        ->and($levels['admin'])->toBe(70)
        ->and($levels['editor'])->toBe(50)
        ->and($levels['author'])->toBe(30)
        ->and($levels['contributor'])->toBe(20)
        ->and($levels['viewer'])->toBe(10);
});
