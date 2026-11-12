<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Plans;

use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\PlanFeature;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteType;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Registry\FeatureRegistry;
use App\Modules\Tenancy\Registry\ModuleCatalog;

/**
 * What the plan screens (design 30) read. Every table here is platform-wide (D6, no RLS) and small
 * (a few dozen plans, a handful of website types), so each read is one query whatever the number
 * of plans: the plans, all their grants, the customers on each plan (one GROUP BY over `tenants`,
 * the CustomerDirectory precedent: about 9 ms at 50,000 customers), the website types and the
 * websites of each type. Never opens a tenant context.
 */
final class PlanCatalog
{
    /**
     * The plans in the order customers see them in "Đổi gói", with what each grants and how many
     * customers are on it. `status`: ok = on sale · off = no longer sold · new = never sold yet.
     *
     * @return list<array{id: int, key: string, name: string, sold: bool, status: 'ok'|'off'|'new', pos: int, customers: int, grants: array<string, bool|int>}>
     */
    public function plans(): array
    {
        $plans = Plan::query()->select(['id', 'key', 'name', 'is_active', 'sort_order', 'meta'])->orderBy('sort_order')->orderBy('id')->get();
        $customers = $this->customers();
        $grants = [];
        foreach (PlanFeature::query()->select(['plan_id', 'feature_key', 'value'])->whereIn('plan_id', $plans->modelKeys())->get() as $row) {
            $value = $row->getAttribute('value');
            if (is_bool($value) || is_int($value)) {
                $grants[Cast::int($row->getAttribute('plan_id'))][Cast::string($row->getAttribute('feature_key'))] = $value;
            }
        }

        $rows = [];
        foreach ($plans->values() as $i => $plan) {
            $count = $customers[$plan->id] ?? 0;
            $soldOnce = (Cast::arr($plan->meta)['sold_once'] ?? false) === true;
            $rows[] = [
                'id' => $plan->id,
                'key' => $plan->key,
                'name' => $plan->name,
                'sold' => $plan->is_active,
                // a plan with customers was given out, so it is "no longer sold", never "not sold yet"
                'status' => $plan->is_active ? 'ok' : ($soldOnce || $count > 0 ? 'off' : 'new'),
                'pos' => $i + 1,
                'customers' => $count,
                'grants' => $grants[$plan->id] ?? [],
            ];
        }

        return $rows;
    }

    /** How many customers (removed ones aside) are on one plan. */
    public function customersOn(Plan $plan): int
    {
        return Tenant::query()->where('plan_id', $plan->id)->count();
    }

    /**
     * What a plan grants once the defaults fill the features it leaves out.
     *
     * @param  array<string, bool|int>  $grants
     * @return array<string, bool|int>
     */
    public static function effective(array $grants): array
    {
        $out = [];
        foreach (FeatureRegistry::all() as $key => $feature) {
            $out[$key] = $grants[$key] ?? $feature['default'];
        }

        return $out;
    }

    /**
     * Whether the plans on sale give customers something to choose between: at least two, and not
     * all granting the same. Behind the warning under "Lời mời nâng gói".
     *
     * @param  list<array{sold: bool, grants: array<string, bool|int>}>  $plans
     */
    public static function differ(array $plans): bool
    {
        $sets = [];
        $sold = 0;
        foreach ($plans as $plan) {
            if ($plan['sold']) {
                $sold++;
                $sets[json_encode(self::effective($plan['grants']), JSON_THROW_ON_ERROR)] = true;
            }
        }

        return $sold >= 2 && count($sets) > 1;
    }

    /**
     * The website types (read only): their name in each language, how many websites use each, the
     * modules a new website of the type gets and its role templates.
     *
     * @return list<array{key: string, labels: array<string, string>, sites: int, modules: list<string>, roles: list<string>}>
     */
    public function siteTypes(): array
    {
        $types = SiteType::query()->select(['id', 'key', 'label'])->orderBy('id')->get();
        /** @var array<int, int> $sites */
        $sites = Site::query()->whereNotNull('site_type_id')->selectRaw('site_type_id, count(*) as n')->groupBy('site_type_id')
            ->pluck('n', 'site_type_id')->map(fn (mixed $n): int => Cast::int($n))->all();
        $catalog = array_column(ModuleCatalog::all(), 'label', 'key');

        $out = [];
        foreach ($types as $type) {
            $modules = [];
            foreach (ModuleCatalog::modulesFor($type->key) as $module) {
                $label = Cast::arr($catalog[$module] ?? []);
                $modules[] = Cast::string($label[app()->getLocale()] ?? $label['vi'] ?? $module);
            }
            $out[] = [
                'key' => $type->key,
                'labels' => array_filter(Cast::arr($type->label), 'is_string'),
                'sites' => $sites[$type->id] ?? 0,
                'modules' => $modules,
                'roles' => array_map(fn (string $role): string => Cast::string(__('rbac.role.'.$role)), array_keys(PermissionRegistry::tenantRoleTemplates($type->key))),
            ];
        }

        return $out;
    }

    /** @return array<int, int> plan id → customers on it */
    private function customers(): array
    {
        $out = [];
        foreach (Tenant::query()->whereNotNull('plan_id')->selectRaw('plan_id, count(*) as n')->groupBy('plan_id')->pluck('n', 'plan_id') as $plan => $n) {
            $out[Cast::int($plan)] = Cast::int($n);
        }

        return $out;
    }
}
