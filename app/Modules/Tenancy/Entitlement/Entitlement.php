<?php

namespace App\Modules\Tenancy\Entitlement;

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteModule;

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
