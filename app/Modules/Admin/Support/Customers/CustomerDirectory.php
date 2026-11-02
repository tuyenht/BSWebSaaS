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
 * 2044_01_01_000000; users: 2039_01_01_000100), anywhere in the text. A trigram index needs three
 * letters or digits in a row, so a shorter search ("an", "hà", "a b") looks for a customer or
 * owner name with a word starting with each part instead ("an" finds "Cơ khí An Vinh", not
 * "Thanh"; emails are not words), through the word indexes on the same folded names
 * (2044_01_01_000000): a trigram index can find word starts too, but checks every row it found
 * again with the fold (about 300 ms for 20,000 matches). The customer's code ("KH-000123", "000123" or "123"; prefix and
 * padding from config/customers.php) is matched exactly on customer_no, at any length. Text with
 * no letter or digit at all is no search: the list stays as it is and says so.
 */
final class CustomerDirectory
{
    public const array STATUSES = ['active', 'suspended'];

    /** @var list<int> */
    public const array PER_PAGE = [10, 20, 50];

    public const int SEARCH_MAX = 100;

    /** What a page row needs from `tenants`, and what the matches of a search carry. */
    private const array PICKED = ['tenants.id', 'tenants.ulid', 'tenants.name', 'tenants.status', 'tenants.plan_id',
        'tenants.created_at', 'tenants.owner_user_id', 'tenants.deleted_at'];

    /** How many letters or digits in a row a trigram index can look up. */
    private const int TRIGRAM = 3;

    /**
     * One page of customers, newest first, with the list's counts under the same search.
     *
     * One statement when searching: the customers matching the search are found once (a
     * MATERIALIZED CTE, with the columns a page row needs), then both counted and paged from there,
     * never by joining them back to `tenants` (that read the whole table again). With no search, the page and the
     * counts read the table directly (two statements, each cheaper than gathering everyone). A wide search spends most of its time finding them (the
     * trigram index checks every matched row again with the fold), so a second statement for the
     * counts would double it.
     *
     * `base` is the status groups with neither the search nor the plans (the "N trong M" totals)
     * when they came from the same GROUP BY as `counts` (no search), else null: counts('', [])
     * gives them then.
     *
     * @param  list<string>  $plans  plan keys
     * @return array{rows: Collection<int, Tenant>, older: string|null, newer: string|null, counts: array{groups: array{all: int, active: int, suspended: int}, plans: array<int, int>}, base: array{all: int, active: int, suspended: int}|null}
     */
    public function page(string $search, string $status, array $plans, int $per, string $after, string $before): array
    {
        $anchorUlid = $before !== '' ? $before : $after;
        $newer = $before !== '';
        $anchor = $anchorUlid === '' ? null : Tenant::query()->where('ulid', $anchorUlid)->first(['id', 'created_at']);
        if ($anchor === null) {
            $newer = false;
        }

        $searching = self::searches($search);
        $direction = $newer ? 'asc' : 'desc';
        // the page's rows are picked from tenants alone, then joined to their owners: joining first
        // made the planner hash every member before sorting (about 50 ms at 50,000)
        $picked = Tenant::query()
            ->when($searching, fn (Builder $w) => $w->from('customer_matches', 'tenants'))
            ->when($status !== '', fn (Builder $w) => $w->where('tenants.status', $status))
            ->when($plans !== [], fn (Builder $w) => $w->whereIn('tenants.plan_id', Plan::query()->select('id')->whereIn('key', $plans)))
            // a customer with no created_at sorts as the oldest, so it still pages (a NULL in the
            // row comparison would make every page after it empty)
            ->when($anchor !== null, fn (Builder $w) => $w->whereRaw(
                "(coalesce(tenants.created_at, '-infinity'), tenants.id) ".($newer ? '>' : '<')." (coalesce(?::timestamptz, '-infinity'), ?)",
                [$anchor?->getRawOriginal('created_at'), $anchor?->getKey()],
            ))
            ->select(self::PICKED)
            ->orderByRaw("coalesce(tenants.created_at, '-infinity') {$direction}")->orderBy('tenants.id', $direction)
            ->limit($per + 1);
        $page = Tenant::query()->fromSub($picked, 'tenants')
            ->leftJoin('users', 'users.id', '=', 'tenants.owner_user_id')
            ->select(['tenants.id', 'tenants.ulid', 'tenants.name', 'tenants.status', 'tenants.plan_id', 'tenants.created_at',
                'users.name as owner_name', 'users.email as owner_email'])
            ->withCount('sites')
            ->orderByRaw("coalesce(tenants.created_at, '-infinity') {$direction}")->orderBy('tenants.id', $direction);

        if ($searching) {
            $matches = $this->filtered($search)->toBase()->select(self::PICKED);
            // the counts row is always there; the page joins it, and may be empty
            $sql = 'with customer_matches as materialized ('.$matches->toSql().') '
                .'select c.match_counts, p.* from (select json_agg(g) as match_counts from '
                .'(select status, plan_id, count(*) as n from customer_matches group by status, plan_id) as g) as c '
                .'left join lateral ('.$page->toSql().') as p on true '
                ."order by coalesce(p.created_at, '-infinity') {$direction}, p.id {$direction}";
            $read = Tenant::fromQuery($sql, [...$matches->getBindings(), ...$page->getBindings()]);
            $first = $read->first();
            $tally = is_string($first?->getAttribute('match_counts')) ? json_decode($first->getAttribute('match_counts'), true) : [];
            $counts = $this->tally(is_array($tally) ? $tally : [], $plans);
            $base = null;
            $rows = $read->filter(fn (Tenant $t): bool => $t->getKey() !== null)
                ->each(fn (Tenant $t) => $t->offsetUnset('match_counts'));
        } else {
            // no search: every customer matches, so the matches are not gathered first (that alone
            // cost about 80 ms at 50,000); the page and one GROUP BY read the table directly, and
            // that GROUP BY gives the totals with no plan chosen too
            $rows = $page->get();
            $groups = $this->groups('');
            $counts = $this->tally($groups, $plans);
            $base = $plans === [] ? $counts['groups'] : $this->tally($groups, [])['groups'];
        }

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
            'counts' => $counts,
            'base' => $base,
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
        return $this->tally($this->groups($search), $plans);
    }

    /**
     * How many customers match $search in each (status, plan): one GROUP BY.
     *
     * @return list<array<array-key, mixed>>
     */
    private function groups(string $search): array
    {
        $rows = $this->filtered($search)->toBase()
            ->selectRaw('tenants.status, tenants.plan_id, count(*) as n')->groupBy('tenants.status', 'tenants.plan_id')->get();

        return array_values($rows->map(fn (object $row): array => (array) $row)->all());
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

    /** Whether $search narrows the list: false when it is empty or has no letter or digit. */
    public static function searches(string $search): bool
    {
        return self::parts(self::needle($search)) !== [];
    }

    /** Typed, but with no letter or digit to look for: the list says what to type. */
    public static function unsearchable(string $search): bool
    {
        return self::needle($search) !== '' && ! self::searches($search);
    }

    /**
     * Customers matching the search (every customer when it does not narrow, see searches()).
     *
     * @return Builder<Tenant>
     */
    private function filtered(string $search): Builder
    {
        $query = Tenant::query();
        if (! self::searches($search)) {
            return $query;
        }

        $needle = self::needle($search);
        $number = self::customerNo($needle);
        $byCode = $number === null ? null : Tenant::query()->select('id')->where('customer_no', $number);

        if (! self::wordy($needle)) {
            // too short for a trigram: word starts, on the word indexes
            [$from, $bindings] = self::wordStarts($needle, $number);

            return $query->fromRaw($from, $bindings);
        }

        $like = VietnameseFold::like($needle);
        // Each condition is its own lookup, joined by UNION, so each is served by its own index
        // (the customer's name, the owner's name, the owner's email, then the owner's customers,
        // and the customer's number when the search reads as a code). Written as one OR across
        // tenants and users, no index serves it, and the name of every customer is folded on each
        // search (about 10 µs a row with this letter table).
        $owners = User::query()->select('id')->whereRaw(VietnameseFold::sql('name').' LIKE ?', [$like])
            ->union(User::query()->select('id')->whereRaw('lower(email) LIKE ?', [$like]));
        $matching = Tenant::query()->select('id')->whereRaw(VietnameseFold::sql('name').' LIKE ?', [$like])
            ->union(Tenant::query()->select('id')->whereIn('owner_user_id', $owners));
        if ($byCode !== null) {
            $matching->union($byCode);
        }

        return $query->whereIn('tenants.id', $matching);
    }

    /**
     * A search too short for a trigram: the customers with a word starting with each part of the
     * needle in their own name or their owner's, on the word indexes (VietnameseFold::words), and
     * the customer with that number. Such a search often matches a large share of the customers,
     * so each lookup returns the rows themselves, and the lookups never overlap (by the owner; by
     * the customer's own name, owner not matched; by code, neither): gathering ids first and
     * reading those customers again cost a pass over the whole table, and dropping duplicates
     * (UNION) hashed every row matched, spilling to disk at 50,000. The owners matched are read
     * once (a MATERIALIZED CTE), as both lookups use them. Measured at 50,000 (median of five):
     * 25-27 ms when two fifths of the customers match by name; about 205 ms when every owner
     * matches (the owners are hashed twice), within the wide-search budget. Leaving the customers
     * found by name out of the owner lookup instead measured 37-49 and 91-99 ms. Deleted customers
     * go in the caller's scope.
     *
     * @return array{0: literal-string, 1: list<int|string>} the FROM expression, aliased "tenants", and its bindings
     */
    private static function wordStarts(string $needle, ?int $number): array
    {
        $words = VietnameseFold::words('name')." @@ to_tsquery('simple', ?)";
        $prefixes = implode(' & ', array_map(fn (string $part): string => $part.':*', self::parts($needle)));
        $columns = implode(', ', self::PICKED);
        $notCode = $number === null ? '' : ' and tenants.customer_no <> ?';
        $code = $number === null ? [] : [$number];

        $sql = "with owners as materialized (select id from users where {$words}) "
            ."select {$columns} from tenants where tenants.owner_user_id in (select id from owners){$notCode} "
            ."union all select {$columns} from tenants where {$words}"
            ." and (tenants.owner_user_id is null or tenants.owner_user_id not in (select id from owners)){$notCode}"
            .($number === null ? '' : " union all select {$columns} from tenants where tenants.customer_no = ?");

        /** @var literal-string $from built from constants only; the needle goes in the bindings */
        $from = "({$sql}) as tenants";

        return [$from, [$prefixes, ...$code, $prefixes, ...$code, ...$code]];
    }

    private static function needle(string $search): string
    {
        if (! mb_check_encoding($search, 'UTF-8')) {
            return '';
        }

        return VietnameseFold::text(trim(mb_substr(trim($search), 0, self::SEARCH_MAX)));
    }

    /** At least TRIGRAM letters or digits in a row: what a trigram index can look up. */
    private static function wordy(string $needle): bool
    {
        return preg_match('/[\p{L}\p{N}]{'.self::TRIGRAM.'}/u', $needle) === 1;
    }

    /**
     * The runs of letters and digits in the folded needle ("an-v." → an, v): what a short search
     * looks for at the start of words. Only letters and digits, so each is safe in a tsquery.
     *
     * @return list<string>
     */
    private static function parts(string $needle): array
    {
        return preg_match_all('/[\p{L}\p{N}]+/u', $needle, $m) > 0 ? $m[0] : [];
    }

    /**
     * The customer number when the folded needle reads as a customer code: the configured prefix
     * (any case, diacritics ignored) or none, then digits, padded or not ("KH-000123", "kh-123",
     * "000123", "123" → 123). Past 18 digits it is no code (no overflow); 0 is none.
     */
    private static function customerNo(string $needle): ?int
    {
        $prefix = VietnameseFold::text(Cast::string(config('customers.code_prefix')));
        if (preg_match('/^(?:'.preg_quote($prefix, '/').')?0*(\d{1,18})$/u', $needle, $m) !== 1) {
            return null;
        }
        $number = (int) $m[1];

        return $number > 0 ? $number : null;
    }
}
