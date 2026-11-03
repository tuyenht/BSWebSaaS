<?php

use App\Modules\Admin\Support\Customers\CustomerDirectory;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Support\CustomerCode;
use Illuminate\Support\Facades\DB;

/*
 * Time budget of the customer search (design 03) at 50,000 customers, each with an owner (five
 * times the 10,000 customers the platform is built for): what the list reads for one search (the
 * page with its counts, one statement) as the median of five runs after a warm-up. The search
 * folds diacritics on the customer's name and its owner's name and email, with a pattern that can
 * start anywhere in the text.
 *
 * A narrow search and a search by the owner's email stay within 50 ms (measured 5–8 ms; 2.6 s
 * before the indexes). A wide search, matching at least a tenth of the customers, stays within
 * 250 ms (measured 190–230 ms; 2.4 s before): the trigram index must check every row it matched
 * again with the fold, about 115 ms for the 10,000 matches here, which no index can remove.
 *
 * A search by customer code (the unique index on customer_no), with or without its prefix, stays
 * within 50 ms (measured 3 ms). So does the list with no search (measured 33 ms: the page is picked
 * from tenants alone, then joined to its owners, and the counts are one GROUP BY) and the
 * "N trong M" totals a narrowed list adds (measured 13 ms).
 *
 * A search of one or two letters looks for word starts on the word indexes. Two fifths of every
 * customer ("an", "b") stay within 50 ms (measured 25–27 ms). One letter that starts a word of
 * every owner's name ("v": "Văn") matches every customer, a wide search: within 250 ms (measured
 * 205–209 ms).
 *
 * The fixture is vacuumed, as autovacuum keeps a live table: freshly loaded, the trigram indexes
 * still hold the new rows in their pending list and the heap the rows the previous run deleted,
 * which more than doubles the wide search and can turn the planner away from the indexes.
 */

beforeEach(function (): void {
    seedTenancy();
    $admin = DB::connection('pgsql_admin');
    $admin->statement("delete from users where email like 'kh-budget-%'");
    $admin->statement(<<<'SQL'
        insert into users (ulid, name, email, password, created_at, updated_at)
        select lpad(upper(to_hex(i)), 26, '0'), 'Chủ ' || (array['Nguyễn','Trần','Lê','Phạm','Hoàng'])[1 + i % 5] || ' Văn ' || i,
               'kh-budget-' || i || '@example.test', 'x', now(), now()
        from generate_series(1, 50000) as i
        SQL);
    $admin->statement(<<<'SQL'
        insert into tenants (ulid, name, status, settings, owner_user_id, contact_phone, created_at, updated_at)
        select lpad(upper(to_hex(u.n)), 26, 'K'), 'Công ty ' || (array['Đức Phát','Bình An','Tâm Đức','An Vinh','Hòa Bình'])[1 + u.n % 5] || ' ' || u.n,
               case when u.n % 10 = 0 then 'suspended' else 'active' end, '{}', u.id,
               case when u.n % 5 = 0 then null else '+849' || lpad(((u.n::bigint * 7919) % 100000000)::text, 8, '0') end,
               now() - make_interval(mins => u.n), now()
        from (select id, substring(email from 'kh-budget-([0-9]+)@')::int as n from users where email like 'kh-budget-%') as u
        SQL);
    $admin->statement('vacuum analyze users');
    $admin->statement('vacuum analyze tenants');
});

afterEach(function (): void {
    seedTenancy();
    $admin = DB::connection('pgsql_admin');
    $admin->statement("delete from users where email like 'kh-budget-%'");
    // fresh statistics, so later tests are not planned for 50,000 rows that are gone
    $admin->statement('analyze users');
    $admin->statement('analyze tenants');
});

/** Milliseconds of what the list reads for one search: the median of five runs after a warm-up. */
function khSearchMs(string $q): float
{
    $directory = app(CustomerDirectory::class);

    return khMedianMs(function () use ($directory, $q): void {
        $directory->page($q, '', [], 20, '', '');
    });
}

/** Milliseconds of the list's totals with neither search nor plans (what a narrowed list adds). */
function khCountsMs(): float
{
    $directory = app(CustomerDirectory::class);

    return khMedianMs(function () use ($directory): void {
        $directory->counts('', []);
    });
}

function khMedianMs(Closure $read): float
{
    $read();
    $times = [];
    for ($i = 0; $i < 5; $i++) {
        $start = hrtime(true);
        $read();
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    sort($times);

    return $times[2];
}

it('measures the search of 50,000 customers', function (): void {
    expect(DB::table('tenants')->count())->toBeGreaterThanOrEqual(50000);
    $no = (int) DB::table('tenants')->where('name', 'like', '% 31337')->value('customer_no');

    $narrow = khSearchMs('tam duc 4997');   // a few customers, by name without diacritics
    $owner = khSearchMs('kh-budget-31337@'); // one customer, by its owner's email
    $code = khSearchMs(CustomerCode::format($no)); // one customer, by its code
    $wide = khSearchMs('nguyen');          // a fifth of every customer, by the owner's name
    $none = khSearchMs('');                // no search: the first page of everyone
    $number = khSearchMs((string) $no);    // one customer, by its number without the prefix
    $two = khSearchMs('an');               // two letters: word starts, two fifths of every customer
    $one = khSearchMs('b');                // one letter: word starts, two fifths of every customer
    $every = khSearchMs('v');              // one letter every owner's name has a word for
    $base = khCountsMs();                  // the "N trong M" totals a narrowed list adds
    fwrite(STDERR, sprintf("\n  customer search at 50k: narrow %.1f ms · owner email %.1f ms · code %.1f ms · number %.1f ms · wide %.1f ms · none %.1f ms · 2 letters %.1f ms · 1 letter %.1f ms · 1 letter, everyone %.1f ms · totals %.1f ms\n",
        $narrow, $owner, $code, $number, $wide, $none, $two, $one, $every, $base));

    // The times above are measurements to read, not limits: they depend on the load of the machine
    // running them. What is asserted is the plan of the statements (the next test), which does not.
    expect([$narrow, $owner, $code, $wide, $none, $number, $two, $one, $every, $base])->each->toBeFloat();
});

/**
 * The plan Postgres picks for the statement the list sends for $q (the page with its counts), as
 * the nodes that read tenants or users: [node type, relation, index name].
 *
 * @return list<array{0: string, 1: string, 2: string|null}>
 */
function khPlanReads(string $q): array
{
    $page = null;
    DB::listen(function ($query) use (&$page): void {
        if (str_starts_with($query->sql, 'with customer_matches')) {
            $page = ['sql' => $query->sql, 'bindings' => $query->connection->prepareBindings($query->bindings)];
        }
    });
    app(CustomerDirectory::class)->page($q, '', [], 20, '', '');
    expect($page)->not->toBeNull('The list sent no page query.');

    $plan = (array) DB::selectOne('EXPLAIN (FORMAT JSON) '.$page['sql'], $page['bindings']);
    $reads = [];
    $walk = function (array $node) use (&$walk, &$reads): void {
        $relation = $node['Relation Name'] ?? null;
        if (in_array($relation, ['tenants', 'users'], true) || isset($node['Index Name'])) {
            $reads[] = [(string) $node['Node Type'], (string) ($relation ?? ''), $node['Index Name'] ?? null];
        }
        foreach ($node['Plans'] ?? [] as $child) {
            $walk($child);
        }
    };
    $decoded = json_decode((string) reset($plan), true);
    $walk($decoded[0]['Plan']);

    return $reads;
}

it('plans a one-letter search and a search by number on the indexes, never a full read of tenants or users', function (string $q): void {
    $no = (int) DB::table('tenants')->where('name', 'like', '% 31337')->value('customer_no');
    $reads = khPlanReads($q === '#' ? (string) $no : $q);

    $seq = fn (string $table): int => count(array_filter($reads, fn (array $r): bool => $r[0] === 'Seq Scan' && $r[1] === $table));
    $indexes = array_map(fn (array $r): ?string => $r[2], $reads);
    $report = 'Plan of "'.$q.'": '.json_encode($reads);

    // users are never read whole. tenants are read whole at most once: the join to the customers
    // of the owners found (a large share of them, by design), never a scan for the names.
    expect($reads)->not->toBe([])
        ->and($seq('users'))->toBe(0, $report)
        ->and($seq('tenants'))->toBeLessThanOrEqual($q === '#' ? 0 : 1, $report);
    if ($q === '#' || $q === '09 01 23') {
        // a number is also looked for in the customers' phones
        expect($indexes)->toContain('tenants_contact_phone_trgm_index');
    }
    if ($q !== '#') {
        // the word indexes on the folded names, of the owners and of the customers
        expect($indexes)->toContain('users_name_fold_words_index', 'tenants_name_fold_words_index');
    }
})->with(['one letter' => 'b', 'the letter every owner has' => 'v', 'a number' => '#', 'a phone in short groups' => '09 01 23']);

it('plans the whole list, newest first, on the order index: no read of every customer', function (string $status, bool $planned): void {
    $plans = [];
    if ($planned) {
        // one customer in three on the first plan, so the plan filter narrows the list
        $plan = Plan::query()->create(['key' => 'kh-budget', 'name' => 'Gói đo', 'is_active' => true, 'sort_order' => 1]);
        DB::connection('pgsql_admin')->statement("update tenants set plan_id = ? where ulid like 'K%' and customer_no % 3 = 0", [$plan->id]);
        DB::connection('pgsql_admin')->statement('analyze tenants');
        $plans = [$plan->key];
    }
    $sent = [];
    DB::listen(function ($query) use (&$sent): void {
        if (preg_match("/order by coalesce\\(tenants\\.created_at, '-infinity'\\) (asc|desc)/", $query->sql) === 1) {
            $sent[] = ['sql' => $query->sql, 'bindings' => $query->connection->prepareBindings($query->bindings)];
        }
    });
    $directory = app(CustomerDirectory::class);
    $first = $directory->page('', $status, $plans, 20, '', '');
    // an older page (the keyset after the first page's last row), then the newer page back from it
    $older = $directory->page('', $status, $plans, 20, (string) $first['older'], '');
    $directory->page('', $status, $plans, 20, '', (string) $older['newer']);
    expect($sent)->toHaveCount(3)
        ->and($first['rows'])->toHaveCount(20)
        ->and($older['rows'])->toHaveCount(20);

    foreach ($sent as $i => $statement) {
        $nodes = [];
        $walk = function (array $node) use (&$walk, &$nodes): void {
            $nodes[] = [(string) $node['Node Type'], (string) ($node['Relation Name'] ?? ''), (string) ($node['Index Name'] ?? ''), (string) ($node['Index Cond'] ?? '')];
            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child);
            }
        };
        $plan = (array) DB::selectOne('EXPLAIN (FORMAT JSON) '.$statement['sql'], $statement['bindings']);
        $walk(json_decode((string) reset($plan), true)[0]['Plan']);
        $report = (string) json_encode($nodes);
        $ordered = array_values(array_filter($nodes, fn (array $n): bool => $n[2] === 'tenants_listing_order_index'));

        expect($ordered)->not->toBe([], $report)
            ->and(array_filter($nodes, fn (array $n): bool => $n[0] === 'Seq Scan' && $n[1] === 'tenants'))->toBe([], $report);
        if ($i > 0) {
            // the keyset starts the index read where the page begins, not a filter over every row
            expect(str_contains($ordered[0][3], 'COALESCE(created_at'))->toBeTrue($report);
        }
    }
})->with([
    'every customer' => ['', false],
    'the active ones' => ['active', false],
    'the suspended ones' => ['suspended', false],
    'one plan' => ['', true],
]);

it('reads the whole list\'s totals behind a search once, not on every search typed', function (): void {
    $directory = app(CustomerDirectory::class);
    $first = $directory->baseCounts();
    DB::connection('pgsql_admin')->table('tenants')->where('name', 'like', '% 31337')->update(['status' => 'suspended']);

    $live = $directory->counts('', [])['groups'];

    expect($directory->baseCounts())->toBe($first)
        ->and($live['suspended'])->toBe($first['suspended'] + 1)
        ->and($first['all'])->toBeGreaterThanOrEqual(50000)
        ->and($first['all'])->toBe($first['active'] + $first['suspended']);
});
