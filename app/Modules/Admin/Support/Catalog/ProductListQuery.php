<?php

namespace App\Modules\Admin\Support\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Shared\Support\Cast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The products list read in SQL: the status group, the search and the order run in the database and
 * only the rows of one page come back.
 *
 * - Every query keeps the explicit site_id of the BelongsToSite scope and runs in the request's
 *   tenancy transaction (RLS as app_user).
 * - Counts are bounded: they stop at cap + 1 rows, so "more than 10,000" costs at most 10,001 rows
 *   whatever the size of the website. The group counts are one statement.
 * - Every order ends with the row's id as the tie-break, and a row with no sort value (no price, no
 *   stock tracking, no name in the default language) comes after the others, whichever way the
 *   order runs. A page cursor names the last row of a page (its sort value and id), so keyset paging
 *   meets every row exactly once, also past the numbered pages.
 */
final class ProductListQuery
{
    /** Counts stop past this many rows ("hơn 10.000"); numbered pages reach this far into a list. */
    public const int CAP = 10_000;

    /** What a row of the list reads (no meta). */
    public const array COLUMNS = ['id', 'ulid', 'site_id', 'sku', 'price', 'sale_price', 'stock', 'is_featured', 'is_bestseller',
        'status', 'published_at', 'created_at', 'updated_at', 'deleted_at'];

    /** The status groups that map to one status; "all" is every live row and "trash" every deleted one. */
    public const array STATUS_TABS = ['published', 'draft', 'archived'];

    /** Every sort key, with the direction it reads in. */
    public const array SORTS = ['updated' => 'desc', 'name' => 'asc', 'price_asc' => 'asc', 'price_desc' => 'desc', 'stock_asc' => 'asc'];

    /** The order of names: Vietnamese alphabet, case and accents in their places. */
    private const string NAME_COLLATION = '"vi-VN-x-icu"';

    public function __construct(
        private readonly int $siteId,
        private readonly string $tab,
        private readonly string $search,
        private readonly string $locale,
        private readonly int $cap = self::CAP,
    ) {}

    /**
     * The status group and the search.
     *
     * @return Builder<Product>
     */
    public function filtered(): Builder
    {
        $query = $this->scoped($this->tab);
        $term = trim($this->search);
        if ($term === '') {
            return $query;
        }

        $like = '%'.addcslashes($term, '\\%_').'%';
        // no index can serve an ILIKE under RLS: the list walks its rows in the order shown and stops once
        // the page is full; `offset 0` keeps the name test a probe per row
        $query->where(fn (Builder $either) => $either->where('products.sku', 'ilike', $like)
            ->orWhereExists(fn (QueryBuilder $text) => $text->selectRaw('1')->from('product_translations as st')
                ->where('st.site_id', $this->siteId)
                ->whereColumn('st.product_id', 'products.id')
                ->where('st.name', 'ilike', $like)
                ->offset(0)));

        return $query;
    }

    /**
     * The rows of one status group: every live row, one status, or the Trash.
     *
     * @return Builder<Product>
     */
    private function scoped(string $tab): Builder
    {
        $query = Product::query();
        if ($tab === 'trash') {
            $query->onlyTrashed();
        } elseif (in_array($tab, self::STATUS_TABS, true)) {
            $query->where('products.status', $tab);
        }

        return $query;
    }

    /**
     * The group counts, bounded, in one statement: every live row, each status, the Trash.
     *
     * @return array<string, int>
     */
    public function tabCounts(): array
    {
        $select = [];
        $bindings = [];
        $keys = ['all', ...self::STATUS_TABS, 'trash'];
        foreach ($keys as $i => $key) {
            [$sql, $values] = $this->boundedSql($this->scoped($key));
            $select[] = "{$sql} as c{$i}";
            $bindings = [...$bindings, ...$values];
        }
        $row = (array) DB::selectOne('select '.implode(', ', $select), $bindings);

        $out = [];
        foreach ($keys as $i => $key) {
            $out[$key] = Cast::int($row["c{$i}"] ?? 0);
        }

        return $out;
    }

    /**
     * The rows of the filtered list, counted up to cap + 1.
     *
     * @param  Builder<Product>  $query
     */
    public function count(Builder $query): int
    {
        [$sql, $bindings] = $this->boundedSql($query);

        return Cast::int(((array) DB::selectOne("select {$sql} as n", $bindings))['n'] ?? 0);
    }

    /**
     * @param  Builder<Product>  $query
     * @return array{0: string, 1: list<mixed>}
     */
    private function boundedSql(Builder $query): array
    {
        $rows = $query->toBase()->select(DB::raw('1'))->limit($this->cap + 1);
        $rows->orders = null;

        return ['(select count(*) from ('.$rows->toSql().') as bounded)', $rows->getBindings()];
    }

    /**
     * One page of rows in the order of $key: from $offset (numbered pages), or after $anchor (a page
     * cursor: the sort value and id of the row the page follows). With $before, the rows that come
     * before the anchor are read, nearest first, and turned round into list order.
     *
     * Each row carries `__sv`, its sort value as text, from which the next cursor is made.
     *
     * @param  array{v: string|null, id: int}|null  $anchor
     * @return EloquentCollection<int, Product>
     */
    public function rows(string $key, int $offset, int $limit, ?array $anchor = null, bool $before = false): EloquentCollection
    {
        $key = array_key_exists($key, self::SORTS) ? $key : 'updated';
        $dir = self::SORTS[$key];
        [$order, $value, $cast] = $this->sortExpression($key);
        $reading = $before ? ($dir === 'asc' ? 'desc' : 'asc') : $dir;

        $query = $this->filtered()
            ->select(array_map(fn (string $column): string => 'products.'.$column, self::COLUMNS))
            ->selectRaw("({$value})::text as __sv");
        if ($key === 'name') {
            $query->leftJoin('product_translations as nt', fn (JoinClause $title) => $title->on('nt.product_id', '=', 'products.id')
                ->where('nt.site_id', '=', $this->siteId)
                ->where('nt.locale', '=', $this->locale));
        }

        if ($anchor !== null) {
            [$sql, $values] = $this->afterAnchor($order, $value, $cast, $dir, $before, $anchor);
            $query->whereRaw($sql, $values);
            $offset = 0;
        }

        // spelled out: a missing value comes last in list order, so first when the list is read backwards
        $nulls = $before ? 'nulls first' : 'nulls last';
        $query->orderByRaw("{$order} {$reading} {$nulls}, products.id {$reading}")->offset($offset)->limit($limit);

        $rows = $query->get();

        return $before ? $rows->reverse()->values() : $rows;
    }

    /**
     * What continues the list after (or, with $before, ahead of) the anchor row.
     *
     * @param  literal-string  $order
     * @param  literal-string  $value
     * @param  literal-string  $cast
     * @param  'asc'|'desc'  $dir
     * @param  array{v: string|null, id: int}  $anchor
     * @return array{0: literal-string, 1: list<mixed>}
     */
    private function afterAnchor(string $order, string $value, string $cast, string $dir, bool $before, array $anchor): array
    {
        $forward = $dir === 'desc' ? '<' : '>';
        $op = $before ? ($forward === '<' ? '>' : '<') : $forward;

        if ($anchor['v'] === null) {
            // the rows with no value close the list, by id: after one, only later ids of them; before one,
            // every row with a value and the earlier ids of them
            return $before
                ? ["({$value} is not null or products.id {$op} ?)", [$anchor['id']]]
                : ["({$value} is null and products.id {$op} ?)", [$anchor['id']]];
        }

        $row = "(({$order}, products.id) {$op} (?::{$cast}, ?))";

        return [$before ? $row : "({$row} or {$value} is null)", [$anchor['v'], $anchor['id']]];
    }

    /**
     * [order expression, value expression, cast of the value]
     *
     * @return array{0: literal-string, 1: literal-string, 2: literal-string}
     */
    private function sortExpression(string $key): array
    {
        return match ($key) {
            'name' => ['nt.name collate '.self::NAME_COLLATION, 'nt.name', 'text'],
            'price_asc', 'price_desc' => ['products.price', 'products.price', 'bigint'],
            'stock_asc' => ['products.stock', 'products.stock', 'integer'],
            // the Trash lists what was removed last first
            default => $this->tab === 'trash'
                ? ['products.deleted_at', 'products.deleted_at', 'timestamptz']
                : ['products.updated_at', 'products.updated_at', 'timestamptz'],
        };
    }
}
