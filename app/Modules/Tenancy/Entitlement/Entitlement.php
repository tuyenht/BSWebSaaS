<?php

namespace App\Modules\Tenancy\Entitlement;

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Models\PlanFeature;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Tenancy\Models\SiteModule;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Registry\FeatureRegistry;
use App\Modules\Tenancy\Registry\ModuleCatalog;

/**
 * Defense-in-depth layer #1 (design.md §15.2): what the current site is entitled to,
 * checked BEFORE RBAC + RLS. Fail-closed — CurrentSite is resolved fresh each call, and no
 * context means no entitlement. A SCOPED binding: the module keys are remembered per site for one
 * request/job (the admin nav and the page ask the same question) and flushed with it under Octane.
 */
final class Entitlement
{
    /** @var array<int, list<string>> entitledModuleKeys() per site id, for this request only */
    private array $moduleKeys = [];

    public function moduleEnabled(string $moduleKey): bool
    {
        // SiteModule is BelongsToSite — the query is auto-scoped to the current site
        // (and fail-closed to zero rows when there is no site context).
        return SiteModule::query()
            ->where('module_key', $moduleKey)
            ->where('enabled', true)
            ->exists();
    }

    /**
     * The module keys the current site may surface in the admin nav: the keys its
     * site_type grants (design.md §15.2) ∪ any enabled per-site site_modules override.
     * Fail-closed: no resolved site → no entitlement.
     *
     * @return list<string>
     */
    public function entitledModuleKeys(): array
    {
        $current = app(CurrentSite::class);

        if (! $current->hasId()) {
            return [];
        }

        return $this->moduleKeys[$current->id()] ??= $this->resolveModuleKeys($current->id());
    }

    /** @return list<string> */
    private function resolveModuleKeys(int $siteId): array
    {
        // One JOIN for the site_type key — not with('siteType') (which is 2 queries) — so the
        // whole resolution is a flat 2 queries (this + the override pluck) on the admin-nav hot
        // path, staying within the query budget without needing a cache.
        $typeKey = Site::query()
            ->whereKey($siteId)
            ->join('site_types', 'sites.site_type_id', '=', 'site_types.id')
            ->value('site_types.key');

        $fromType = is_string($typeKey) ? ModuleCatalog::modulesFor($typeKey) : [];

        // BelongsToSite auto-scopes the override query to the current site — it never
        // leaks another tenant's enabled modules.
        /** @var list<string> $fromOverride */
        $fromOverride = SiteModule::query()->where('enabled', true)->pluck('module_key')->all();

        return array_values(array_unique([...$fromType, ...$fromOverride]));
    }

    /**
     * Is a boolean feature granted to the current site? Resolves a per-site override first, then
     * the tenant's plan grant, then the registry default (fail-closed: unknown/ungranted → false).
     */
    public function featureEnabled(string $key): bool
    {
        return (bool) $this->featureValue($key);
    }

    /**
     * The resolved value of a feature for the current site — a boolean grant or a numeric limit,
     * coerced to the kind the FeatureRegistry declares. Resolution order:
     *   per-site override (site_features, RLS-scoped) → tenant's plan grant (plan_features)
     *   → registry default. No site context → the registry default (fail-closed baseline).
     */
    public function featureValue(string $key): bool|int
    {
        $current = app(CurrentSite::class);

        if (! $current->hasId()) {
            return FeatureRegistry::default($key);
        }

        // A per-site override wins over the plan (BelongsToSite scopes it to the current site) —
        // for a feature one website can hold. A customer-wide one is the plan's alone, whatever a
        // stray override row says.
        if ((FeatureRegistry::all()[$key]['scope'] ?? 'site') === 'site') {
            $override = SiteFeature::query()->where('feature_key', $key)->first();
            if ($override !== null) {
                return $this->coerce($override->value, $key);
            }
        }

        // Else the tenant's plan grant. sites/tenants are global (D6) so this join is context-free.
        $planId = Site::query()
            ->whereKey($current->id())
            ->join('tenants', 'sites.tenant_id', '=', 'tenants.id')
            ->value('tenants.plan_id');

        return $this->planValue($planId, $key);
    }

    /**
     * The value a customer's plan gives a feature, with no website involved — for a customer-wide
     * limit checked before the customer's next website exists (`sites.max`). Resolution order:
     * the tenant's plan grant (plan_features) → registry default. tenants/plans are global (D6),
     * so this needs no site context; plan_id may be null (no plan) → the default.
     */
    public function tenantFeatureValue(int $tenantId, string $key): bool|int
    {
        return $this->planValue(Tenant::query()->whereKey($tenantId)->value('plan_id'), $key);
    }

    /** What plan $planId grants for $key, or the registry default when no plan or no grant. */
    private function planValue(mixed $planId, string $key): bool|int
    {
        if ($planId !== null) {
            $grant = PlanFeature::query()->where('plan_id', $planId)->where('feature_key', $key)->first();
            if ($grant !== null) {
                return $this->coerce($grant->value, $key);
            }
        }

        return FeatureRegistry::default($key);
    }

    /** Coerce a stored jsonb value to the feature's declared kind (boolean grant vs numeric limit). */
    private function coerce(mixed $value, string $key): bool|int
    {
        $kind = FeatureRegistry::all()[$key]['kind'] ?? 'boolean';

        if ($kind === 'limit') {
            return is_numeric($value) ? (int) $value : 0;
        }

        return (bool) $value;
    }

    public function siteIsActive(): bool
    {
        $current = app(CurrentSite::class);

        if (! $current->hasId()) {
            return false;
        }

        return Site::query()
            ->whereKey($current->id())
            ->where('status', 'active')
            ->exists();
    }
}
