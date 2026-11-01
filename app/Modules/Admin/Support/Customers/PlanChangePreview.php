<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Customers;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Domains\DomainName;
use App\Modules\Tenancy\Models\PlanFeature;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Registry\FeatureRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * What moving a customer to another plan changes (design 03, "Đổi gói"), read-only: the feature
 * values of two plans side by side, and where the customer already uses more than the new plan
 * gives. Nothing here writes; the change itself is PlatformPlans::changeCustomerPlan.
 *
 * A plan's value for a feature is its grant, or the registry default when it grants nothing (what
 * Entitlement resolves for a website with no override of its own).
 *
 * Usage is counted where the database already knows it:
 * - websites: the customer's live websites against `sites.max`;
 * - per live website, own domains (a name and its www. spelling count once, as AttachDomain does)
 *   against `custom_domains.max`, and switched-on languages against `locales.max`.
 * A website with its own value for a feature keeps it, so it is never over the plan's. Languages
 * and own values live in tenant tables (RLS): each website is read inside its own tenant context.
 */
final readonly class PlanChangePreview
{
    public function __construct(private Tenancy $tenancy) {}

    /**
     * Every feature's value under each plan.
     *
     * @param  list<int|null>  $planIds  null = no plan (every feature at its default)
     * @return array<int|string, array<string, bool|int>> keyed by plan id ('' for no plan)
     */
    public function values(array $planIds): array
    {
        $grants = PlanFeature::query()->whereIn('plan_id', array_values(array_filter($planIds, fn (?int $id): bool => $id !== null)))
            ->get(['plan_id', 'feature_key', 'value'])->groupBy('plan_id');

        $out = [];
        foreach ($planIds as $planId) {
            $own = $planId === null ? [] : Cast::arr($grants->get($planId)?->pluck('value', 'feature_key')->all());
            $values = [];
            foreach (FeatureRegistry::all() as $key => $feature) {
                $values[$key] = array_key_exists($key, $own) ? self::coerce($own[$key], $feature['kind']) : $feature['default'];
            }
            $out[$planId ?? ''] = $values;
        }

        return $out;
    }

    /**
     * Each feature with its value under $from and $to and how it changes.
     *
     * @param  array<string, bool|int>  $from
     * @param  array<string, bool|int>  $to
     * @return list<array{key: string, name: string, from: string, to: string, change: 'same'|'add'|'lose'|'up'|'down'}>
     */
    public function diff(array $from, array $to): array
    {
        $rows = [];
        foreach (FeatureRegistry::all() as $key => $feature) {
            $a = $from[$key] ?? $feature['default'];
            $b = $to[$key] ?? $feature['default'];
            $change = match (true) {
                $a === $b => 'same',
                $feature['kind'] === 'boolean' => $b ? 'add' : 'lose',
                default => (int) $b > (int) $a ? 'up' : 'down',
            };
            $rows[] = ['key' => $key, 'name' => $feature['name'], 'from' => self::format($key, $a), 'to' => self::format($key, $b), 'change' => $change];
        }

        return $rows;
    }

    /**
     * What each live website already uses, for overages(): read once per dialog (the caller keeps
     * it), since it does not depend on the plan chosen.
     *
     * Own domains for every website come in one query (`domains` is platform-wide). Languages and
     * own feature values live in tenant tables (RLS) and can only be read inside each website's own
     * tenant context, so each website costs one statement there (both reads as sub-selects), plus
     * the context's own setup: a few queries per website, the customer's handful of websites.
     *
     * @param  Collection<int, Site>  $liveSites  the customer's live websites (id, name)
     * @return list<array{site: string, own: list<string>, domains: int, locales: int}>
     */
    public function usage(Collection $liveSites): array
    {
        if ($liveSites->isEmpty()) {
            return [];
        }

        // the websites' own domains, through the site relation (a hostname is resolved only by SiteResolver)
        $domains = Site::query()->whereIn('id', $liveSites->pluck('id')->all())->with('domains:id,site_id,hostname,kind')->get(['id'])
            ->mapWithKeys(fn (Site $s): array => [$s->id => $s->domains->where('kind', 'custom')->pluck('hostname')->all()]);

        $out = [];
        foreach ($liveSites as $site) {
            $row = $this->tenancy->enter($site->id, fn (): ?object => DB::query()
                ->selectSub(SiteFeature::query()->selectRaw("coalesce(json_agg(feature_key), '[]'::json)"), 'own')
                ->selectSub(SiteLocale::query()->where('enabled', true)->selectRaw('count(*)'), 'locales')
                ->first());
            $own = json_decode(Cast::string($row->own ?? null, '[]'), true);

            $out[] = [
                'site' => $site->name,
                'own' => Cast::strings(is_array($own) ? $own : []),
                // a name and its www. spelling count once, as AttachDomain does
                'domains' => count(array_unique(array_map(
                    fn (mixed $host): string => DomainName::pairKey(Cast::string($host)),
                    Cast::arr($domains->get($site->id)),
                ))),
                'locales' => Cast::int($row->locales ?? null),
            ];
        }

        return $out;
    }

    /**
     * Where the customer already uses more than $to gives, one plain sentence each. No query: the
     * websites' usage comes from usage().
     *
     * @param  int  $sites  how many live websites the customer has
     * @param  list<array{site: string, own: list<string>, domains: int, locales: int}>  $usage
     * @param  array<string, bool|int>  $to
     * @return list<string>
     */
    public function overages(int $sites, array $usage, array $to): array
    {
        $out = [];
        $sitesMax = (int) ($to['sites.max'] ?? 0);
        if ($sites > $sitesMax) {
            $out[] = __('admin.customers_dlg_over_sites', ['n' => self::num($sites), 'max' => self::num($sitesMax)]);
        }

        foreach ($usage as $site) {
            $used = ['custom_domains.max' => [$site['domains'], 'admin.customers_usage_domains'], 'locales.max' => [$site['locales'], 'admin.customers_usage_locales']];
            foreach ($used as $key => [$n, $label]) {
                $limit = (int) ($to[$key] ?? 0);
                if (! in_array($key, $site['own'], true) && $n > $limit) {
                    $out[] = __('admin.customers_dlg_over_limit', [
                        'site' => $site['site'], 'used' => __($label, ['n' => self::num($n)]), 'max' => self::format($key, $limit),
                    ]);
                }
            }
        }

        return $out;
    }

    /** A feature value as the screen writes it: "Có" / "Không", a number, megabytes. */
    public static function format(string $key, bool|int $value): string
    {
        $kind = FeatureRegistry::all()[$key]['kind'] ?? 'boolean';
        if ($kind === 'boolean') {
            return $value ? __('admin.customers_value_yes') : __('admin.customers_value_no');
        }

        return $key === 'media.storage_mb' ? __('admin.customers_value_mb', ['n' => self::num((int) $value)]) : self::num((int) $value);
    }

    private static function num(int $n): string
    {
        return (string) Number::format($n, locale: app()->getLocale());
    }

    private static function coerce(mixed $value, string $kind): bool|int
    {
        return $kind === 'limit' ? (is_numeric($value) ? (int) $value : 0) : (bool) $value;
    }
}
