<?php

namespace App\Modules\Provisioning\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteModule;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Genesis: provision a site end-to-end and leave it active. Idempotent (anchored on
 * the unique primary domain), so it is safe for An Vinh and for site #2..#10000.
 *
 * Order respects the defense layers: global identity/site rows first, then per-site
 * (RLS) config + RBAC inside the tenant context, then flip the site to 'active'.
 */
final class ProvisionSite
{
    /** Localized slug of the default post category seeded for every site. */
    private const NEWS_SLUG = 'tin-tuc';

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly PermissionRegistrar $registrar,
        private readonly RouteSync $routes,
    ) {}

    public function handle(ProvisionSiteData $data): Site
    {
        // 1) Global rows (no RLS): tenant, site, primary domain, owner, membership.
        $tenant = $data->tenantId !== null
            ? Tenant::query()->findOrFail($data->tenantId)
            : Tenant::query()->firstOrCreate(['name' => $data->tenantName]);

        $domain = Domain::query()->firstWhere('hostname', $data->hostname);

        if ($domain === null) {
            $site = Site::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $data->siteName,
                'status' => 'provisioning',
                'default_locale' => $data->defaultLocale,
            ]);
            Domain::query()->create([
                'site_id' => $site->id,
                'hostname' => $data->hostname,
                'kind' => 'subdomain',
                'is_primary' => true,
            ]);
        } else {
            $site = $domain->site ?? throw new \RuntimeException("Domain {$data->hostname} has no site.");
        }

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
        $this->tenancy->enter($site->id, function () use ($data, $owner): void {
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

            foreach ($data->modules as $module) {
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
            $this->seedNews($data->defaultLocale);

            foreach (PermissionRegistry::tenantRoleTemplates() as $name => $spec) {
                $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
                $role->forceFill(['level' => $spec['level'], 'is_system' => true])->save();

                $permissions = $spec['permissions'] === '*'
                    ? PermissionRegistry::TENANT_PERMISSIONS
                    : $spec['permissions'];
                $role->syncPermissions($permissions);
            }

            $owner->assignRole('owner');

            $this->seedHomepage($data, $owner);
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
     * Seed the default "Tin tức" (news) post collection: a post_cat taxonomy + the admin
     * nav section bound to it (entry_type=post). Idempotent — the section is anchored on
     * the unique (site_id, key); the taxonomy (which has no natural unique key) on its
     * localized slug. Runs inside the tenant context so site_id is stamped + RLS-scoped.
     */
    private function seedNews(string $locale): void
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
        if ($translation !== null) {
            try {
                $this->routes->syncTaxonomy($category, $locale, $translation->slug);
            } catch (RouteConflictException) {
                // A page already owns this path; leave it.
            }
        }
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
        $category->translations()->create([
            'locale' => $locale,
            'name' => 'Tin tức',
            'slug' => self::NEWS_SLUG,
        ]);

        return $category;
    }
}
