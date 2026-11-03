<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Exceptions\FeatureChangeRejectedException;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Tenancy\Models\TenantHistory;
use App\Modules\Tenancy\Registry\FeatureRegistry;

/**
 * Give the current website its own value for one feature, over whatever its customer's plan grants
 * (design.md §15.2) — or, with null, take that value away so the plan decides again. The single
 * write path to `site_features`, so the rules hold whoever calls it:
 *
 *  - the feature exists in the FeatureRegistry;
 *  - a switch takes true/false, a limit takes a whole number of 0 or more;
 *  - the feature is one a single website can hold: a customer-wide one (how many websites they may
 *    have) is set by the plan only.
 *
 * An override only changes what the website may do NEXT: whatever it already has above a lowered
 * limit stays (the code that owns each feature checks it when something is added).
 *
 * A real change leaves a `feature_overridden` row in the website's customer's history
 * (tenant_history) in the same transaction — the tenant context's — naming the website as it is now
 * (ulid and name), the feature and the new value (null: cleared). Setting the value the website
 * already holds, or clearing what it does not hold, changes nothing and records nothing.
 *
 * Who may grant features is the caller's check. Runs inside the tenant context.
 */
final readonly class OverrideSiteFeature
{
    /**
     * @param  int|null  $actorUserId  who made the change, for the customer's history; null from the console
     * @return bool|int|null the override this replaced or removed; null when there was none
     *
     * @throws FeatureChangeRejectedException unknown_feature · wrong_kind · customer_wide
     */
    public function handle(string $key, bool|int|null $value, ?int $actorUserId = null): bool|int|null
    {
        $feature = FeatureRegistry::all()[$key] ?? null;

        if ($feature === null) {
            throw new FeatureChangeRejectedException('unknown_feature');
        }
        if ($feature['scope'] !== 'site') {
            throw new FeatureChangeRejectedException('customer_wide');
        }

        if ($value !== null && FeatureRegistry::refusal($key, $value) !== null) {
            throw new FeatureChangeRejectedException('wrong_kind');
        }

        // BelongsToSite scopes both the read and the write to the current website.
        $row = SiteFeature::query()->firstWhere('feature_key', $key);
        $stored = $row?->getAttribute('value');
        $was = is_bool($stored) || is_int($stored) ? $stored : null;

        // a clear is a no-op only when there is no row at all: a stored value of an unexpected
        // shape still goes when cleared
        if ($value === null ? $row === null : $was === $value) {
            return $was;
        }

        if ($value === null) {
            $row->delete();
        } else {
            SiteFeature::query()->updateOrCreate(['feature_key' => $key], ['value' => $value]);
        }

        $this->recordHistory($key, $value, $actorUserId);

        return $was;
    }

    private function recordHistory(string $key, bool|int|null $value, ?int $actorUserId): void
    {
        // sites is platform-wide (D6): read as is, the current website's own row.
        $site = Site::query()->withTrashed()->findOrFail(app(CurrentSite::class)->id(), ['id', 'tenant_id', 'ulid', 'name']);

        TenantHistory::query()->create([
            'tenant_id' => $site->tenant_id,
            'kind' => TenantHistory::KIND_FEATURE_OVERRIDDEN,
            'actor_user_id' => $actorUserId,
            'meta' => ['site' => $site->ulid, 'site_name' => $site->name, 'feature' => $key, 'value' => $value],
        ]);
    }
}
