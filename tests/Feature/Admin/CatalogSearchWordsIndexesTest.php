<?php

use App\Modules\Admin\Support\Catalog\ProductListQuery;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * The products search reads the word table by its keys. The definitions are pinned here, and the
 * statements the list really sends for a search are planned against them as app_user, inside the
 * website's tenancy context (RLS on): only a leakproof condition can become an index condition there,
 * so a search rewritten into LIKE, a function on the column or a comparison in another collation
 * falls back to reading rows one by one and fails here. The test database is small, so the planner is
 * told to avoid a full table read (`enable_seqscan = off`); each test starts from the same analysed rows.
 */

beforeEach(function (): void {
    seedTenancy();
    $admin = DB::connection('pgsql_admin');
    // 3,000 products on each of the two websites; every product has a common word ("vong"), one word
    // of fifty ("loai7"), and its own code
    $admin->statement(<<<'SQL'
        insert into products (ulid, site_id, sku, status, created_at, updated_at)
        select lpad(s::text || '-' || g::text, 26, 'W'), s, 'WX-' || g, 'published',
               timestamptz '2026-12-01 00:00:00+00' - g * interval '1 minute',
               timestamptz '2026-12-01 00:00:00+00' - g * interval '1 minute'
        from generate_series(1, 2) s, generate_series(1, 3000) g
        SQL);
    $admin->statement(<<<'SQL'
        insert into product_search_words (site_id, product_id, word)
        select p.site_id, p.id, w
        from products p, lateral (values ('vong'), ('loai' || (p.id % 50)), ('wx' || p.id), ('ma' || p.id)) as v (w)
        SQL);
    $admin->statement('analyze products');
    $admin->statement('analyze product_search_words');
});

/**
 * Every statement the list sends while it reads its first page and its count for $search.
 *
 * @return list<array{sql: string, bindings: array<int, mixed>}>
 */
function searchStatements(string $search, int $gather = ProductListQuery::GATHER): array
{
    $sent = [];
    DB::listen(function (QueryExecuted $query) use (&$sent): void {
        $sent[] = ['sql' => $query->sql, 'bindings' => $query->connection->prepareBindings($query->bindings)];
    });
    $list = new ProductListQuery(1, 'all', $search, 'vi', gather: $gather);
    $list->rows('updated', 0, 21);
    $list->count($list->filtered());

    return $sent;
}

/**
 * [index name, index condition] of every index scan in the plan of $sql, and the tables read in full.
 *
 * @param  array<int, mixed>  $bindings
 * @return array{scans: list<array{0: string, 1: string}>, full: list<string>}
 */
function searchPlan(string $sql, array $bindings): array
{
    return DB::transaction(function () use ($sql, $bindings): array {
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = json_decode((string) array_values((array) DB::selectOne('EXPLAIN (FORMAT JSON) '.$sql, $bindings))[0], true);
        $scans = [];
        $full = [];
        $walk = function (array $node) use (&$walk, &$scans, &$full): void {
            if (isset($node['Index Name'])) {
                $scans[] = [(string) $node['Index Name'], (string) ($node['Index Cond'] ?? '')];
            } elseif (($node['Node Type'] ?? '') === 'Seq Scan') {
                $full[] = (string) $node['Relation Name'];
            }
            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($plan[0]['Plan']);

        return ['scans' => $scans, 'full' => $full];
    });
}

it('defines the keys of the word table as measured', function (string $index, string $definition): void {
    $row = DB::selectOne('select pg_get_indexdef(indexrelid) as def, indisvalid as valid from pg_index where indexrelid = to_regclass(?)', [$index]);

    expect($row)->not->toBeNull("{$index} is missing")
        ->and($row->valid)->toBeTrue()
        ->and($row->def)->toBe("CREATE {$definition}");
})->with([
    'a word range of one website' => ['product_search_words_pkey', 'UNIQUE INDEX product_search_words_pkey ON public.product_search_words USING btree (site_id, word, product_id)'],
    'the words of one product' => ['product_search_words_product_id_index', 'INDEX product_search_words_product_id_index ON public.product_search_words USING btree (product_id)'],
]);

it('reads the words of a search through the keys, never the whole table', function (string $search, int $gather, string $shape, array $probe, array $page = []): void {
    app(Tenancy::class)->enter(1, function () use ($search, $gather, $shape, $probe, $page): void {
        $statements = searchStatements($search, $gather);
        $words = array_values(array_filter($statements, fn (array $s): bool => str_contains($s['sql'], 'product_search_words')));

        // the word counts, the page and the count: three statements read the word table
        expect($words)->toHaveCount(3)
            ->and($words[0]['sql'])->toStartWith('select (select count(*) from (select 1 from product_search_words')
            ->and($words[1]['sql'])->toContain($shape)
            ->and($words[2]['sql'])->toContain($shape);
        $isRange = fn (array $scan): bool => $scan[0] === 'product_search_words_pkey' && str_contains($scan[1], 'word >=') && str_contains($scan[1], 'word <');

        // each word counted is a range of the key
        $counted = searchPlan($words[0]['sql'], $words[0]['bindings']);
        expect($counted['full'])->toBe([])
            ->and(array_values(array_filter($counted['scans'], $isRange)))->toHaveCount(count(explode(' ', $search)));

        // the page and the count read the words the way their shape says
        foreach (['page' => $words[1], 'count' => $words[2]] as $part => $statement) {
            $plan = searchPlan($statement['sql'], $statement['bindings']);
            $names = array_map(fn (array $scan): string => $scan[0], $plan['scans']);
            expect($plan['full'])->toBe([], $statement['sql']);
            foreach ($probe + ($part === 'page' ? $page : []) as $index => $condition) {
                $hits = array_filter($plan['scans'], fn (array $scan): bool => $scan[0] === $index && str_contains($scan[1], $condition));
                expect($hits)->not->toBe([], "{$index} ({$condition}) in ".implode(', ', $names));
            }
        }
    });
})->with([
    'a rare word: its products gathered by the key, read by id' => ['loai7', ProductListQuery::GATHER, '= any (array(select w.product_id', [
        'product_search_words_pkey' => 'word >=', 'products_pkey' => 'id = ANY',
    ]],
    'a code with a common word: the other word checked per product' => ['vong wx7', ProductListQuery::GATHER, '= any (array(select w.product_id', [
        'product_search_words_pkey' => 'word >=', 'product_search_words_product_id_index' => 'product_id = w.product_id', 'products_pkey' => 'id = ANY',
    ]],
    'only common words: the list walks its order and checks each row' => ['vong', 100, 'exists (select 1 from "product_search_words" as "w"', [
        'product_search_words_product_id_index' => 'product_id = products.id',
    ], ['products_live_updated_idx' => 'site_id']],
]);
