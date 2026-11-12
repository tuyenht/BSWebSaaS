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
 * websites of each type. The two counts grow with the platform, so a screen reads them once and
 * hands them back in (keyed by plan key and type key, never by id) instead of counting on every
 * round trip. Never opens a tenant context.
 */
final class PlanCatalog
{
    /**
     * The plans in the order customers see them in "Đổi gói", with what each grants and how many
     * customers are on it. `status`: ok = on sale · off = no longer sold · new = never sold yet.
     *
     * @param  array<string, int>|null  $customers  plan key → customers, as customerCounts() read it; null reads it now
     * @return list<array{id: int, key: string, name: string, sold: bool, status: 'ok'|'off'|'new', pos: int, customers: int, grants: array<string, bool|int>}>
     */
    public function plans(?array $customers = null): array
    {
        $plans = Plan::query()->select(['id', 'key', 'name', 'is_active', 'sort_order', 'meta'])->orderBy('sort_order')->orderBy('id')->get();
        $customers ??= $this->customerCounts();
        $grants = [];
        foreach (PlanFeature::query()->select(['plan_id', 'feature_key', 'value'])->whereIn('plan_id', $plans->modelKeys())->get() as $row) {
            $value = $row->getAttribute('value');
            if (is_bool($value) || is_int($value)) {
                $grants[Cast::int($row->getAttribute('plan_id'))][Cast::string($row->getAttribute('feature_key'))] = $value;
            }
        }

        $rows = [];
        foreach ($plans->values() as $i => $plan) {
            $count = $customers[$plan->key] ?? 0;
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

    /** @return array<string, bool|int> feature key → what one plan grants, as stored */
    public static function grantsOf(Plan $plan): array
    {
        return self::grantsFrom(PlanFeature::query()->where('plan_id', $plan->id)->pluck('value', 'feature_key')->all());
    }

    /**
     * A feature table handed back from the browser or the session: only switches and numbers.
     *
     * @param  array<array-key, mixed>  $table
     * @return array<string, bool|int>
     */
    public static function grantsFrom(array $table): array
    {
        $grants = [];
        foreach ($table as $key => $value) {
            if (is_bool($value) || is_int($value)) {
                $grants[(string) $key] = $value;
            }
        }

        return $grants;
    }

    /**
     * Whether two feature tables grant the same, whatever their order.
     *
     * @param  array<array-key, mixed>  $a
     * @param  array<array-key, mixed>  $b
     */
    public static function sameGrants(array $a, array $b): bool
    {
        $a = self::grantsFrom($a);
        $b = self::grantsFrom($b);
        ksort($a);
        ksort($b);

        return $a === $b;
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
     * @param  array<string, int>|null  $sites  type key → websites, as siteCounts() read it; null reads it now
     * @return list<array{key: string, labels: array<string, string>, sites: int, modules: list<string>, roles: list<string>}>
     */
    public function siteTypes(?array $sites = null): array
    {
        $types = SiteType::query()->select(['id', 'key', 'label'])->orderBy('id')->get();
        $sites ??= $this->siteCounts();
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
                'sites' => $sites[$type->key] ?? 0,
                'modules' => $modules,
                'roles' => array_map(fn (string $role): string => Cast::string(__('rbac.role.'.$role)), array_keys(PermissionRegistry::tenantRoleTemplates($type->key))),
            ];
        }

        return $out;
    }

    /** @return array<string, int> plan key → customers on it (removed ones aside) */
    public function customerCounts(): array
    {
        $out = [];
        $rows = Tenant::query()->join('plans', 'plans.id', '=', 'tenants.plan_id')
            ->selectRaw('plans.key as plan_key, count(*) as n')->groupBy('plans.key')->pluck('n', 'plan_key');
        foreach ($rows as $plan => $n) {
            $out[Cast::string($plan)] = Cast::int($n);
        }

        return $out;
    }

    /** @return array<string, int> website type key → websites of that type */
    public function siteCounts(): array
    {
        $out = [];
        $rows = Site::query()->join('site_types', 'site_types.id', '=', 'sites.site_type_id')
            ->selectRaw('site_types.key as type_key, count(*) as n')->groupBy('site_types.key')->pluck('n', 'type_key');
        foreach ($rows as $type => $n) {
            $out[Cast::string($type)] = Cast::int($n);
        }

        return $out;
    }
}
