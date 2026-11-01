<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Customers;

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\VietnameseFold;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
 * - group and plan counts are one GROUP BY each, under the same search.
 *
 * Search ignores diacritics and case (VietnameseFold) over the customer's name and its owner's
 * name and email; the owner side goes through the users trigram indexes.
 */
final class CustomerDirectory
{
    public const array STATUSES = ['active', 'suspended'];

    /** @var list<int> */
    public const array PER_PAGE = [10, 20, 50];

    public const int SEARCH_MAX = 100;

    /**
     * One page of customers, newest first.
     *
     * @param  list<string>  $plans  plan keys
     * @return array{rows: Collection<int, Tenant>, older: string|null, newer: string|null}
     */
    public function page(string $search, string $status, array $plans, int $per, string $after, string $before): array
    {
        $query = $this->filtered($search, $plans)->when($status !== '', fn (Builder $w) => $w->where('tenants.status', $status));

        $anchorUlid = $before !== '' ? $before : $after;
        $newer = $before !== '';
        $anchor = $anchorUlid === '' ? null : Tenant::query()->where('ulid', $anchorUlid)->first(['id', 'created_at']);
        if ($anchor === null) {
            $newer = false;
        }

        $direction = $newer ? 'asc' : 'desc';
        $rows = (clone $query)
            ->leftJoin('users', 'users.id', '=', 'tenants.owner_user_id')
            ->when($anchor !== null, fn (Builder $w) => $w->whereRaw(
                '(tenants.created_at, tenants.id) '.($newer ? '>' : '<').' (?, ?)',
                [$anchor?->getRawOriginal('created_at'), $anchor?->getKey()],
            ))
            ->select(['tenants.id', 'tenants.ulid', 'tenants.name', 'tenants.status', 'tenants.plan_id', 'tenants.created_at',
                'users.name as owner_name', 'users.email as owner_email'])
            ->withCount('sites')
            ->orderBy('tenants.created_at', $direction)->orderBy('tenants.id', $direction)
            ->limit($per + 1)
            ->get();

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
        ];
    }

    /**
     * How many customers each status group holds under the search and the plan filter.
     *
     * @param  list<string>  $plans
     * @return array{all: int, active: int, suspended: int}
     */
    public function groupCounts(string $search, array $plans): array
    {
        $counts = ['all' => 0, 'active' => 0, 'suspended' => 0];

        $byStatus = $this->filtered($search, $plans)->toBase()
            ->selectRaw('tenants.status, count(*) as n')->groupBy('tenants.status')->get();
        foreach ($byStatus as $row) {
            $status = is_string($row->status ?? null) ? $row->status : '';
            $n = is_numeric($row->n ?? null) ? (int) $row->n : 0;
            if (array_key_exists($status, $counts)) {
                $counts[$status] = $n;
            }
            $counts['all'] += $n;
        }

        return $counts;
    }

    /**
     * How many customers each plan holds under the search (the plan filter's own counts).
     *
     * @return array<int, int> plan id => customers
     */
    public function planCounts(string $search): array
    {
        $counts = [];
        $byPlan = $this->filtered($search, [])->toBase()->whereNotNull('tenants.plan_id')
            ->selectRaw('tenants.plan_id, count(*) as n')->groupBy('tenants.plan_id')->get();
        foreach ($byPlan as $row) {
            if (is_numeric($row->plan_id ?? null) && is_numeric($row->n ?? null)) {
                $counts[(int) $row->plan_id] = (int) $row->n;
            }
        }

        return $counts;
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
     * Customers matching the search and the chosen plans.
     *
     * @param  list<string>  $plans
     * @return Builder<Tenant>
     */
    private function filtered(string $search, array $plans): Builder
    {
        $query = Tenant::query();

        $needle = VietnameseFold::text(mb_substr(trim($search), 0, self::SEARCH_MAX));
        if ($needle !== '') {
            $like = VietnameseFold::like($needle);
            $owners = User::query()->select('id')
                ->where(fn (Builder $w) => $w->whereRaw(VietnameseFold::sql('name').' LIKE ?', [$like])->orWhereRaw('lower(email) LIKE ?', [$like]));
            $query->where(fn (Builder $w) => $w->whereRaw(VietnameseFold::sql('tenants.name').' LIKE ?', [$like])
                ->orWhereIn('tenants.owner_user_id', $owners));
        }

        if ($plans !== []) {
            $query->whereIn('tenants.plan_id', Plan::query()->select('id')->whereIn('key', $plans));
        }

        return $query;
    }
}
