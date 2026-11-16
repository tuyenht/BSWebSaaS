<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Search;

use App\Modules\Catalog\Support\ProductSearchWords;
use App\Modules\Shared\Support\Cast;
use App\Modules\Storefront\Support\ThankYouPages;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Support\Facades\DB;

/**
 * The storefront search of the current site (`/tim-kiem`): which posts, pages and products a visitor
 * may see match the words typed, in which group, in which order. Reads only the word tables
 * (SiteSearchWords: titles, names, codes at weight 1, short descriptions at 3) and the category words,
 * never the long text, in the page's language only.
 *
 * - Every word typed must match a stored word of the item, the last one as the start of a word.
 * - Candidates come from the rarest word (its rows counted up to GATHER + 1 in one statement): when it
 *   has at most GATHER rows, all of them are ranked by the best field (title before short description),
 *   then newest; titles that hold the accented words typed come first. When even the rarest word is
 *   more common than that, the newest visible items are walked instead (at most EXAMINE per page: the
 *   posts and pages as one stream, the products as another, newest first, continued by a keyset on the
 *   publish time; only the walked items are judged, so the cursor never passes one not yet examined),
 *   and the counts say "at least".
 * - A category whose words hold every word typed (at most CATEGORY_MAX) brings its items and those of
 *   its subcategories, through the category pivots, ranked between a title and a short description.
 * - Only what a visitor may open: published, not in the Trash, publish time reached, a published
 *   translation in the language (posts and pages) or a translation (products), never a form's thank-you page.
 * - Groups: the products, each level-1 post category (an item's main category decides), and the
 *   rest (pages, posts without a category).
 *
 * Every statement names the site (`site_id = ?`) besides RLS, selects ids and sort keys only (the
 * page loads what it shows for the few rows shown), and has a bound: no OFFSET, no count past the caps.
 *
 * The caps were measured on one site of 200,000 items (150,000 posts and pages, 50,000 products, skewed
 * words, 3.1M word rows): a first page takes 3–90 ms at the 95th percentile for one to three words
 * (common, rare, a two-letter start, a product code) and 170 ms for eight common words, all three
 * statements together. The candidates are looked up by id (`= any (array(…))`, an index lookup each)
 * and the posts walk follows the posts list index (newest first, never-dated last).
 */
final class SiteSearch
{
    /** Most word rows of the rarest word whose items are all ranked (measured, see the class doc). */
    public const int GATHER = 1000;

    /** Most items walked per page (posts and pages together; products) when even the rarest word is more common than GATHER (2,000 cost a common word about 30% more). */
    public const int EXAMINE = 1000;

    /** Rows a group shows on the first page. */
    public const int PER_GROUP = 3;

    /** Rows each "Xem thêm" adds. */
    public const int PAGE = 10;

    /** Most categories a search matches by their own words. */
    public const int CATEGORY_MAX = 20;

    /** The products group. */
    public const string PRODUCTS = 'sp';

    /** The group of pages and posts without a level-1 post category. */
    public const string OTHER = 'khac';

    /** The weight of an item found only through a matched category: below a title (1), above a short description (3). */
    private const int CATEGORY_WEIGHT = 2;

    /** A weight that never matches (larger than any stored weight). */
    private const int NO_WEIGHT = 9;

    public function __construct(
        private readonly CurrentSite $current,
        private readonly ThankYouPages $thankYou,
    ) {}

    /**
     * The first page: each group with its count and its first PER_GROUP rows, products first, then the
     * post groups by their level-1 category's id, the rest last (the page puts the categories in their own order).
     */
    public function overview(SearchText $q, string $locale): SearchResult
    {
        if (! $q->searchable()) {
            return new SearchResult([], false);
        }
        $plan = $this->plan($q, $locale);
        $groups = [];
        foreach ($this->products($q, $locale, $plan, null, true) as $group) {
            $groups[] = $group;
        }
        foreach ($this->entries($q, $locale, $plan, null, null, true) as $group) {
            $groups[] = $group;
        }

        return new SearchResult($groups, false);
    }

    /**
     * One group's next PAGE rows after the row with ulid $after (from the start when null).
     *
     * @param  string  $group  PRODUCTS, OTHER or the level-1 post category's id as "c{id}"
     */
    public function more(SearchText $q, string $locale, string $group, ?string $after): SearchResult
    {
        if (! $q->searchable()) {
            return new SearchResult([], false);
        }
        $plan = $this->plan($q, $locale);
        if ($group === self::PRODUCTS) {
            $groups = $this->products($q, $locale, $plan, $after, false);
        } else {
            $root = $group === self::OTHER ? 0 : (preg_match('/^c([1-9][0-9]{0,18})$/', $group, $m) === 1 ? (int) $m[1] : null);
            $groups = $root === null ? [] : $this->entries($q, $locale, $plan, $root, $after, false);
        }

        return new SearchResult($groups, true);
    }

    /**
     * How many rows each word has in each table (up to GATHER + 1), and the categories (with their
     * subcategories) whose own words hold every word typed, in one statement.
     *
     * @return array{entries: list<int>, products: list<int>, categories: list<int>}
     */
    private function plan(SearchText $q, string $locale): array
    {
        $site = $this->current->id();
        $select = [];
        $bindings = [];
        foreach (['entry_search_words' => 'e', 'product_site_words' => 'p'] as $table => $alias) {
            foreach ($q->words as $i => $word) {
                [$cond, $values] = $this->match('word', $q, $i);
                $select[] = "(select count(*) from (select 1 from {$table} where site_id = ? and locale = ? and {$cond} limit ?) as bounded) as {$alias}{$i}";
                $bindings = [...$bindings, $site, $locale, ...$values, self::GATHER + 1];
            }
        }

        // the categories matched by their own words: driven by the first word, each other word must be there too
        [$first, $values] = $this->match('w.word', $q, 0);
        $matched = "select w.taxonomy_id from taxonomy_search_words w where w.site_id = ? and w.locale = ? and {$first}";
        $catBindings = [$site, $locale, ...$values];
        foreach (array_keys($q->words) as $i) {
            if ($i === 0) {
                continue;
            }
            [$also, $more] = $this->match('o.word', $q, $i);
            $matched .= " and exists (select 1 from taxonomy_search_words o where o.site_id = ? and o.locale = ? and o.taxonomy_id = w.taxonomy_id and {$also})";
            $catBindings = [...$catBindings, $site, $locale, ...$more];
        }
        $matched .= ' group by w.taxonomy_id limit ?';
        $catBindings[] = self::CATEGORY_MAX;
        // with every category under them (by the ltree path; one whose path was never written is still itself)
        $select[] = "array(with m as ({$matched}) select s.id from taxonomies s where s.site_id = ? and s.vocabulary in ('post_cat', 'product_cat')"
            .' and (s.id in (select taxonomy_id from m) or s.path <@ any (array(select t.path from taxonomies t where t.site_id = ? and t.id in (select taxonomy_id from m) and t.path is not null)))) as categories';
        $bindings = [...$bindings, ...$catBindings, $site, $site];

        $row = (array) DB::selectOne('select '.implode(', ', $select), $bindings);
        $plan = ['entries' => [], 'products' => [], 'categories' => self::intArray($row['categories'] ?? null)];
        foreach (array_keys($q->words) as $i) {
            $plan['entries'][] = Cast::int($row["e{$i}"] ?? 0);
            $plan['products'][] = Cast::int($row["p{$i}"] ?? 0);
        }

        return $plan;
    }

    /**
     * The posts and pages: on the first page every group with its count and first rows, else the next
     * rows of one group ($root: a level-1 post category's id, 0 = the rest).
     *
     * @param  array{entries: list<int>, products: list<int>, categories: list<int>}  $plan
     * @return list<SearchGroup>
     */
    private function entries(SearchText $q, string $locale, array $plan, ?int $root, ?string $after, bool $overview): array
    {
        $site = $this->current->id();
        $counts = $plan['entries'];
        if ($counts === []) {
            return [];
        }
        $byWords = min($counts) > 0;
        $cats = $plan['categories'];
        if (! $byWords && $cats === []) {
            return [];
        }
        $rarest = (int) array_search(min($counts), $counts, true);
        $walk = $byWords && $counts[$rarest] > self::GATHER;

        $b = [];
        $keyset = '';
        $keysetBindings = [];
        if ($walk && $after !== null) {
            $keyset = ' and (e.published_at, e.id) < (select a.published_at, a.id from entries a where a.site_id = ? and a.ulid = ?)';
            $keysetBindings = [$site, $after];
        }

        // the candidates: the rarest word's items and the matched categories' items, or, walking, the newest
        // posts and pages as ONE stream in one order (a matched category's items are judged inside it too:
        // a candidate from outside the stream would move the cursor past items it never examined)
        if ($walk) {
            $streams = [];
            foreach (['post', 'page'] as $type) {
                $streams[] = "(select e.id, e.published_at from entries e where e.site_id = ? and e.type = '{$type}' and e.deleted_at is null and e.status = 'published'"
                    ." and e.published_at is not null{$keyset} order by e.published_at desc nulls last, e.id desc limit ?)";   // nulls last: a backward walk of the posts list index
                $b = [...$b, $site, ...$keysetBindings, self::EXAMINE];
            }
            // merged in order (a merge of the two ordered reads), the newest EXAMINE of both together
            $sql = 'with c as (select s.id from ('.implode(' union all ', $streams).') as s order by s.published_at desc nulls last, s.id desc limit ?)';
            $b[] = self::EXAMINE;
        } else {
            $parts = [];
            if ($byWords) {
                [$cond, $values] = $this->match('w.word', $q, $rarest);
                $parts[] = "(select w.entry_id as id from entry_search_words w where w.site_id = ? and w.locale = ? and {$cond})";
                $b = [...$b, $site, $locale, ...$values];
            }
            if ($cats !== []) {
                // the newest-made first, so the same rows are gathered on every page (no repeat, no skip)
                $parts[] = '(select p.entry_id as id from entry_taxonomies p where p.site_id = ? and p.taxonomy_id = any (?::bigint[]) order by p.entry_id desc limit ?)';
                $b = [...$b, $site, self::pgInts($cats), self::GATHER];
            }
            $sql = 'with c as ('.implode(' union ', $parts).')';
        }

        // the visible ones, with each word's best weight and the main category (materialized: the filter, the
        // weight and the sort each read the weights, which an inlined CTE would compute again each time)
        $sql .= ', v as materialized (select e.id, e.ulid, e.published_at, t.title';
        foreach (array_keys($q->words) as $i) {
            if ($byWords) {
                [$cond, $values] = $this->match('x.word', $q, $i);
                $sql .= ", (select min(x.weight) from entry_search_words x where x.site_id = ? and x.locale = ? and x.entry_id = e.id and {$cond}) as w{$i}";
                $b = [...$b, $site, $locale, ...$values];
            } else {
                $sql .= ", null::smallint as w{$i}";
            }
        }
        if ($cats !== []) {
            $sql .= ', exists (select 1 from entry_taxonomies p where p.site_id = ? and p.entry_id = e.id and p.taxonomy_id = any (?::bigint[])) as in_cat';
            $b = [...$b, $site, self::pgInts($cats)];
        } else {
            $sql .= ', false as in_cat';
        }
        $sql .= ', (select p.taxonomy_id from entry_taxonomies p where p.site_id = ? and p.entry_id = e.id and p.position = 0 order by p.id limit 1) as primary_id'
            .' from entries e join entry_translations t on t.entry_id = e.id and t.site_id = ? and t.locale = ? and t.status = \'published\''
            ." where e.site_id = ? and e.id = any (array(select id from c)) and e.deleted_at is null and e.status = 'published' and e.type in ('page', 'post')"
            .' and (e.published_at is null or e.published_at <= now()) and e.ulid::text <> all (?::text[])'.$keyset.')';
        $b = [...$b, $site, $site, $locale, $site, self::pgTexts($this->thankYou->all()), ...$keysetBindings];

        // the rows that match, their weight, accents and group (the main category's level-1 post category)
        [$weight, $accent, $accentBindings] = $this->scores($q);
        $sql .= ", r as (select v.id, v.ulid, v.published_at, v.primary_id, coalesce(root.id, 0) as root_id, {$weight} as weight, {$accent} as accent"
            .' from v left join taxonomies pt on pt.site_id = ? and pt.id = v.primary_id'
            // the level-1 category: the first id of the main category's path (itself when it is top-level and has no path yet)
            ." left join taxonomies root on root.site_id = ? and root.id = coalesce(nullif(split_part(pt.path::text, '.', 1), '')::bigint, case when pt.parent_id is null then pt.id end) and root.vocabulary = 'post_cat'"
            .' where ('.$this->allWords($q).' or v.in_cat)';
        $b = [...$b, ...$accentBindings, $site, $site];
        if ($root !== null) {
            $sql .= ' and coalesce(root.id, 0) = ?';
            $b[] = $root;
        }
        $order = $walk ? 'r.published_at desc nulls last, r.id desc' : 'r.accent desc, r.weight, r.published_at desc nulls last, r.id desc';
        $sql .= "), ranked as (select r.*, row_number() over (partition by r.root_id order by {$order}) as rn, count(*) over (partition by r.root_id) as n from r)";

        if ($overview) {
            $sql .= ' select id, ulid, primary_id, root_id, rn, n from ranked where rn <= ? order by root_id = 0, root_id, rn';
            $b[] = self::PER_GROUP;
        } elseif ($walk) {
            $sql .= ' select id, ulid, primary_id, root_id, rn, n from ranked order by rn limit ?';
            $b[] = self::PAGE + 1;
        } else {
            $sql .= ' select id, ulid, primary_id, root_id, rn, n from ranked where rn > coalesce((select k.rn from ranked k where k.ulid = ?), 0) order by rn limit ?';
            $b = [...$b, (string) $after, self::PAGE + 1];
        }

        return $this->groups(DB::select($sql, $b), $walk, $overview, fn (int $rootId): string => $rootId === 0 ? self::OTHER : 'c'.$rootId);
    }

    /**
     * The products (one group), as entries() does for posts.
     *
     * @param  array{entries: list<int>, products: list<int>, categories: list<int>}  $plan
     * @return list<SearchGroup>
     */
    private function products(SearchText $q, string $locale, array $plan, ?string $after, bool $overview): array
    {
        $site = $this->current->id();
        $counts = $plan['products'];
        if ($counts === []) {
            return [];
        }
        $byWords = min($counts) > 0;
        $cats = $plan['categories'];
        if (! $byWords && $cats === []) {
            return [];
        }
        $rarest = (int) array_search(min($counts), $counts, true);
        $walk = $byWords && $counts[$rarest] > self::GATHER;

        $b = [];
        $keyset = '';
        $keysetBindings = [];
        if ($walk && $after !== null) {
            $keyset = ' and (p.published_at, p.id) < (select a.published_at, a.id from products a where a.site_id = ? and a.ulid = ?)';
            $keysetBindings = [$site, $after];
        }

        if ($walk) {
            // the newest products only (a matched category's are judged inside the walk, as entries() does)
            $sql = "with c as (select p.id from products p where p.site_id = ? and p.status = 'published' and p.deleted_at is null"
                ." and p.published_at is not null{$keyset} order by p.published_at desc, p.id desc limit ?)";
            $b = [...$b, $site, ...$keysetBindings, self::EXAMINE];
        } else {
            $parts = [];
            if ($byWords) {
                [$cond, $values] = $this->match('w.word', $q, $rarest);
                $parts[] = "(select w.product_id as id from product_site_words w where w.site_id = ? and w.locale = ? and {$cond})";
                $b = [...$b, $site, $locale, ...$values];
            }
            if ($cats !== []) {
                // the newest-made first, so the same rows are gathered on every page (no repeat, no skip)
                $parts[] = '(select pc.product_id as id from product_taxonomies pc where pc.site_id = ? and pc.taxonomy_id = any (?::bigint[]) order by pc.product_id desc limit ?)';
                $b = [...$b, $site, self::pgInts($cats), self::GATHER];
            }
            $sql = 'with c as ('.implode(' union ', $parts).')';
        }

        $sql .= ', v as materialized (select p.id, p.ulid, p.published_at, t.name as title';
        foreach (array_keys($q->words) as $i) {
            if ($byWords) {
                [$cond, $values] = $this->match('x.word', $q, $i);
                $sql .= ", (select min(x.weight) from product_site_words x where x.site_id = ? and x.locale = ? and x.product_id = p.id and {$cond}) as w{$i}";
                $b = [...$b, $site, $locale, ...$values];
            } else {
                $sql .= ", null::smallint as w{$i}";
            }
        }
        if ($cats !== []) {
            $sql .= ', exists (select 1 from product_taxonomies pc where pc.site_id = ? and pc.product_id = p.id and pc.taxonomy_id = any (?::bigint[])) as in_cat';
            $b = [...$b, $site, self::pgInts($cats)];
        } else {
            $sql .= ', false as in_cat';
        }
        $sql .= ' from products p join product_translations t on t.product_id = p.id and t.site_id = ? and t.locale = ?'
            ." where p.site_id = ? and p.id = any (array(select id from c)) and p.deleted_at is null and p.status = 'published'"
            .' and (p.published_at is null or p.published_at <= now())'.$keyset.')';
        $b = [...$b, $site, $locale, $site, ...$keysetBindings];

        [$weight, $accent, $accentBindings] = $this->scores($q);
        $sql .= ", r as (select v.id, v.ulid, v.published_at, null::bigint as primary_id, 0 as root_id, {$weight} as weight, {$accent} as accent from v"
            .' where ('.$this->allWords($q).' or v.in_cat))';
        $b = [...$b, ...$accentBindings];
        $order = $walk ? 'r.published_at desc nulls last, r.id desc' : 'r.accent desc, r.weight, r.published_at desc nulls last, r.id desc';
        $sql .= ", ranked as (select r.*, row_number() over (order by {$order}) as rn, count(*) over () as n from r)";

        if ($overview) {
            $sql .= ' select id, ulid, primary_id, root_id, rn, n from ranked where rn <= ? order by rn';
            $b[] = self::PER_GROUP;
        } elseif ($walk) {
            $sql .= ' select id, ulid, primary_id, root_id, rn, n from ranked order by rn limit ?';
            $b[] = self::PAGE + 1;
        } else {
            $sql .= ' select id, ulid, primary_id, root_id, rn, n from ranked where rn > coalesce((select k.rn from ranked k where k.ulid = ?), 0) order by rn limit ?';
            $b = [...$b, (string) $after, self::PAGE + 1];
        }

        return $this->groups(DB::select($sql, $b), $walk, $overview, fn (int $rootId): string => self::PRODUCTS);
    }

    /**
     * The rows read, as groups in the order their rows came.
     *
     * @param  array<array-key, mixed>  $rows
     * @param  \Closure(int): string  $key
     * @return list<SearchGroup>
     */
    private function groups(array $rows, bool $walk, bool $overview, \Closure $key): array
    {
        /** @var array<string, array{count: int, hits: list<SearchHit>}> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $group = $key(Cast::int($row['root_id'] ?? 0));
            $groups[$group] ??= ['count' => Cast::int($row['n'] ?? 0), 'hits' => []];
            $primary = $row['primary_id'] ?? null;
            $groups[$group]['hits'][] = new SearchHit(
                Cast::int($row['id'] ?? 0),
                Cast::string($row['ulid'] ?? ''),
                is_numeric($primary) ? (int) $primary : null,
                Cast::int($row['rn'] ?? 0),
            );
        }

        $out = [];
        foreach ($groups as $group => $found) {
            $hits = $found['hits'];
            $more = ! $overview && count($hits) > self::PAGE;
            if ($more) {
                array_pop($hits);
            }
            $out[] = new SearchGroup((string) $group, $found['count'], $walk, $hits, $overview ? $found['count'] > count($hits) : $more);
        }

        return $out;
    }

    /**
     * The SQL weight of a row (its worst word's best field, or CATEGORY_WEIGHT through a matched
     * category, whichever is better) and its accent score (how many accented words typed its title holds).
     *
     * @return array{0: string, 1: string, 2: list<string>}
     */
    private function scores(SearchText $q): array
    {
        $columns = array_map(static fn (int $i): string => "v.w{$i}", array_keys($q->words));
        $weight = 'least(case when '.$this->allWords($q).' then greatest('.implode(', ', $columns).') else '.self::NO_WEIGHT.' end,'
            .' case when v.in_cat then '.self::CATEGORY_WEIGHT.' else '.self::NO_WEIGHT.' end)';
        $accent = [];
        $bindings = [];
        foreach ($q->accented as $typed) {
            $accent[] = '(position(? in lower(v.title collate "vi-VN-x-icu")) > 0)::int';
            $bindings[] = $typed;
        }

        return [$weight, $accent === [] ? '0' : '('.implode(' + ', $accent).')', $bindings];
    }

    /** SQL: every word typed matched a stored word of the row. */
    private function allWords(SearchText $q): string
    {
        return implode(' and ', array_map(static fn (int $i): string => "v.w{$i} is not null", array_keys($q->words)));
    }

    /**
     * The condition one word typed puts on a word column: the same word, or for the last word typed
     * any word that starts with it (a range on collation "C", which an index serves under RLS).
     *
     * @param  literal-string  $column
     * @return array{0: literal-string, 1: list<string>}
     */
    private function match(string $column, SearchText $q, int $index): array
    {
        $word = $q->words[$index];
        if ($index === array_key_last($q->words)) {
            return ProductSearchWords::range($column, $word);
        }

        return ["{$column} = ?", [$word]];
    }

    /**
     * A PostgreSQL array value (`{1,2}`) read back as integers.
     *
     * @return list<int>
     */
    private static function intArray(mixed $value): array
    {
        if (! is_string($value) || $value === '{}') {
            return [];
        }

        return array_values(array_map(intval(...), array_filter(explode(',', trim($value, '{}')), is_numeric(...))));
    }

    /** @param list<int> $ids */
    private static function pgInts(array $ids): string
    {
        return '{'.implode(',', $ids).'}';
    }

    /** @param list<string> $values ulids (letters and digits only) */
    private static function pgTexts(array $values): string
    {
        return '{'.implode(',', array_filter($values, static fn (string $v): bool => preg_match('/^[0-9A-Za-z]+$/', $v) === 1)).'}';
    }
}
