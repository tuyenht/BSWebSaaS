<?php

namespace App\Modules\Tenancy\Entitlement;

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteModule;
use App\Modules\Tenancy\Registry\ModuleCatalog;

/**
 * Defense-in-depth layer #1 (design.md §15.2): what the current site is entitled to,
 * checked BEFORE RBAC + RLS. Stateless / fail-closed — CurrentSite is resolved fresh
 * each call (Octane-safe), and no context means no entitlement.
 */
final class Entitlement
{
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

        // One JOIN for the site_type key — not with('siteType') (which is 2 queries) — so the
        // whole resolution is a flat 2 queries (this + the override pluck) on the admin-nav hot
        // path, staying within the query budget without needing a cache.
        $typeKey = Site::query()
            ->whereKey($current->id())
            ->join('site_types', 'sites.site_type_id', '=', 'site_types.id')
            ->value('site_types.key');

        $fromType = is_string($typeKey) ? ModuleCatalog::modulesFor($typeKey) : [];

        // BelongsToSite auto-scopes the override query to the current site — it never
        // leaks another tenant's enabled modules.
        /** @var list<string> $fromOverride */
        $fromOverride = SiteModule::query()->where('enabled', true)->pluck('module_key')->all();

        return array_values(array_unique([...$fromType, ...$fromOverride]));
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
