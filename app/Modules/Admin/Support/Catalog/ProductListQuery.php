<?php

namespace App\Modules\Admin\Support\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductSearchWords;
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
 * - The numbers beside the filter values (valueCounts) are read only while the whole list is within
 *   the cap; past it the values show no number and the statement is not run.
 * - Every order ends with the row's id as the tie-break, and a row with no sort value (no price, no
 *   stock tracking, no name in the default language) comes after the others, whichever way the
 *   order runs. A page cursor names the last row of a page (its sort value and id), so keyset paging
 *   meets every row exactly once, also past the numbered pages.
 */
final class ProductListQuery
{
    /** Counts stop past this many rows ("hơn 10.000"); numbered pages reach this far into a list. */
    public const int CAP = 10_000;

    /** Word rows of the rarest typed word up to which a search gathers its products first (whereWords). */
    public const int GATHER = 100_000;

    /** What a row of the list reads (no meta). */
    public const array COLUMNS = ['id', 'ulid', 'site_id', 'sku', 'price', 'sale_price', 'sale_ends_at', 'stock', 'is_featured', 'is_bestseller',
        'status', 'published_at', 'created_at', 'updated_at', 'deleted_at'];

    /** The status groups that map to one status; "all" is every live row and "trash" every deleted one. */
    public const array STATUS_TABS = ['published', 'draft', 'archived'];

    /** Every sort key, with the direction it reads in. */
    public const array SORTS = ['updated' => 'desc', 'name' => 'asc', 'price_asc' => 'asc', 'price_desc' => 'desc', 'stock_asc' => 'asc'];

    /** The order of names: Vietnamese alphabet, case and accents in their places. */
    private const string NAME_COLLATION = '"vi-VN-x-icu"';

    /**
     * A sale is on: a lower price than the regular one, inside its dates, an open end counting as always.
     */
    private const string ON_SALE = 'products.sale_price is not null and products.price is not null'
        .' and products.sale_price < products.price'
        .' and (products.sale_starts_at is null or products.sale_starts_at <= now())'
        .' and (products.sale_ends_at is null or products.sale_ends_at >= now())';

    private const string ON_SALE_GROUP = '('.self::ON_SALE.')';

    /** How many of the site's languages a product has a translation row in (two bindings: site, languages). */
    private const string HAVE = '(select count(*) from product_translations tr where tr.site_id = ? and tr.product_id = products.id and tr.locale = any(?::text[]))';

    /** The price a row shows: the sale price while a sale is on, else the regular price. */
    private const string SHOWN_PRICE = 'case when '.self::ON_SALE.' then products.sale_price else products.price end';

    public function __construct(
        private readonly int $siteId,
        private readonly string $tab,
        private readonly string $search,
        private readonly string $locale,
        private readonly int $cap = self::CAP,
        private readonly ProductListFilter $filter = new ProductListFilter,
        private readonly int $gather = self::GATHER,
    ) {}

    /** @var array<string, array<string, int>> the word counts of a search, read once per list (wordCounts) */
    private array $wordCounts = [];

    /**
     * The status group, the filters and the search. $order is the sort the rows will be read in (rows()),
     * or null for a count or a set of rows the caller picks itself.
     *
     * @return Builder<Product>
     */
    public function filtered(?string $order = null): Builder
    {
        $query = $this->scoped($this->tab);
        $this->applyFilters($query);
        $term = trim($this->search);
        if ($term === '') {
            return $query;
        }

        $needles = ProductSearchWords::needles($term);
        if ($needles === []) {
            // nothing that can start a word ("%", only signs): nothing is found, never the whole list
            return $query->whereRaw('false');
        }
        $this->whereWords($query, $needles, $order === null || ($order === 'updated' && $this->tab !== 'trash'));

        return $query;
    }

    /**
     * Each typed word starts one of the product's words (ProductSearchWords), in any order: accents and
     * case are ignored, `%` and `_` are letters like any other. Every condition is a range of the word
     * table's key (leakproof, so an index condition under RLS).
     *
     * The rarest word decides the shape, by its word rows counted up to the gather limit + 1 (one
     * statement for every word, read once per list):
     * - none: nothing is found;
     * - at most the gather limit (100,000), or a page in an order no index walks (name, price, stock, the
     *   Trash): its products are gathered first, each checked for the other words, and the list reads
     *   them by id (`id = any(array(…))`, then sorted);
     * - more (every word is very common) for a count or a page in edit order: the list walks in its own
     *   order and checks each row's words until the page or the bounded count is full (`offset 0` keeps
     *   each a probe per row).
     * Walking when a word is merely common is fast only while the matches are dense: measured on a
     * website of 200,000 products, "vòng bi chịu nhiệt" (3,800 matches, every word on 20,000 or more)
     * took 1.5 s to count by walking and the "published" tab, where none matched, 1.0 s for its first
     * page; the name and price orders walked every row (1.1–1.6 s). Gathered, each is under 100 ms.
     * A row count is a little over the products when a product has several words with the same start;
     * that only moves the choice, never the result.
     *
     * @param  Builder<Product>  $query
     * @param  list<string>  $needles
     */
    private function whereWords(Builder $query, array $needles, bool $walkable): void
    {
        $counts = $this->wordCounts($needles);
        asort($counts);
        $rarest = (string) array_key_first($counts);
        if (($counts[$rarest] ?? 0) === 0) {
            $query->whereRaw('false');

            return;
        }

        $others = array_slice(array_map(strval(...), array_keys($counts)), 1);
        if ($counts[$rarest] <= $this->gather || ! $walkable) {
            [$range, $bindings] = ProductSearchWords::range('w.word', $rarest);
            $sql = 'select w.product_id from product_search_words w where w.site_id = ? and '.$range;
            $bindings = [$this->siteId, ...$bindings];
            foreach ($others as $needle) {
                [$also, $values] = ProductSearchWords::range('o.word', $needle);
                $sql .= ' and exists (select 1 from product_search_words o where o.site_id = ? and o.product_id = w.product_id and '.$also.')';
                $bindings = [...$bindings, $this->siteId, ...$values];
            }
            $query->whereRaw("products.id = any (array({$sql}))", $bindings);

            return;
        }

        foreach ([$rarest, ...$others] as $needle) {
            [$range, $bindings] = ProductSearchWords::range('w.word', $needle);
            $query->whereExists(fn (QueryBuilder $has) => $has->selectRaw('1')->from('product_search_words as w')
                ->where('w.site_id', $this->siteId)
                ->whereColumn('w.product_id', 'products.id')
                ->whereRaw($range, $bindings)
                ->offset(0));
        }
    }

    /**
     * How many word rows of the site start with each needle, each counted up to the gather limit + 1, in one statement.
     *
     * @param  list<string>  $needles
     * @return array<string, int>
     */
    private function wordCounts(array $needles): array
    {
        $key = implode("\0", $needles);
        if (isset($this->wordCounts[$key])) {
            return $this->wordCounts[$key];
        }

        $select = [];
        $bindings = [];
        foreach ($needles as $i => $needle) {
            [$range, $values] = ProductSearchWords::range('word', $needle);
            $select[] = "(select count(*) from (select 1 from product_search_words where site_id = ? and {$range} limit ?) as bounded) as c{$i}";
            $bindings = [...$bindings, $this->siteId, ...$values, $this->gather + 1];
        }
        $row = (array) DB::selectOne('select '.implode(', ', $select), $bindings);
        $counts = [];
        foreach ($needles as $i => $needle) {
            $counts[$needle] = Cast::int($row["c{$i}"] ?? 0);
        }

        return $this->wordCounts[$key] = $counts;
    }

    /**
     * The filter groups, all of which must hold; inside a group any value does. Every test reads a
     * real column (or the product's own rows in a pivot or translation table), never the meta.
     *
     * @param  Builder<Product>  $query
     */
    private function applyFilters(Builder $query): void
    {
        $f = $this->filter;
        if ($f->categoryIds !== []) {
            // the main category (position 0) is one of the chosen ones or sits under one of them
            $query->whereExists(fn (QueryBuilder $filed) => $filed->selectRaw('1')->from('product_taxonomies as pc')
                ->where('pc.site_id', $this->siteId)
                ->whereColumn('pc.product_id', 'products.id')
                ->where('pc.position', 0)
                ->whereRaw('pc.taxonomy_id = any(?::bigint[])', [self::pgArray($f->categoryIds)]));
        }
        if ($f->trans !== [] && count($f->locales) >= 2) {
            $this->whereTransStates($query, $f->trans, $f->locales);
        }
        if ($f->tags !== []) {
            $query->where(function (Builder $either) use ($f): void {
                foreach ($f->tags as $tag) {
                    $either->orWhere($tag === 'featured' ? 'products.is_featured' : 'products.is_bestseller', true);
                }
            });
        }
        if ($f->stock !== []) {
            $query->where(function (Builder $either) use ($f): void {
                foreach ($f->stock as $kind) {
                    match ($kind) {
                        'in' => $either->orWhere('products.stock', '>', 0),
                        'out' => $either->orWhere('products.stock', 0),
                        default => $either->orWhereNull('products.stock'),
                    };
                }
            });
        }
        if ($f->sale) {
            $query->whereRaw(self::ON_SALE_GROUP);
        }
    }

    /**
     * A product's translation state among the site's languages: every one (full), some (partial),
     * only one or none (none) — counted per row in the database, by which languages have a row.
     *
     * @param  Builder<Product>  $query
     * @param  list<string>  $states
     * @param  list<string>  $locales
     */
    private function whereTransStates(Builder $query, array $states, array $locales): void
    {
        $n = count($locales);
        $allowed = [];
        foreach ($states as $state) {
            $allowed = [...$allowed, ...match ($state) {
                'full' => [$n],
                'none' => [0, 1],
                default => $n > 2 ? range(2, $n - 1) : [],
            }];
        }
        if ($allowed === []) {
            $query->whereRaw('false');

            return;
        }

        $query->whereRaw('least('.self::HAVE.', ?) = any(?::int[])',
            [$this->siteId, self::pgArray($locales), $n, self::pgArray(array_values(array_unique($allowed)))]);
    }

    /**
     * The general numbers beside the filter values, in one statement: each value has its own count that
     * stops at cap + 1, not changed by any other filter or the group. The caller runs this only while the
     * site's whole list is within the cap; past it the values carry no number.
     *
     * @param  list<string>  $locales  the site's languages; fewer than two leave out the translation states
     * @return array{tags: array<string, int>, stock: array<string, int>, trans: array<string, int>, sale: int}
     */
    public function valueCounts(array $locales, bool $selling): array
    {
        $n = count($locales);
        /** @var array<string, Builder<Product>> $wanted */
        $wanted = [
            't_featured' => Product::query()->where('products.is_featured', true),
            't_best' => Product::query()->where('products.is_bestseller', true),
        ];
        if ($selling) {
            $wanted['s_in'] = Product::query()->where('products.stock', '>', 0);
            $wanted['s_out'] = Product::query()->where('products.stock', 0);
            $wanted['s_none'] = Product::query()->whereNull('products.stock');
            $wanted['sale'] = Product::query()->whereRaw(self::ON_SALE_GROUP);
        }
        if ($n >= 2) {
            $have = [$this->siteId, self::pgArray($locales)];
            $wanted['r_full'] = Product::query()->whereRaw(self::HAVE.' >= ?', [...$have, $n]);
            // with exactly two languages no product can have some but not all of them and more than one
            if ($n > 2) {
                $wanted['r_partial'] = Product::query()->whereRaw(self::HAVE.' > 1 and '.self::HAVE.' < ?', [...$have, ...$have, $n]);
            }
            $wanted['r_none'] = Product::query()->whereRaw(self::HAVE.' <= 1', $have);
        }

        $select = [];
        $bindings = [];
        foreach ($wanted as $key => $query) {
            [$sql, $values] = $this->boundedSql($query);
            $select[] = "{$sql} as {$key}";
            $bindings = [...$bindings, ...$values];
        }
        $row = (array) DB::selectOne('select '.implode(', ', $select), $bindings);
        $get = fn (string $key): int => Cast::int($row[$key] ?? 0);

        return [
            'tags' => ['featured' => $get('t_featured'), 'best' => $get('t_best')],
            'stock' => $selling ? ['in' => $get('s_in'), 'out' => $get('s_out'), 'none' => $get('s_none')] : [],
            'trans' => $n >= 2 ? ['full' => $get('r_full'), 'partial' => $n > 2 ? $get('r_partial') : 0, 'none' => $get('r_none')] : [],
            'sale' => $selling ? $get('sale') : 0,
        ];
    }

    /** @param  list<int|string>  $values */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(fn (int|string $v): string => is_int($v) ? (string) $v : '"'.addcslashes($v, '"\\').'"', $values)).'}';
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
     * A cursor page is read in at most two parts, each a plain range in the list's order: the rows
     * with a sort value, then (only when the first part did not fill the page) the rows without one.
     * One condition for both ("past the anchor, or no value") is not a range, so an index on the
     * order could only be walked from its end and filtered row by row.
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
        [$order, $value, $cast, $nullable] = $this->sortExpression($key);
        $reading = $before ? ($dir === 'asc' ? 'desc' : 'asc') : $dir;

        $query = $this->filtered($key)
            ->select(array_map(fn (string $column): string => 'products.'.$column, self::COLUMNS))
            ->selectRaw("({$value})::text as __sv")
            ->selectRaw('('.self::ON_SALE.') as on_sale'); // the sale state is the one the filter and the sort use, never re-worked in PHP
        if ($key === 'name') {
            $query->leftJoin('product_translations as nt', fn (JoinClause $title) => $title->on('nt.product_id', '=', 'products.id')
                ->where('nt.site_id', '=', $this->siteId)
                ->where('nt.locale', '=', $this->locale));
        }

        // spelled out: a missing value comes last in list order, so first when the list is read backwards
        $nulls = $before ? 'nulls first' : 'nulls last';
        $query->orderByRaw("{$order} {$reading} {$nulls}, products.id {$reading}");

        if ($anchor === null) {
            return $query->offset($offset)->limit($limit)->get();
        }

        /** @var list<Product> $found */
        $found = [];
        foreach ($this->afterAnchor($value, $cast, $order, $dir, $nullable, $before, $anchor) as [$sql, $values]) {
            $part = (clone $query)->whereRaw($sql, $values)->limit($limit - count($found))->get();
            $found = [...$found, ...$part->all()];
            if (count($found) >= $limit) {
                break;
            }
        }
        // each part reads its own snapshot: a row whose value is cleared between the two is found twice, kept once
        $rows = (new EloquentCollection($found))->unique('id')->values();

        return $before ? $rows->reverse()->values() : $rows;
    }

    /**
     * The parts that continue the list after (or, with $before, ahead of) the anchor row, in reading
     * order: [condition, bindings] each.
     *
     * @param  literal-string  $value
     * @param  literal-string  $cast
     * @param  literal-string  $order
     * @param  'asc'|'desc'  $dir
     * @param  array{v: string|null, id: int}  $anchor
     * @return list<array{0: literal-string, 1: list<mixed>}>
     */
    private function afterAnchor(string $value, string $cast, string $order, string $dir, bool $nullable, bool $before, array $anchor): array
    {
        $forward = $dir === 'desc' ? '<' : '>';
        $op = $before ? ($forward === '<' ? '>' : '<') : $forward;

        if ($anchor['v'] === null) {
            // the rows with no value close the list, by id: after one, only later ids of them; before
            // one, the earlier ids of them nearest first, then every row with a value
            $rest = ["({$value} is null and products.id {$op} ?)", [$anchor['id']]];

            return $before ? [$rest, ["{$value} is not null", []]] : [$rest];
        }

        // a row with no value never passes the row comparison, so the rows with a value are one range
        $row = ["(({$order}, products.id) {$op} (?::{$cast}, ?))", [$anchor['v'], $anchor['id']]];

        // after a row with a value come the rest of them, then the rows with none; ahead of it, only rows with a value
        return $before || ! $nullable ? [$row] : [$row, ["{$value} is null", []]];
    }

    /**
     * [order expression, value expression, cast of the value, whether a row may have no value]
     *
     * @return array{0: literal-string, 1: literal-string, 2: literal-string, 3: bool}
     */
    private function sortExpression(string $key): array
    {
        return match ($key) {
            'name' => ['nt.name collate '.self::NAME_COLLATION, 'nt.name', 'text', true],
            'price_asc', 'price_desc' => [self::SHOWN_PRICE, self::SHOWN_PRICE, 'bigint', true],
            'stock_asc' => ['products.stock', 'products.stock', 'integer', true],
            // the Trash lists what was removed last first; a row is in the Trash because it has a deletion time.
            // The edit time is a nullable column: a row written outside Eloquent may have none.
            default => $this->tab === 'trash'
                ? ['products.deleted_at', 'products.deleted_at', 'timestamptz', false]
                : ['products.updated_at', 'products.updated_at', 'timestamptz', true],
        };
    }
}
