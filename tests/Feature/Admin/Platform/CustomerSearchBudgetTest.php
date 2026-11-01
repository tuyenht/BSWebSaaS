<?php

use App\Modules\Admin\Support\Customers\CustomerDirectory;
use Illuminate\Support\Facades\DB;

/*
 * Time budget of the customer search (design 03) at 50,000 customers, each with an owner: what the
 * list reads for one search — the page, the group counts and the plan counts — stays within
 * 50 ms (the median of five runs, after a warm-up). The search folds diacritics on the customer's
 * name and its owner's name and email, with a pattern that can start anywhere in the text.
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
    $admin->statement('analyze users');
    $admin->statement('analyze tenants');
});

afterEach(function (): void {
    seedTenancy();
    DB::connection('pgsql_admin')->statement("delete from users where email like 'kh-budget-%'");
});

/** Milliseconds of what the list reads for one search: the median of five runs after a warm-up. */
function khSearchMs(string $q): float
{
    $directory = app(CustomerDirectory::class);
    $read = function () use ($directory, $q): void {
        $directory->page($q, '', [], 20, '', '');
        $directory->groupCounts($q, []);
        $directory->planCounts($q);
    };
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

it('searches 50,000 customers within 50 ms', function (): void {
    expect(DB::table('tenants')->count())->toBeGreaterThanOrEqual(50000);

    $narrow = khSearchMs('tam duc 4997');   // a few customers, by name without diacritics
    $owner = khSearchMs('kh-budget-31337@'); // one customer, by its owner's email
    $wide = khSearchMs('nguyen');          // a fifth of every customer, by the owner's name
    fwrite(STDERR, sprintf("\n  customer search at 50k: narrow %.1f ms · owner email %.1f ms · wide %.1f ms\n", $narrow, $owner, $wide));

    expect($narrow)->toBeLessThanOrEqual(50.0)
        ->and($owner)->toBeLessThanOrEqual(50.0)
        ->and($wide)->toBeLessThanOrEqual(50.0);
});
