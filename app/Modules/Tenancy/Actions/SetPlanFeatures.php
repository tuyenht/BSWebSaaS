<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Exceptions\FeatureChangeRejectedException;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\PlanFeature;
use App\Modules\Tenancy\Registry\FeatureRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Set what a plan grants, as ONE whole table (design.md §15.2): a feature in the table is granted
 * with that value, a feature left out is no longer granted and falls back to its default. The
 * single write path to `plan_features`.
 *
 * Every entry is checked before anything is written — the feature exists, a switch takes
 * true/false, a limit a whole number of 0 or more — so a refused table changes nothing. A plan may
 * grant customer-wide features (how many websites) as well as per-website ones.
 *
 * A lowered limit or a feature switched off only changes what a customer may do NEXT; what their
 * websites already have stays.
 */
final readonly class SetPlanFeatures
{
    /**
     * @param  array<string, bool|int>  $features  feature key → value
     *
     * @throws FeatureChangeRejectedException unknown_feature · wrong_kind
     */
    public function handle(Plan $plan, array $features): void
    {
        foreach ($features as $key => $value) {
            $refusal = FeatureRegistry::refusal($key, $value);

            if ($refusal !== null) {
                throw new FeatureChangeRejectedException($refusal);
            }
        }

        DB::transaction(function () use ($plan, $features): void {
            PlanFeature::query()->where('plan_id', $plan->id)->whereNotIn('feature_key', array_keys($features))->delete();

            foreach ($features as $key => $value) {
                PlanFeature::query()->updateOrInsert(
                    ['plan_id' => $plan->id, 'feature_key' => $key],
                    ['value' => json_encode($value, JSON_THROW_ON_ERROR)],
                );
            }
        });
    }
}
