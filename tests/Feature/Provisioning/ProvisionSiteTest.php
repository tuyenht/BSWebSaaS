<?php

use App\Modules\Cms\Actions\Menus\DeleteMenuItem;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Provisioning\Exceptions\OwnerChangeRefusedException;
use App\Modules\Rbac\Support\MembershipLock;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteModule;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Tenancy\Registry\ModuleCatalog;
use Illuminate\Support\Facades\DB;

/*
 * P0.6 — ProvisionSite is the SaaS control-plane primitive: it builds An Vinh (and
 * every future site) end-to-end and idempotently. It exercises all three defense
 * layers (global identity/site → per-site entitlement + RBAC under tenant context).
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();
});

function provisionAnVinh(string $ownerEmail = 'owner@anvinh.test'): Site
{
    return app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'An Vinh',
        siteName: 'An Vinh',
        hostname: 'anvinh.platform.test',
        ownerEmail: $ownerEmail,
        ownerName: 'An Vinh Owner',
        ownerPassword: 'a-strong-password',
        locales: ['vi', 'en', 'ja'],
        modules: ['cms', 'media'],
    ));
}

it('provisions a full, active site stack', function (): void {
    $site = provisionAnVinh();
    $tenancy = app(Tenancy::class);

    expect($site->status)->toBe('active')
        ->and(Domain::query()->where('hostname', 'anvinh.platform.test')->where('site_id', $site->id)->exists())->toBeTrue()
        ->and($tenancy->enter($site->id, fn () => SiteModule::query()->where('enabled', true)->pluck('module_key')->sort()->values()->all()))->toBe(collect(ModuleCatalog::modulesFor('corporate'))->sort()->values()->all())
        ->and($tenancy->enter($site->id, fn () => SiteLocale::query()->where('is_default', true)->pluck('locale')->all()))->toBe(['vi'])
        ->and($tenancy->enter($site->id, fn () => SiteLocale::query()->orderBy('locale')->pluck('locale')->all()))->toBe(['en', 'ja', 'vi']);

    // Owner can manage content within the site (owner role = all tenant permissions).
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');
    expect($tenancy->enter($site->id, fn () => $owner->fresh()->can('entries.publish')))->toBeTrue();
});

it('gives the first owner the owner role, though platform staff change no member of the site', function (): void {
    $site = provisionAnVinh();
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');
    $this->artisan('rbac:sync')->assertSuccessful();
    $staff = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $staff->assignRole('super_admin'));
    $lock = app(MembershipLock::class);

    [$roles, $ownerLocked, $staffLocked] = app(Tenancy::class)->enter($site->id, fn (): array => [
        $owner->fresh()?->roles()->pluck('name')->all(),
        $lock->outsideStaff($owner->fresh(), $site->id),
        $lock->outsideStaff($staff->fresh(), $site->id),
    ]);

    expect($roles)->toBe(['owner'])
        ->and($ownerLocked)->toBeFalse()
        ->and($staffLocked)->toBeTrue();
});

/** The emails of the site's owners. */
function provisionOwners(Site $site): array
{
    return app(Tenancy::class)->enter($site->id, fn (): array => User::query()
        ->whereExists(fn ($q) => $q->selectRaw('1')->from('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereColumn('model_has_roles.model_id', 'users.id')
            ->where('model_has_roles.site_id', $site->id)
            ->where('roles.name', 'owner'))
        ->orderBy('email')->pluck('email')->all());
}

it('refuses a re-run that names someone else as owner, and points to site:recover-owner', function (): void {
    $site = provisionAnVinh();

    expect(fn () => provisionAnVinh('nguoi-la@example.test'))->toThrow(OwnerChangeRefusedException::class);

    expect(provisionOwners($site))->toBe(['owner@anvinh.test'])
        ->and(User::query()->where('email', 'nguoi-la@example.test')->exists())->toBeFalse()
        ->and(__('provisioning.owner_change_refused', ['host' => 'anvinh.platform.test']))->toContain('site:recover-owner');
});

it('refuses a new owner for a live website whose owners are all gone: that is a recovery', function (): void {
    $site = provisionAnVinh();
    app(Tenancy::class)->enter($site->id, fn () => User::query()->firstWhere('email', 'owner@anvinh.test')?->removeRole('owner'));

    expect(fn () => provisionAnVinh('nguoi-la@example.test'))->toThrow(OwnerChangeRefusedException::class);
    expect(provisionOwners($site))->toBe([]);
});

it('still finishes a first run that stopped before its owner was given', function (): void {
    $site = provisionAnVinh();
    // as a run that failed midway leaves it: still provisioning, nobody holding the owner role
    app(Tenancy::class)->enter($site->id, fn () => User::query()->firstWhere('email', 'owner@anvinh.test')?->removeRole('owner'));
    $site->update(['status' => 'provisioning']);

    $finished = provisionAnVinh('chu-moi@example.test');

    expect($finished->status)->toBe('active')
        ->and(provisionOwners($site))->toBe(['chu-moi@example.test']);
});

it('refuses the second of two unfinished runs with different owners once the first has given its owner', function (): void {
    $site = provisionAnVinh();
    app(Tenancy::class)->enter($site->id, fn () => User::query()->firstWhere('email', 'owner@anvinh.test')?->removeRole('owner'));
    $site->update(['status' => 'provisioning']);

    // The other run (another connection, committed at once) gives its owner while this one waits on
    // the owner lock, after this one's first check passed.
    $admin = DB::connection('pgsql_admin');
    $grant = [
        'role_id' => $admin->table('roles')->where('site_id', $site->id)->where('name', 'owner')->value('id'),
        'model_type' => User::class,
        'model_id' => User::query()->where('email', 'owner@anvinh.test')->value('id'),
        'site_id' => $site->id,
    ];
    $done = false;
    DB::listen(function ($query) use (&$done, $site, $admin, $grant): void {
        if (! $done && str_contains($query->sql, 'pg_advisory_xact_lock') && ($query->bindings[0] ?? null) === 'site-owner:'.$site->id) {
            $done = true;
            $admin->table('model_has_roles')->insert($grant);
        }
    });

    expect(fn () => provisionAnVinh('chu-moi@example.test'))->toThrow(OwnerChangeRefusedException::class);
    expect($done)->toBeTrue()
        ->and(provisionOwners($site))->toBe(['owner@anvinh.test'])
        ->and(User::query()->where('email', 'chu-moi@example.test')->exists())->toBeFalse();
});

it('creates the platform subdomain already verified and primary', function (): void {
    $site = provisionAnVinh();

    $domain = Domain::query()->where('site_id', $site->id)->sole();

    expect($domain->kind)->toBe('subdomain')
        ->and($domain->is_primary)->toBeTrue()
        ->and($domain->verified_at)->not->toBeNull()
        ->and($domain->ssl_status)->toBeNull();
});

it('is idempotent — re-running provisions nothing new', function (): void {
    $first = provisionAnVinh();
    $second = provisionAnVinh();

    expect($second->id)->toBe($first->id)
        ->and(Site::query()->count())->toBe(1)
        ->and(Domain::query()->where('hostname', 'anvinh.platform.test')->count())->toBe(1)
        ->and(User::query()->where('email', 'owner@anvinh.test')->count())->toBe(1);
});

it('seeds a "posts" nav section bound to a tin-tuc post category', function (): void {
    $site = provisionAnVinh();
    $tenancy = app(Tenancy::class);

    $tenancy->enter($site->id, function (): void {
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

it('seeds the posts section + category idempotently', function (): void {
    $site = provisionAnVinh();
    provisionAnVinh();
    $tenancy = app(Tenancy::class);

    $tenancy->enter($site->id, function (): void {
        expect(ContentSection::query()->where('key', 'posts')->count())->toBe(1)
            ->and(Taxonomy::query()->where('vocabulary', 'post_cat')->count())->toBe(1)
            ->and(TaxonomyTranslation::query()->where('slug', 'tin-tuc')->count())->toBe(1);
    });
});

it('routes the seeded category at /tin-tuc', function (): void {
    $site = provisionAnVinh();
    $tenancy = app(Tenancy::class);

    $tenancy->enter($site->id, function (): void {
        $category = Taxonomy::query()->where('vocabulary', 'post_cat')->first();

        expect(SiteRoute::query()->where('taxonomy_id', $category->id)->value('path'))->toBe('/tin-tuc');
    });
});

it('seeds a primary menu (home + tin-tuc) assigned for the default locale', function (): void {
    $site = provisionAnVinh();
    $tenancy = app(Tenancy::class);

    $tenancy->enter($site->id, function (): void {
        $menu = Menu::query()->where('handle', 'main-vi')->first();
        expect($menu)->not->toBeNull();

        $assignment = MenuAssignment::query()->where('region_key', 'primary')->where('locale', 'vi')->first();
        expect($assignment)->not->toBeNull()
            ->and($assignment->menu_id)->toBe($menu->id);

        $home = MenuItem::query()->where('menu_id', $menu->id)->where('link_type', 'dynamic')->where('dynamic_key', 'home')->first();
        $news = MenuItem::query()->where('menu_id', $menu->id)->where('link_type', 'taxonomy')->first();
        expect($home)->not->toBeNull()
            ->and($home->translationFor('vi')->label)->toBe('Trang chủ')
            ->and($news)->not->toBeNull()
            ->and($news->translationFor('vi')->label)->toBe('Tin tức');
    });
});

it('seeds the menu idempotently', function (): void {
    $site = provisionAnVinh();
    provisionAnVinh();
    $tenancy = app(Tenancy::class);

    $tenancy->enter($site->id, function (): void {
        expect(Menu::query()->count())->toBe(1)
            ->and(MenuAssignment::query()->where('region_key', 'primary')->where('locale', 'vi')->count())->toBe(1)
            ->and(MenuItem::query()->count())->toBe(2);
    });
});

it('isolates the provisioned site from another tenant', function (): void {
    provisionAnVinh();
    $other = app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Other Co',
        siteName: 'Other',
        hostname: 'other.platform.test',
        ownerEmail: 'owner@other.test',
        ownerName: 'Other Owner',
        ownerPassword: 'another-password',
    ));

    $tenancy = app(Tenancy::class);
    $anVinhOwner = User::query()->firstWhere('email', 'owner@anvinh.test');

    // An Vinh's owner has NO authority on the other site.
    expect($tenancy->enter($other->id, fn () => $anVinhOwner->fresh()->can('entries.publish')))->toBeFalse();
});

it('provisioned site_modules keys are all valid ModuleCatalog keys — no orphan like "cms"', function (): void {
    $site = provisionAnVinh();
    $tenancy = app(Tenancy::class);

    $catalogKeys = ModuleCatalog::modulesFor('corporate');
    $provisioned = $tenancy->enter($site->id, fn () => SiteModule::query()->pluck('module_key')->all());

    $orphans = array_diff($provisioned, $catalogKeys);
    expect($orphans)->toBeEmpty('These module_keys are not in ModuleCatalog: '.implode(', ', $orphans));

    // The full catalog set must be present — no subset, no orphan.
    expect(collect($provisioned)->sort()->values()->all())->toBe(collect($catalogKeys)->sort()->values()->all());
});

it('seeds a contact page routed at /lien-he so the homepage CTA resolves', function (): void {
    $site = provisionAnVinh();
    $tenancy = app(Tenancy::class);

    $tenancy->enter($site->id, function (): void {
        $route = SiteRoute::query()->where('path', '/lien-he')->first();
        expect($route)->not->toBeNull();

        $entry = Entry::query()->find($route->entry_id);
        expect($entry->type)->toBe('page');

        $tr = $entry->translations()->where('locale', 'vi')->first();
        expect($tr->slug)->toBe('lien-he')
            ->and($tr->body['blocks'][0]['type'])->toBe('contact');
    });
});

it('neither duplicates nor brings back a menu item staff moved to the trash, and keeps a region that follows another', function (): void {
    $site = provisionAnVinh();
    $tenancy = app(Tenancy::class);
    $editor = menuEditor($site->id);

    $tenancy->enter($site->id, function () use ($editor): void {
        app(DeleteMenuItem::class)->handle($editor, MenuItem::query()->where('dynamic_key', 'home')->firstOrFail());
        MenuAssignment::query()->create(['region_key' => 'handheld', 'locale' => 'vi', 'same_as_region' => 'primary']);
    });

    provisionAnVinh();

    $tenancy->enter($site->id, function (): void {
        expect(MenuItem::withTrashed()->where('dynamic_key', 'home')->count())->toBe(1)
            ->and(MenuItem::onlyTrashed()->where('dynamic_key', 'home')->count())->toBe(1)
            ->and(MenuItem::withTrashed()->count())->toBe(2)
            ->and(MenuAssignment::query()->where('region_key', 'handheld')->value('same_as_region'))->toBe('primary');
    });
});

it('records the contact page of a site whose /lien-he route already exists, when its setting is missing', function (): void {
    $site = provisionAnVinh();
    $ulid = app(Tenancy::class)->enter($site->id, function (): string {
        SiteSetting::query()->where('group_name', 'catalog')->where('key', 'contact_page')->delete();

        return SiteRoute::query()->where('path', '/lien-he')->firstOrFail()->entry->ulid;
    });

    provisionAnVinh();

    $value = app(Tenancy::class)->enter($site->id, fn () => SiteSetting::query()->where('group_name', 'catalog')->where('key', 'contact_page')->value('value'));
    expect($value)->toBe(['entry' => $ulid]);
});
