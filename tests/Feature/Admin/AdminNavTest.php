<?php

use App\Modules\Admin\Navigation\AdminNav;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Rbac\Context\CurrentSiteTeamResolver;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteModule;
use Illuminate\Support\Facades\DB;

/*
 * Increment-3 — AdminNav reconciles to ModuleCatalog as the single STRUCTURAL source
 * (order / identity / route / nav_permission), intersected with site ENTITLEMENT
 * (design.md §15.2: site_type.module ∪ site_modules override) and the user's RBAC.
 *
 * Two label axes: feature items (dashboard/users/settings) take their label from the
 * i18n layer (__()), display-locale aware; content slots (posts/pages) take theirs from
 * content_sections (content-locale, single string), born via the real ProvisionSite —
 * never hand-inserted. One producer, one $navItems, no hardcoded <li>.
 */

beforeEach(function () {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();

    // A: the corporate site (id 1) + its "Tin tức"/"Trang" content_sections are born via
    // the real provisioning primitive — not seeded by hand.
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'An Vinh',
        siteName: 'An Vinh',
        hostname: 'anvinh.test',
        ownerEmail: 'owner@anvinh.test',
        ownerName: 'An Vinh Owner',
        ownerPassword: 'a-strong-password',
        locales: ['vi'],
        modules: ['cms', 'media'],
    ));
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Run a callback inside the An Vinh (site 1) tenant context, asserting the spatie team
 * is bound to the active site (② — so Gate::forUser resolves tenant-scoped permissions,
 * not platform). Used by the items() unit assertions.
 *
 * @template T
 *
 * @param  Closure():T  $cb
 * @return T
 */
function navInAnVinh(Closure $cb): mixed
{
    return app(Tenancy::class)->enter(1, function () use ($cb) {
        expect(app(CurrentSiteTeamResolver::class)->getPermissionsTeamId())->toBe(1);

        return $cb();
    });
}

/**
 * The render-ordered nav item keys for a user (must run inside a tenant context).
 *
 * @return list<string>
 */
function navItemKeys(User $user): array
{
    return array_map(fn ($item) => $item->key, app(AdminNav::class)->items($user));
}

/**
 * A real second site_type ('news', whose modulesFor() is empty) with its own active
 * site + domain — the minimal honest fixture for the entitlement filter. No
 * content_sections (A intact); built via the privileged connection so RLS does not
 * block setup.
 */
function provisionNewsTypeSite(string $hostname = 'news.test'): int
{
    $admin = DB::connection('pgsql_admin');

    // site_types is the GLOBAL catalog (not truncated between tests — ProvisionSite relies
    // on the pre-seeded `corporate` row). Idempotently reuse the `news` type so repeated
    // fixtures (and suite re-runs) don't collide on the unique key.
    $typeId = $admin->table('site_types')->where('key', 'news')->value('id')
        ?? $admin->table('site_types')->insertGetId([
            'key' => 'news',
            'label' => json_encode(['vi' => 'Tin tức', 'en' => 'News', 'ja' => 'ニュース']),
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    $siteId = $admin->table('sites')->insertGetId([
        'ulid' => str_pad('NEWS', 26, '0'),
        'tenant_id' => 1, // reuse An Vinh's tenant (created in beforeEach provisioning)
        'name' => 'News Site',
        'status' => 'active',
        'site_type_id' => $typeId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $admin->table('domains')->insert([
        'site_id' => $siteId, 'hostname' => $hostname, 'kind' => 'subdomain',
        'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $admin->table('site_locales')->insert([
        'site_id' => $siteId, 'locale' => 'vi', 'is_default' => true,
        'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $siteId;
}

/*
|--------------------------------------------------------------------------
| Exact nav contract (catalog order, labels, urls)
|--------------------------------------------------------------------------
*/

it('builds the exact owner nav in catalog order with the right labels and urls', function () {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    [$keys, $labels, $urls] = navInAnVinh(function () use ($owner) {
        $items = app(AdminNav::class)->items($owner);

        return [
            array_map(fn ($i) => $i->key, $items),
            array_map(fn ($i) => $i->label, $items),
            array_map(fn ($i) => $i->url, $items),
        ];
    });

    expect($keys)->toBe(['dashboard', 'pages', 'posts', 'users', 'settings']);

    // Feature labels via __() (display-locale); content labels from content_sections.
    // Order = catalog sort_order, pages(1) before posts(2) — behaviour-preserving vs the
    // content_sections order seeded today.
    expect($labels)->toBe([
        __('admin.dashboard'),
        'Trang',
        'Tin tức',
        __('admin.users'),
        __('admin.settings'),
    ]);

    expect($urls)->toBe([
        route('admin.dashboard'),
        route('admin.entries.index', ['section' => 'pages']),
        route('admin.entries.index', ['section' => 'posts']),
        route('admin.users.index'),
        route('admin.settings'),
    ]);
});

it('shows a member without entries.view or users.manage only the two always-visible items', function () {
    $member = User::factory()->create();

    $keys = navInAnVinh(function () use ($member) {
        grantRole($member, 'media-only', ['media.view']); // lacks entries.view + users.manage

        return navItemKeys($member);
    });

    expect($keys)->toBe(['dashboard', 'settings']);
});

it('hides posts/pages without entries.view and users without users.manage', function () {
    $member = User::factory()->create();

    $keys = navInAnVinh(function () use ($member) {
        grantRole($member, 'author', ['entries.view']); // has entries.view, lacks users.manage

        return navItemKeys($member);
    });

    expect($keys)->toBe(['dashboard', 'pages', 'posts', 'settings'])
        ->and($keys)->not->toContain('users');
});

it('still shows dashboard + settings to a user with zero permissions (null nav_permission = always)', function () {
    $nobody = User::factory()->create();

    $keys = navInAnVinh(function () use ($nobody) {
        grantRole($nobody, 'empty', []);

        return navItemKeys($nobody);
    });

    expect($keys)->toBe(['dashboard', 'settings']);
});

it('never renders catalog modules with no admin route (the seven unbuilt)', function () {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    $keys = navInAnVinh(fn () => navItemKeys($owner));

    foreach (['media', 'contacts', 'menus', 'banners', 'roles', 'trash', 'profile'] as $unbuilt) {
        expect($keys)->not->toContain($unbuilt);
    }
});

it('emits no duplicate nav keys', function () {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    $keys = navInAnVinh(fn () => navItemKeys($owner));

    expect($keys)->toBe(array_values(array_unique($keys)));
});

/*
|--------------------------------------------------------------------------
| Single producer (no orphan hardcoded <li> in the rendered shell)
|--------------------------------------------------------------------------
*/

it('is the single nav producer: the rendered shell shows each destination once, no orphan hardcoded <li>', function () {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    $html = $this->actingAs($owner)->get('http://anvinh.test/admin')
        ->assertOk()
        ->getContent();

    // ② shell-render: the owner only resolves users.manage because the spatie team = the
    // active site during the request — so the users item proves team context is live.
    expect($html)->toContain(__('admin.users'));

    // One <li> per destination: a leftover hardcoded item alongside the catalog loop would
    // double a nav-exclusive href. (Paths, not absolute urls — host-independent.)
    expect(substr_count($html, '/admin/users'))->toBe(1)
        ->and(substr_count($html, 'section=posts'))->toBe(1)
        ->and(substr_count($html, 'section=pages'))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Entitlement filter (① — site_type.module ∪ site_modules override) ∩ RBAC
|--------------------------------------------------------------------------
*/

it('entitlement hides a routable module the site_type does not grant — even when the user holds its permission', function () {
    $newsSite = provisionNewsTypeSite();
    $user = User::factory()->create();

    $keys = app(Tenancy::class)->enter($newsSite, function () use ($user, $newsSite) {
        expect(app(CurrentSiteTeamResolver::class)->getPermissionsTeamId())->toBe($newsSite);
        grantRole($user, 'news-admin', ['users.manage', 'entries.view']);

        return navItemKeys($user);
    });

    // 'users' is routable and the user holds users.manage, but site_type 'news'
    // (modulesFor = []) does not entitle it and there is no override → filtered out.
    // Dropping the entitlement filter would surface it: this is the discriminating guard.
    expect($keys)->not->toContain('users');
});

it('surfaces a module beyond the site_type set when a site_modules override enables it (union, not hide)', function () {
    $newsSite = provisionNewsTypeSite();
    $user = User::factory()->create();

    $keys = app(Tenancy::class)->enter($newsSite, function () use ($user) {
        grantRole($user, 'news-admin', ['users.manage']);
        SiteModule::query()->create(['module_key' => 'users', 'enabled' => true]); // entitlement override

        return navItemKeys($user);
    });

    expect($keys)->toContain('users');
});
