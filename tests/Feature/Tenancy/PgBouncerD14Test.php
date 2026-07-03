<?php

declare(strict_types=1);

use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;

/*
 * Gate #1 / D14 — PgBouncer transaction-mode + prepared statements. In transaction
 * pooling each autocommit parameterized query may land on a different pooled backend;
 * a broken prepared-statement setup raises "prepared statement ... does not exist".
 *
 * Meaningful through PgBouncer in the docker dev loop (app_user @ :6432). In CI it
 * runs against Postgres directly, where it acts as a regression guard.
 */

it('survives many parameterized queries under transaction pooling (D14)', function (): void {
    for ($i = 1; $i <= 60; $i++) {
        $row = DB::selectOne('select (?::int * ?::int) as n', [$i, 2]);

        expect((int) $row->n)->toBe($i * 2);
    }
});

it('survives parameterized queries interleaved with tenant transactions (D14)', function (): void {
    seedTenancy();
    $tenancy = app(Tenancy::class);

    foreach ([1, 2, 1, 2] as $site) {
        $tenancy->enter($site, function () use ($site): void {
            $row = DB::selectOne('select current_setting(?, true) as guc', ['app.current_site_id']);
            expect($row->guc)->toBe((string) $site);
        });

        // autocommit query between tenant transactions — forces backend rotation
        expect((int) DB::selectOne('select (?)::int as n', [$site])->n)->toBe($site);
    }
});
