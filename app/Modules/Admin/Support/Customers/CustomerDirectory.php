<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Customers;

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\VietnameseFold;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Reads the platform's customer list (design 03 "Khách hàng"). `tenants`, `users`, `sites` and
 * `plans` are platform-wide tables (D6, no RLS): the platform console reads across every customer
 * by design, behind its own permission, and never enters a tenant context.
 *
 * Built for 10,000+ customers:
 * - pages move by the last row seen (keyset on created_at, id — newest first), never by counting
 *   or skipping rows; the URL carries that row's ulid, never its id;
 * - the owner's name and email come in the page's own query (a join on tenants.owner_user_id),
 *   and the live-site count as a sub-select per row of the page — no query per row;
 * - group and plan counts come from one GROUP BY over the same matches, in the page's statement.
 *
 * Search ignores diacritics and case (VietnameseFold) over the customer's name and its owner's
 * name and email, through the trigram indexes on those folded expressions (tenants:
 * 2044_01_01_000000; users: 2039_01_01_000100).
 */
final class CustomerDirectory
{
    public const array STATUSES = ['active', 'suspended'];

    /** @var list<int> */
    public const array PER_PAGE = [10, 20, 50];

    public const int SEARCH_MAX = 100;

    /**
     * One page of customers, newest first, with the list's counts under the same search.
     *
     * One statement: the customers matching the search are found once (a MATERIALIZED CTE), then
     * both counted and paged from there. A wide search spends most of its time finding them (the
     * trigram index checks every matched row again with the fold), so a second statement for the
     * counts would double it.
     *
     * @param  list<string>  $plans  plan keys
     * @return array{rows: Collection<int, Tenant>, older: string|null, newer: string|null, counts: array{groups: array{all: int, active: int, suspended: int}, plans: array<int, int>}}
     */
    public function page(string $search, string $status, array $plans, int $per, string $after, string $before): array
    {
        $anchorUlid = $before !== '' ? $before : $after;
        $newer = $before !== '';
        $anchor = $anchorUlid === '' ? null : Tenant::query()->where('ulid', $anchorUlid)->first(['id', 'created_at']);
        if ($anchor === null) {
            $newer = false;
        }

        $matches = $this->filtered($search)->toBase()
            ->select(['tenants.id', 'tenants.status', 'tenants.plan_id', 'tenants.created_at']);

        $direction = $newer ? 'asc' : 'desc';
        $page = Tenant::query()
            ->whereIn('tenants.id', fn (QueryBuilder $q) => $q->from('customer_matches')->select('id'))
            ->when($status !== '', fn (Builder $w) => $w->where('tenants.status', $status))
            ->when($plans !== [], fn (Builder $w) => $w->whereIn('tenants.plan_id', Plan::query()->select('id')->whereIn('key', $plans)))
            ->leftJoin('users', 'users.id', '=', 'tenants.owner_user_id')
            ->when($anchor !== null, fn (Builder $w) => $w->whereRaw(
                '(tenants.created_at, tenants.id) '.($newer ? '>' : '<').' (?, ?)',
                [$anchor?->getRawOriginal('created_at'), $anchor?->getKey()],
            ))
            ->select(['tenants.id', 'tenants.ulid', 'tenants.name', 'tenants.status', 'tenants.plan_id', 'tenants.created_at',
                'users.name as owner_name', 'users.email as owner_email'])
            ->withCount('sites')
            ->orderBy('tenants.created_at', $direction)->orderBy('tenants.id', $direction)
            ->limit($per + 1);

        // the counts row is always there; the page joins it, and may be empty
        $sql = 'with customer_matches as materialized ('.$matches->toSql().') '
            .'select c.match_counts, p.* from (select json_agg(g) as match_counts from '
            .'(select status, plan_id, count(*) as n from customer_matches group by status, plan_id) as g) as c '
            .'left join lateral ('.$page->toSql().') as p on true '
            ."order by p.created_at {$direction}, p.id {$direction}";
        $bindings = [...$matches->getBindings(), ...$page->getBindings()];

        $read = Tenant::fromQuery($sql, $bindings);
        $first = $read->first();
        $tally = is_string($first?->getAttribute('match_counts')) ? json_decode($first->getAttribute('match_counts'), true) : [];
        $rows = $read->filter(fn (Tenant $t): bool => $t->getKey() !== null)
            ->each(fn (Tenant $t) => $t->offsetUnset('match_counts'));

        if ($anchor !== null && $rows->isEmpty()) {
            // a page link gone stale (nothing older or newer any more): the first page
            return $this->page($search, $status, $plans, $per, '', '');
        }

        $more = $rows->count() > $per;
        $rows = $rows->take($per);
        if ($newer) {
            $rows = $rows->reverse();
        }
        $rows = $rows->values();

        $hasOlder = $newer ? $rows->isNotEmpty() : $more;
        $hasNewer = $newer ? $more : $anchor !== null;

        return [
            'rows' => $rows,
            'older' => $hasOlder && $rows->isNotEmpty() ? $rows->last()->ulid : null,
            'newer' => $hasNewer && $rows->isNotEmpty() ? $rows->first()->ulid : null,
            'counts' => $this->tally(is_array($tally) ? $tally : [], $plans),
        ];
    }

    /**
     * The list's counts under the search alone (no page): the status groups with neither the
     * search nor the plans give the "N trong M" totals.
     *
     * @param  list<string>  $plans  plan keys
     * @return array{groups: array{all: int, active: int, suspended: int}, plans: array<int, int>}
     */
    public function counts(string $search, array $plans): array
    {
        $rows = $this->filtered($search)->toBase()
            ->selectRaw('tenants.status, tenants.plan_id, count(*) as n')->groupBy('tenants.status', 'tenants.plan_id')->get();

        return $this->tally($rows->map(fn (object $row): array => (array) $row)->all(), $plans);
    }

    /**
     * Sums the (status, plan) groups: how many customers each status group holds within the
     * chosen plans (all plans when none is chosen), and how many each plan holds whatever the plan
     * filter (the plan filter's own counts). The groups are few (statuses x plans).
     *
     * @param  array<array-key, mixed>  $rows  {status, plan_id, n} each
     * @param  list<string>  $plans  plan keys
     * @return array{groups: array{all: int, active: int, suspended: int}, plans: array<int, int>}
     */
    private function tally(array $rows, array $plans): array
    {
        $groups = ['all' => 0, 'active' => 0, 'suspended' => 0];
        $byPlan = [];
        $chosen = $plans === [] ? null
            : array_map(fn (mixed $id): int => Cast::int($id), Plan::query()->whereIn('key', $plans)->pluck('id')->all());

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $status = is_string($row['status'] ?? null) ? $row['status'] : '';
            $plan = is_numeric($row['plan_id'] ?? null) ? (int) $row['plan_id'] : null;
            $n = is_numeric($row['n'] ?? null) ? (int) $row['n'] : 0;
            if ($plan !== null) {
                $byPlan[$plan] = ($byPlan[$plan] ?? 0) + $n;
            }
            if ($chosen !== null && ($plan === null || ! in_array($plan, $chosen, true))) {
                continue;
            }
            if (array_key_exists($status, $groups)) {
                $groups[$status] += $n;
            }
            $groups['all'] += $n;
        }

        return ['groups' => $groups, 'plans' => $byPlan];
    }

    /**
     * Every plan, in the catalog's order: the sold ones and those no longer sold (still held by
     * some customers).
     *
     * @return Collection<int, Plan>
     */
    public function plans(): Collection
    {
        return Plan::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'key', 'name', 'is_active']);
    }

    /**
     * Customers matching the search (every customer when it is empty).
     *
     * @return Builder<Tenant>
     */
    private function filtered(string $search): Builder
    {
        $query = Tenant::query();

        $needle = VietnameseFold::text(mb_substr(trim($search), 0, self::SEARCH_MAX));
        if ($needle !== '') {
            $like = VietnameseFold::like($needle);
            // Each condition is its own lookup, joined by UNION, so each is served by its own index
            // (the customer's name, the owner's name, the owner's email, then the owner's customers).
            // Written as one OR across tenants and users, no index serves it, and the name of every
            // customer is folded on each search (about 10 µs a row with this letter table).
            $owners = User::query()->select('id')->whereRaw(VietnameseFold::sql('name').' LIKE ?', [$like])
                ->union(User::query()->select('id')->whereRaw('lower(email) LIKE ?', [$like]));
            $matching = Tenant::query()->select('id')->whereRaw(VietnameseFold::sql('name').' LIKE ?', [$like])
                ->union(Tenant::query()->select('id')->whereIn('owner_user_id', $owners));
            $query->whereIn('tenants.id', $matching);
        }

        return $query;
    }
}
