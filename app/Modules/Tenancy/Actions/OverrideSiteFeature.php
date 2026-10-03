<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Exceptions\FeatureChangeRejectedException;
use App\Modules\Tenancy\Models\SiteFeature;
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
 * Who may grant features is the caller's check. Runs inside the tenant context.
 */
final readonly class OverrideSiteFeature
{
    /**
     * @return bool|int|null the override this replaced or removed; null when there was none
     *
     * @throws FeatureChangeRejectedException unknown_feature · wrong_kind · customer_wide
     */
    public function handle(string $key, bool|int|null $value): bool|int|null
    {
        $feature = FeatureRegistry::all()[$key] ?? null;

        if ($feature === null) {
            throw new FeatureChangeRejectedException('unknown_feature');
        }
        if ($feature['scope'] !== 'site') {
            throw new FeatureChangeRejectedException('customer_wide');
        }

        $ofKind = $feature['kind'] === 'limit' ? is_int($value) && $value >= 0 : is_bool($value);
        if ($value !== null && ! $ofKind) {
            throw new FeatureChangeRejectedException('wrong_kind');
        }

        // BelongsToSite scopes both the read and the write to the current website.
        $row = SiteFeature::query()->firstWhere('feature_key', $key);
        $stored = $row?->getAttribute('value');
        $was = is_bool($stored) || is_int($stored) ? $stored : null;

        if ($value === null) {
            $row?->delete();

            return $was;
        }

        SiteFeature::query()->updateOrCreate(['feature_key' => $key], ['value' => $value]);

        return $was;
    }
}
