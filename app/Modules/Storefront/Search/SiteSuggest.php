<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Search;

use App\Modules\Catalog\Support\ProductSearchWords;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\VietnameseFold;
use App\Modules\Storefront\Support\ThankYouPages;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Support\Facades\DB;

/**
 * What the suggestions under a search box may show while the visitor types (SearchSuggestions draws
 * them): the categories whose names hold every word typed, and the newest posts, pages and products
 * whose title (a product's name or code) holds them, ROWS of each at most. The same word tables and
 * rules as the search page (SiteSearch): every word must match a stored word, the last one its start,
 * accents or none; only what a visitor may open (published, not in the Trash, publish time reached,
 * translated in the language, never a form's thank-you page; a category with its page in the language).
 * Only title words (weight 1) count here, never a short description.
 *
 * - The rarest word decides how: when it has at most SiteSearch::GATHER rows, all its items are judged,
 *   else the newest visible items are walked (SiteSearch::EXAMINE at most, posts and pages as one
 *   stream) and the walk stops at the first ROWS that hold every word.
 * - A product whose code starts with what was typed (its letters and digits run together, as the code's
 *   words were stored) comes before the other products; a walk orders the newest ones it saw that way.
 *
 * Four statements at most (the word counts with the categories, the posts and pages, the products), each
 * naming the site (`site_id = ?`) besides RLS, each bounded; a row brings its title and its stored address.
 */
final readonly class SiteSuggest
{
    /** Most rows of each kind (and of the suggestions shown). */
    public const int ROWS = 5;

    public function __construct(
        private CurrentSite $current,
        private ThankYouPages $thankYou,
    ) {}

    /**
     * @return array{categories: list<int>, entries: list<array{id: int, title: string, path: string, at: string|null, root: int}>, products: list<array{id: int, title: string, sku: string, path: string, at: string|null}>}
     */
    public function find(SearchText $q, string $locale): array
    {
        if (! $q->searchable()) {
            return ['categories' => [], 'entries' => [], 'products' => []];
        }
        $plan = $this->plan($q, $locale);

        return [
            'categories' => $plan['categories'],
            'entries' => $this->entries($q, $locale, $plan['entries']),
            'products' => $this->products($q, $locale, $plan['products']),
        ];
    }

    /**
     * The typed text's letters and digits run together, folded (what a product code starts with), cut
     * like a stored word; '' when shorter than the shortest code ending stored.
     */
    public static function compact(string $text): string
    {
        $compact = mb_substr((string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '', VietnameseFold::text($text)), 0, ProductSearchWords::MAX_LENGTH);

        return mb_strlen($compact) >= ProductSearchWords::MIN_SKU_ENDING ? $compact : '';
    }

    /**
     * How many rows each word has in each table (up to GATHER + 1, any weight: an upper bound of its
     * title rows, read from the primary key alone), and the categories matched, in one statement.
     *
     * @return array{entries: list<int>, products: list<int>, categories: list<int>}
     */
    private function plan(SearchText $q, string $locale): array
    {
        $site = $this->current->id();
        $select = [];
        $b = [];
        foreach (['entry_search_words' => 'e', 'product_site_words' => 'p'] as $table => $alias) {
            foreach (array_keys($q->words) as $i) {
                [$cond, $values] = self::match('word', $q, $i);
                $select[] = "(select count(*) from (select 1 from {$table} where site_id = ? and locale = ? and {$cond} limit ?) as bounded) as {$alias}{$i}";
                $b = [...$b, $site, $locale, ...$values, SiteSearch::GATHER + 1];
            }
        }

        // the post and product categories whose own words hold every word typed (driven by the first), with a page in the language
        [$first, $values] = self::match('w.word', $q, 0);
        $cats = 'array(select w.taxonomy_id from taxonomy_search_words w'
            ." join taxonomies t on t.site_id = ? and t.id = w.taxonomy_id and t.vocabulary in ('post_cat', 'product_cat')"
            ." where w.site_id = ? and w.locale = ? and {$first}";
        $b = [...$b, $site, $site, $locale, ...$values];
        foreach (array_keys($q->words) as $i) {
            if ($i === 0) {
                continue;
            }
            [$also, $more] = self::match('o.word', $q, $i);
            $cats .= " and exists (select 1 from taxonomy_search_words o where o.site_id = ? and o.locale = ? and o.taxonomy_id = w.taxonomy_id and {$also})";
            $b = [...$b, $site, $locale, ...$more];
        }
        $cats .= ' and exists (select 1 from routes r where r.site_id = ? and r.locale = ? and r.taxonomy_id = w.taxonomy_id)'
            .' group by w.taxonomy_id, t.position order by t.position, w.taxonomy_id limit ?) as categories';
        $select[] = $cats;
        $b = [...$b, $site, $locale, self::ROWS];

        $row = (array) DB::selectOne('select '.implode(', ', $select), $b);
        $plan = ['entries' => [], 'products' => [], 'categories' => self::intArray($row['categories'] ?? null)];
        foreach (array_keys($q->words) as $i) {
            $plan['entries'][] = Cast::int($row["e{$i}"] ?? 0);
            $plan['products'][] = Cast::int($row["p{$i}"] ?? 0);
        }

        return $plan;
    }

    /**
     * The newest posts and pages whose title holds every word typed.
     *
     * @param  list<int>  $counts  each word's rows (plan)
     * @return list<array{id: int, title: string, path: string, at: string|null, root: int}>
     */
    private function entries(SearchText $q, string $locale, array $counts): array
    {
        if ($counts === [] || min($counts) === 0) {
            return [];
        }
        $site = $this->current->id();
        $rarest = (int) array_search(min($counts), $counts, true);
        $b = [];
        if ($counts[$rarest] > SiteSearch::GATHER) {
            // the newest visible posts and pages, as ONE stream in one order (the posts list index read backwards)
            $streams = [];
            foreach (['post', 'page'] as $type) {
                $streams[] = "(select e.id, e.published_at from entries e where e.site_id = ? and e.type = '{$type}' and e.deleted_at is null and e.status = 'published'"
                    .' and e.published_at <= now() order by e.published_at desc nulls last, e.id desc limit ?)';
                $b = [...$b, $site, SiteSearch::EXAMINE];
            }
            $source = '(select s.id, s.published_at from ('.implode(' union all ', $streams).') as s order by s.published_at desc nulls last, s.id desc limit ?) as c';
            $b[] = SiteSearch::EXAMINE;
            $order = 'c.published_at desc nulls last, c.id desc';
        } else {
            [$cond, $values] = self::match('w.word', $q, $rarest);
            $source = "(select distinct w.entry_id as id from entry_search_words w where w.site_id = ? and w.locale = ? and {$cond} and w.weight = 1) as c";
            $b = [...$b, $site, $locale, ...$values];
            $order = 'e.published_at desc nulls last, e.id desc';
        }

        $inner = "select e.id, e.published_at, t.title from {$source} join entries e on e.site_id = ? and e.id = c.id"
            ." join entry_translations t on t.entry_id = e.id and t.site_id = ? and t.locale = ? and t.status = 'published'"
            ." where e.deleted_at is null and e.status = 'published' and e.type in ('page', 'post')"
            .' and (e.published_at is null or e.published_at <= now()) and e.ulid::text <> all (?::text[])';
        $b = [...$b, $site, $site, $locale, self::pgTexts($this->thankYou->all())];
        foreach (array_keys($q->words) as $i) {
            [$cond, $values] = self::match('x.word', $q, $i);
            $inner .= " and exists (select 1 from entry_search_words x where x.site_id = ? and x.locale = ? and x.entry_id = e.id and x.weight = 1 and {$cond})";
            $b = [...$b, $site, $locale, ...$values];
        }
        $inner .= " order by {$order} limit ?";
        $b[] = self::ROWS;

        // for the few rows found: the address and the level-1 post category of the main category (the group)
        $sql = 'select x.id, x.published_at, x.title, (select r.path from routes r where r.site_id = ? and r.locale = ? and r.entry_id = x.id order by r.id limit 1) as path,'
            ." coalesce(root.id, 0) as root_id from ({$inner}) as x"
            .' left join lateral (select p.taxonomy_id from entry_taxonomies p where p.site_id = ? and p.entry_id = x.id and p.position = 0 order by p.id limit 1) as main on true'
            .' left join taxonomies pt on pt.site_id = ? and pt.id = main.taxonomy_id'
            ." left join taxonomies root on root.site_id = ? and root.id = coalesce(nullif(split_part(pt.path::text, '.', 1), '')::bigint, case when pt.parent_id is null then pt.id end) and root.vocabulary = 'post_cat'"
            .' order by x.published_at desc nulls last, x.id desc';
        $b = [$site, $locale, ...$b, $site, $site, $site];

        $out = [];
        foreach (DB::select($sql, $b) as $row) {
            $row = (array) $row;
            $path = Cast::stringOrNull($row['path'] ?? null);
            if ($path === null) {
                continue;   // no address in the language: nothing to open
            }
            $out[] = [
                'id' => Cast::int($row['id'] ?? 0),
                'title' => Cast::string($row['title'] ?? ''),
                'path' => $path,
                'at' => Cast::stringOrNull($row['published_at'] ?? null),
                'root' => Cast::int($row['root_id'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * The products whose name or code holds every word typed: those whose code starts with what was
     * typed first, then the newest.
     *
     * @param  list<int>  $counts  each word's rows (plan)
     * @return list<array{id: int, title: string, sku: string, path: string, at: string|null}>
     */
    private function products(SearchText $q, string $locale, array $counts): array
    {
        if ($counts === [] || min($counts) === 0) {
            return [];
        }
        $site = $this->current->id();
        $rarest = (int) array_search(min($counts), $counts, true);
        $compact = self::compact($q->text);
        $b = [];
        if ($counts[$rarest] > SiteSearch::GATHER) {
            // the newest visible products (the products list index), the code rows only among them
            $source = "(select p.id, p.published_at from products p where p.site_id = ? and p.status = 'published' and p.deleted_at is null"
                .' and p.published_at <= now() order by p.published_at desc, p.id desc limit ?) as c';
            $b = [...$b, $site, SiteSearch::EXAMINE];
            $newest = 'c.published_at desc, c.id desc';
        } else {
            [$cond, $values] = self::match('w.word', $q, $rarest);
            $source = "(select distinct w.product_id as id from product_site_words w where w.site_id = ? and w.locale = ? and {$cond} and w.weight = 1) as c";
            $b = [...$b, $site, $locale, ...$values];
            $newest = 'p.published_at desc nulls last, p.id desc';
        }
        // a code that starts with what was typed first (its letters and digits; SearchSuggestions folds accents too)
        $order = $compact === '' ? $newest : "starts_with(regexp_replace(lower(coalesce(p.sku, '')), '[^[:alnum:]]+', '', 'g'), ?) desc, {$newest}";

        $sql = "select p.id, p.published_at, p.sku, t.name, (select r.path from routes r where r.site_id = ? and r.locale = ? and r.product_id = p.id order by r.id limit 1) as path from {$source}"
            .' join products p on p.site_id = ? and p.id = c.id'
            .' join product_translations t on t.product_id = p.id and t.site_id = ? and t.locale = ?'
            ." where p.deleted_at is null and p.status = 'published' and (p.published_at is null or p.published_at <= now())";
        $b = [$site, $locale, ...$b, $site, $site, $locale];
        foreach (array_keys($q->words) as $i) {
            [$cond, $values] = self::match('x.word', $q, $i);
            $sql .= " and exists (select 1 from product_site_words x where x.site_id = ? and x.locale = ? and x.product_id = p.id and x.weight = 1 and {$cond})";
            $b = [...$b, $site, $locale, ...$values];
        }
        $sql .= " order by {$order} limit ?";
        if ($compact !== '') {
            $b[] = $compact;
        }
        $b[] = self::ROWS;

        $out = [];
        foreach (DB::select($sql, $b) as $row) {
            $row = (array) $row;
            $path = Cast::stringOrNull($row['path'] ?? null);
            if ($path === null) {
                continue;
            }
            $out[] = [
                'id' => Cast::int($row['id'] ?? 0),
                'title' => Cast::string($row['name'] ?? ''),
                'sku' => trim(Cast::string($row['sku'] ?? '')),
                'path' => $path,
                'at' => Cast::stringOrNull($row['published_at'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * The condition one word typed puts on a word column: the same word, or for the last word typed
     * any word that starts with it (a range on collation "C", which an index serves under RLS).
     *
     * @param  literal-string  $column
     * @return array{0: literal-string, 1: list<string>}
     */
    private static function match(string $column, SearchText $q, int $index): array
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

    /** @param list<string> $values ulids (letters and digits only) */
    private static function pgTexts(array $values): string
    {
        return '{'.implode(',', array_filter($values, static fn (string $v): bool => preg_match('/^[0-9A-Za-z]+$/', $v) === 1)).'}';
    }
}
