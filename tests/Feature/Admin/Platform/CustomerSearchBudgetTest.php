<?php

use App\Modules\Admin\Support\Customers\CustomerDirectory;
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
 * A search by customer code (the unique index on customer_no) stays within 50 ms (measured 3 ms).
 * So does the list with no search (measured 36 ms: the page is picked from tenants alone, then
 * joined to its owners, and the counts are one GROUP BY), a search too short to run (the same
 * read) and the "N trong M" totals a narrowed list adds (measured 16 ms).
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
        insert into tenants (ulid, name, status, settings, owner_user_id, created_at, updated_at)
        select lpad(upper(to_hex(u.n)), 26, 'K'), 'Công ty ' || (array['Đức Phát','Bình An','Tâm Đức','An Vinh','Hòa Bình'])[1 + u.n % 5] || ' ' || u.n,
               case when u.n % 10 = 0 then 'suspended' else 'active' end, '{}', u.id,
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

it('searches 50,000 customers within 50 ms, a wide search within 250 ms', function (): void {
    expect(DB::table('tenants')->count())->toBeGreaterThanOrEqual(50000);
    $no = (int) DB::table('tenants')->where('name', 'like', '% 31337')->value('customer_no');

    $narrow = khSearchMs('tam duc 4997');   // a few customers, by name without diacritics
    $owner = khSearchMs('kh-budget-31337@'); // one customer, by its owner's email
    $code = khSearchMs(CustomerCode::format($no)); // one customer, by its code
    $wide = khSearchMs('nguyen');          // a fifth of every customer, by the owner's name
    $none = khSearchMs('');                // no search: the first page of everyone
    $short = khSearchMs('an');             // too short to run: the same as no search
    $base = khCountsMs();                  // the "N trong M" totals a narrowed list adds
    fwrite(STDERR, sprintf("\n  customer search at 50k: narrow %.1f ms · owner email %.1f ms · code %.1f ms · wide %.1f ms · none %.1f ms · 2 letters %.1f ms · totals %.1f ms\n",
        $narrow, $owner, $code, $wide, $none, $short, $base));

    expect($narrow)->toBeLessThanOrEqual(50.0)
        ->and($owner)->toBeLessThanOrEqual(50.0)
        ->and($code)->toBeLessThanOrEqual(50.0)
        ->and($wide)->toBeLessThanOrEqual(250.0)
        ->and($none)->toBeLessThanOrEqual(50.0)
        ->and($short)->toBeLessThanOrEqual(50.0)
        ->and($base)->toBeLessThanOrEqual(50.0);
});
