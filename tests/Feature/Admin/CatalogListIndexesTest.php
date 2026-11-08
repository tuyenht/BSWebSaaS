<?php

use App\Modules\Admin\Livewire\Catalog\Index;
use App\Modules\Admin\Support\Catalog\ProductListQuery;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The products list and the nightly emptying of the product Trash each have an index on the order
 * they read in. The definitions are pinned here, and the queries the list and the purge really send
 * are planned against them as app_user, inside the website's tenancy context (RLS on). The test
 * database is small, so the planner is told to avoid a full table read (`enable_seqscan = off`)
 * and must then pick an index that can serve the query; each test starts from the same analysed
 * rows. A query rewritten so it no longer matches its index (the soft-delete predicate above all,
 * which a partial index matches only as written) fails here.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    $admin = DB::connection('pgsql_admin');
    $admin->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'catalog', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'module_key' => 'catalog', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    // 2,000 products on each of the two websites, a third in each status, edited over 2,000 hours,
    // one in ten in the Trash since a day of the last 40 (so a few are past the 30 days)
    $admin->statement(<<<'SQL'
        insert into products (ulid, site_id, sku, price, stock, status, created_at, updated_at, deleted_at)
        select lpad(s::text || '-' || g::text, 26, 'P'), s, 'IX-' || g, 1000 * g, g % 9,
               (array['published', 'draft', 'archived'])[1 + g % 3],
               timestamptz '2026-12-01 00:00:00+00' - g * interval '1 hour',
               timestamptz '2026-12-01 00:00:00+00' - g * interval '1 hour',
               case when g % 10 = 0 then timestamptz '2027-01-15 00:00:00+00' - (g % 40) * interval '1 day' end
        from generate_series(1, 2) s, generate_series(1, 2000) g
    SQL);
    $admin->statement('analyze products');
});

/**
 * The page query (`limit per + 1`) the list sent on its first render with these URL values.
 *
 * @param  array<string, mixed>  $params
 * @return array{sql: string, bindings: array<int, mixed>}
 */
function productPageQuery(array $params): array
{
    $manager = User::factory()->create();
    grantRole($manager, 'cat-index-manager', ['catalog.view', 'catalog.manage']);
    $page = null;
    DB::listen(function (QueryExecuted $query) use (&$page): void {
        if ($page === null && str_contains($query->sql, 'from "products"') && str_contains($query->sql, 'limit 21')) {
            $page = ['sql' => $query->sql, 'bindings' => $query->connection->prepareBindings($query->bindings)];
        }
    });

    Livewire::withQueryParams($params)->actingAs($manager->fresh())->test(Index::class)->assertOk();

    expect($page)->not->toBeNull('The list sent no page query.');

    return $page;
}

/**
 * The index scans Postgres plans for $sql, in the current tenancy context, when a full table read
 * is ruled out: [index name, index condition] each.
 *
 * @param  array<int, mixed>  $bindings
 * @return list<array{0: string, 1: string}>
 */
function productPlannedScans(string $sql, array $bindings): array
{
    return DB::transaction(function () use ($sql, $bindings): array {
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = json_decode((string) array_values((array) DB::selectOne('EXPLAIN (FORMAT JSON) '.$sql, $bindings))[0], true);
        $scans = [];
        $walk = function (array $node) use (&$walk, &$scans): void {
            if (isset($node['Index Name'])) {
                $scans[] = [(string) $node['Index Name'], (string) ($node['Index Cond'] ?? '')];
            }
            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($plan[0]['Plan']);

        return $scans;
    });
}

/** @param  array<int, mixed>  $bindings */
function productPlannedIndexes(string $sql, array $bindings): array
{
    return array_values(array_unique(array_map(fn (array $scan): string => $scan[0], productPlannedScans($sql, $bindings))));
}

it('defines each index of the products list as measured', function (string $index, string $definition): void {
    $row = DB::selectOne('select pg_get_indexdef(indexrelid) as def, indisvalid as valid from pg_index where indexrelid = to_regclass(?)', [$index]);

    expect($row)->not->toBeNull("{$index} is missing")
        ->and($row->valid)->toBeTrue()
        ->and($row->def)->toBe("CREATE INDEX {$index} ON public.products USING btree {$definition}");
})->with([
    'live products by last edit' => ['products_live_updated_idx', '(site_id, updated_at NULLS FIRST, id) WHERE (deleted_at IS NULL)'],
    'live products of one status by last edit' => ['products_live_status_idx', '(site_id, status, updated_at NULLS FIRST, id) WHERE (deleted_at IS NULL)'],
    'the Trash by deletion time' => ['products_trash_idx', '(site_id, deleted_at NULLS FIRST, id) WHERE (deleted_at IS NOT NULL)'],
]);

it('plans the first page of each group on its index', function (array $params, string $index): void {
    app(Tenancy::class)->enter(1, function () use ($params, $index): void {
        ['sql' => $sql, 'bindings' => $bindings] = productPageQuery($params);

        expect(productPlannedIndexes($sql, $bindings))->toBe([$index]);
    });
})->with([
    'Tất cả' => [[], 'products_live_updated_idx'],
    'Nháp' => [['tab' => 'draft'], 'products_live_status_idx'],
    'Lưu trữ' => [['tab' => 'archived'], 'products_live_status_idx'],
    'Thùng rác' => [['tab' => 'trash'], 'products_trash_idx'],
]);

it('plans a cursor page deep in the list with the cursor as a range of the index', function (string $tab, string $index): void {
    app(Tenancy::class)->enter(1, function () use ($tab, $index): void {
        $list = new ProductListQuery(1, $tab, '', 'vi');
        $at = $list->rows('updated', $tab === 'trash' ? 150 : 1500, 1)->first();
        $sent = [];
        DB::listen(function (QueryExecuted $query) use (&$sent): void {
            $sent[] = ['sql' => $query->sql, 'bindings' => $query->connection->prepareBindings($query->bindings)];
        });

        $rows = $list->rows('updated', 0, 21, ['v' => $at->getAttribute('__sv'), 'id' => $at->id]);

        // a full page from the rows with an edit time: one statement, and no "or no value" in it
        expect($rows)->toHaveCount(21)->and($sent)->toHaveCount(1)
            ->and($sent[0]['sql'])->not->toContain(' is null)');
        $scans = productPlannedScans($sent[0]['sql'], $sent[0]['bindings']);
        expect($scans)->toHaveCount(1)
            ->and($scans[0][0])->toBe($index)
            ->and($scans[0][1])->toContain('ROW(');
    });
})->with([
    'Tất cả' => ['all', 'products_live_updated_idx'],
    'Thùng rác' => ['trash', 'products_trash_idx'],
]);

it('plans the nightly purge of the Trash on the Trash index', function (): void {
    $this->travelTo(Carbon::parse('2027-01-15 10:00:00', 'UTC'));
    $chunk = null;
    DB::listen(function (QueryExecuted $query) use (&$chunk): void {
        if ($chunk === null && str_contains($query->sql, 'from "products"') && str_contains($query->sql, 'for update')) {
            $chunk = ['sql' => $query->sql, 'bindings' => $query->connection->prepareBindings($query->bindings)];
        }
    });

    $this->artisan('products:purge-trash')->assertSuccessful();

    expect($chunk)->not->toBeNull('The purge sent no chunk query.');
    app(Tenancy::class)->enter(1, function () use ($chunk): void {
        expect(productPlannedIndexes($chunk['sql'], $chunk['bindings']))->toBe(['products_trash_idx']);
    });
});
