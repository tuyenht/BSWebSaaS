<?php

namespace App\Modules\Provisioning\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
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
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly PermissionRegistrar $registrar,
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

            foreach (PermissionRegistry::tenantRoleTemplates() as $name => $spec) {
                $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
                $role->forceFill(['level' => $spec['level'], 'is_system' => true])->save();

                $permissions = $spec['permissions'] === '*'
                    ? PermissionRegistry::TENANT_PERMISSIONS
                    : $spec['permissions'];
                $role->syncPermissions($permissions);
            }

            $owner->assignRole('owner');
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
}
