<?php

namespace App\Modules\Provisioning\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Forms\Actions\EnsureContactForm;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Provisioning\Exceptions\HostnameOwnedByAnotherTenantException;
use App\Modules\Provisioning\Exceptions\SiteLimitReachedException;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteModule;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Tenancy\Models\SiteType;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Registry\ModuleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Genesis: provision a site end-to-end and leave it active. Idempotent (anchored on
 * the unique primary domain), so it is safe for An Vinh and for site #2..#10000.
 * A re-run must name the site's own customer (HostnameOwnedByAnotherTenantException).
 *
 * Order respects the defense layers: global identity/site rows first, then per-site
 * (RLS) config + RBAC inside the tenant context, then flip the site to 'active'.
 *
 * The single path that creates a website, so the customer-wide `sites.max` holds whoever calls it:
 * a NEW website past the customer's plan limit is refused (SiteLimitReachedException). Every
 * website not soft-deleted counts, whatever its status — suspended, archived, and one left at
 * `provisioning` by a run that failed midway. Re-running for a website that already exists (same
 * hostname) creates nothing and is never refused, which is also how a failed run is finished.
 */
final readonly class ProvisionSite
{
    /** Localized slug of the default post category seeded for every site. */
    private const string NEWS_SLUG = 'tin-tuc';

    public function __construct(
        private Tenancy $tenancy,
        private PermissionRegistrar $registrar,
        private RouteSync $routes,
        private TaxonomyPaths $paths,
        private SiteResolver $sites,
        private Entitlement $entitlement,
    ) {}

    public function handle(ProvisionSiteData $data): Site
    {
        // 1) Global rows (no RLS): tenant, site, primary domain, owner, membership.
        // Only a live domain identifies a site: a name merely waiting on a site is not one.
        $domain = Domain::query()->where('hostname', $data->hostname)->whereNotNull('verified_at')->first();

        if ($domain === null) {
            $tenant = $data->tenantId !== null
                ? Tenant::query()->findOrFail($data->tenantId)
                : Tenant::query()->firstOrCreate(['name' => $data->tenantName]);

            $site = DB::transaction(function () use ($tenant, $data): Site {
                // Serialize a customer's new websites: two concurrent provisions must not both pass
                // the limit. Lock first, then count (a soft-deleted website frees its slot).
                Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail(['id']);

                // A run for the same hostname may have finished while this one waited on the lock:
                // that makes this a re-run, which is never refused.
                $raced = Domain::query()->where('hostname', $data->hostname)->whereNotNull('verified_at')->first();
                if ($raced !== null) {
                    return $raced->site ?? throw new \RuntimeException("Domain {$data->hostname} has no site.");
                }

                $count = Site::query()->where('tenant_id', $tenant->id)->count();
                $limit = (int) $this->entitlement->tenantFeatureValue($tenant->id, 'sites.max');

                if ($count >= $limit) {
                    throw new SiteLimitReachedException($count, $limit);
                }

                $site = Site::query()->create([
                    'tenant_id' => $tenant->id,
                    'name' => $data->siteName,
                    'status' => 'provisioning',
                ]);
                Domain::query()->create([
                    'site_id' => $site->id,
                    'hostname' => $data->hostname,
                    'kind' => 'subdomain',
                    'is_primary' => true,
                    // A platform subdomain is ours by construction: verified from birth.
                    'verified_at' => now(),
                ]);

                return $site;
            });
            // The host may be remembered as unknown from a request made before it existed.
            $this->sites->forget($data->hostname);
        } else {
            $site = $domain->site ?? throw new \RuntimeException("Domain {$data->hostname} has no site.");
            $tenant = $this->ownTenant($site, $data);
        }

        // Functional shape (design.md §15.2): GĐ1 ships only `corporate`. Stamp it idempotently so a
        // re-run also backfills a site provisioned before the type axis existed.
        $siteType = SiteType::query()->where('key', 'corporate')->firstOrFail();
        if ($site->site_type_id !== $siteType->id) {
            $site->forceFill(['site_type_id' => $siteType->id])->save();
        }
        $siteTypeKey = $siteType->key;

        $owner = User::query()->firstWhere('email', $data->ownerEmail) ?? User::query()->create([
            'name' => $data->ownerName,
            'email' => $data->ownerEmail,
            'password' => Hash::make($data->ownerPassword ?? Str::password(20)),
            'email_verified_at' => now(),
        ]);
        // Attach membership from the User side (Tenancy must not depend on Identity).
        $owner->tenants()->syncWithoutDetaching([$tenant->id]);

        // Make sure the permission catalog exists (idempotent; team-agnostic).
        foreach (PermissionRegistry::permissions() as $permission) {
            Permission::findOrCreate($permission, PermissionRegistry::GUARD);
        }

        // 2) Per-site setup INSIDE the tenant context (RLS + spatie team = this site).
        $this->tenancy->enter($site->id, function () use ($data, $owner, $siteTypeKey): void {
            foreach ($data->locales as $locale) {
                SiteLocale::query()->firstOrCreate(
                    ['locale' => $locale],
                    ['is_default' => $locale === $data->defaultLocale, 'enabled' => true],
                );
            }

            SiteProfile::query()->firstOrCreate([], $data->profile);

            SiteSetting::query()->firstOrCreate(
                ['group_name' => 'general', 'key' => 'site_name'],
                ['value' => ['default' => $data->siteName]],
            );

            // Entitlement (design.md §15.2): always write the site_type's canonical module
            // set from ModuleCatalog, never from the DTO default — keeps ONE vocabulary.
            foreach (ModuleCatalog::modulesFor($siteTypeKey) as $module) {
                SiteModule::query()->firstOrCreate(['module_key' => $module], ['enabled' => true]);
            }

            // Baseline admin nav section: "Trang" (pages). The dynamic menu (AdminNav)
            // surfaces it ∩ the user's entries.* permissions. More sections (post
            // collections) are added per content type as they ship.
            ContentSection::query()->firstOrCreate(
                ['key' => 'pages'],
                ['label' => 'Trang', 'entry_type' => 'page', 'permission_prefix' => 'entries', 'sort_order' => 0],
            );

            // Default "Tin tức" post collection: a post_cat taxonomy + its nav section.
            $news = $this->seedNews($data->defaultLocale);

            // Default storefront navigation: a "primary" menu (home + news), bound to the
            // primary region for the default locale (model-B; EN/JA menus arrive with content).
            $this->seedMenu($data->defaultLocale, $news);

            foreach (PermissionRegistry::tenantRoleTemplates($siteTypeKey) as $name => $spec) {
                $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
                $role->forceFill(['level' => $spec['level'], 'is_system' => true])->save();

                $permissions = $spec['permissions'] === '*'
                    ? PermissionRegistry::TENANT_PERMISSIONS
                    : $spec['permissions'];
                $role->syncPermissions($permissions);
            }

            $owner->assignRole('owner');

            $this->seedHomepage($data, $owner);
            $this->seedContactForm($data);
            $this->seedContactPage($data, $owner);
        });

        $this->registrar->forgetCachedPermissions();

        // 3) Activate.
        $site->update(['status' => 'active']);

        Audit::record('provisioning.site.provisioned', [
            'site_id' => $site->id,
            'auditable_type' => $site::class,
            'auditable_id' => $site->id,
            'meta' => ['hostname' => $data->hostname, 'owner' => $data->ownerEmail],
        ]);

        return $site->refresh();
    }

    /**
     * A re-run continues an existing site, so the input must name that site's own customer: by id
     * when given (it wins over the name), else by exact name. Anything else — another customer, an
     * unknown name, a removed customer — is refused before any write, or the owner would become an
     * owner of another customer's website. Returns the site's tenant, never a lookup by name (names
     * are not unique).
     */
    private function ownTenant(Site $site, ProvisionSiteData $data): Tenant
    {
        $tenant = $site->tenant;

        $named = $tenant !== null && ($data->tenantId !== null
            ? $tenant->id === $data->tenantId
            : $tenant->name === $data->tenantName);

        if (! $named) {
            throw new HostnameOwnedByAnotherTenantException($data->hostname);
        }

        return $tenant;
    }

    /**
     * Seed a live published homepage so a provisioned site is immediately reachable
     * (the seed of "site sống <10 phút"). Idempotent on the home route.
     */
    private function seedHomepage(ProvisionSiteData $data, User $owner): void
    {
        if (SiteRoute::query()->where('path', '/')->exists()) {
            return;
        }

        $entry = Entry::query()->create([
            'type' => 'page',
            'status' => 'published',
            'author_id' => $owner->id,
            'published_at' => now(),
        ]);

        $entry->translations()->create([
            'locale' => $data->defaultLocale,
            'title' => $data->siteName,
            'slug' => 'home',
            'status' => 'published',
            // A composed homepage: components sourced from different objects.
            'body' => ['blocks' => [
                ['type' => 'hero', 'props' => [
                    'heading' => $data->siteName,
                    'subheading' => 'Chào mừng đến với '.$data->siteName,
                ]],
                ['type' => 'latest_posts', 'props' => ['heading' => 'Tin mới nhất', 'limit' => 3]],
                ['type' => 'cta', 'props' => [
                    'heading' => 'Liên hệ '.$data->siteName,
                    'button_label' => 'Liên hệ ngay',
                    'button_url' => '/lien-he',
                ]],
            ]],
        ]);

        SiteRoute::query()->create([
            'locale' => $data->defaultLocale,
            'path' => '/',
            'entry_id' => $entry->id,
        ]);
    }

    /**
     * Seed a published contact page at /lien-he so the homepage CTA (button_url '/lien-he')
     * resolves on every provisioned site. Generic by default (owner fills NAP via the editor);
     * a site's real NAP is seeded later. Idempotent on the contact route.
     */
    private function seedContactPage(ProvisionSiteData $data, User $owner): void
    {
        if (SiteRoute::query()->where('path', '/lien-he')->exists()) {
            return;
        }

        $entry = Entry::query()->create([
            'type' => 'page',
            'status' => 'published',
            'author_id' => $owner->id,
            'published_at' => now(),
        ]);

        $entry->translations()->create([
            'locale' => $data->defaultLocale,
            'title' => 'Liên hệ',
            'slug' => 'lien-he',
            'status' => 'published',
            'body' => ['blocks' => [
                ['type' => 'contact', 'props' => [
                    'heading' => 'Liên hệ '.$data->siteName,
                ]],
                // The lead-capture form (seeded by seedContactForm) submits to /forms/contact.
                ['type' => 'form', 'props' => ['form_key' => 'contact']],
            ]],
        ]);

        SiteRoute::query()->create([
            'locale' => $data->defaultLocale,
            'path' => '/lien-he',
            'entry_id' => $entry->id,
        ]);
    }

    /**
     * Seed the lead-capture contact form (key 'contact') + its fields, so the storefront 'form'
     * block on /lien-he has a form to render and submit to. Lead notifications default to the
     * owner's email (a tenant can edit recipients later). Idempotent — anchored on the unique
     * (site_id, key) and each field's (form_id, key); a re-run never duplicates.
     */
    private function seedContactForm(ProvisionSiteData $data): void
    {
        app(EnsureContactForm::class)->handle([$data->ownerEmail]);
    }

    /**
     * Seed the default "Tin tức" (news) post collection: a post_cat taxonomy + the admin
     * nav section bound to it (entry_type=post). Idempotent — the section is anchored on
     * the unique (site_id, key); the taxonomy (which has no natural unique key) on its
     * localized slug. Runs inside the tenant context so site_id is stamped + RLS-scoped.
     */
    private function seedNews(string $locale): Taxonomy
    {
        $category = $this->firstOrCreateNewsCategory($locale);

        ContentSection::query()->firstOrCreate(
            ['key' => 'posts'],
            [
                'label' => 'Tin tức',
                'entry_type' => 'post',
                'taxonomy_id' => $category->id,
                'permission_prefix' => 'entries',
                'sort_order' => 1,
            ],
        );

        // Give the category a storefront list route (/tin-tuc). Best-effort: never break
        // genesis if an existing page already owns that path (category admin resolves later).
        $translation = $category->translationFor($locale);
        if ($translation instanceof TaxonomyTranslation) {
            try {
                $this->routes->syncTaxonomy($category, $locale, $translation->slug);
            } catch (RouteConflictException) {
                // A page already owns this path; leave it.
            }
        }

        return $category;
    }

    /**
     * Seed the default storefront navigation: a "primary" menu with Trang chủ (home — a
     * dynamic link → '/') + Tin tức (the news category), bound to the 'primary' region for
     * the default locale. An Vinh is model-B (a menu per language) — EN/JA menus + their
     * assignments arrive with that content. Idempotent: the menu is anchored on its handle,
     * each item on its target, the assignment on (region, locale). Runs inside the tenant
     * context so site_id is stamped + RLS-scoped; items are same-site by composite FK.
     */
    private function seedMenu(string $locale, Taxonomy $news): void
    {
        $menu = Menu::query()->firstOrCreate(
            ['handle' => 'main-'.$locale],
            ['name' => 'Menu chính', 'locales' => null],
        );

        $home = MenuItem::query()->firstOrCreate(
            ['menu_id' => $menu->id, 'link_type' => 'dynamic', 'dynamic_key' => 'home'],
            ['position' => 0],
        );
        $home->translations()->firstOrCreate(['locale' => $locale], ['label' => 'Trang chủ']);

        $newsItem = MenuItem::query()->firstOrCreate(
            ['menu_id' => $menu->id, 'link_type' => 'taxonomy', 'routable_type' => 'taxonomy', 'routable_id' => $news->id],
            ['position' => 1],
        );
        $newsItem->translations()->firstOrCreate(['locale' => $locale], ['label' => 'Tin tức']);

        MenuAssignment::query()->firstOrCreate(
            ['region_key' => 'primary', 'locale' => $locale],
            ['menu_id' => $menu->id],
        );
    }

    /** The post category, looked up by its localized slug (taxonomies have no unique key). */
    private function firstOrCreateNewsCategory(string $locale): Taxonomy
    {
        $existing = TaxonomyTranslation::query()
            ->where('locale', $locale)
            ->where('slug', self::NEWS_SLUG)
            ->first();

        if ($existing !== null) {
            return Taxonomy::query()->findOrFail($existing->taxonomy_id);
        }

        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $this->paths->refreshBranch($category->id);
        $category->translations()->create([
            'locale' => $locale,
            'name' => 'Tin tức',
            'slug' => self::NEWS_SLUG,
        ]);

        return $category;
    }
}
