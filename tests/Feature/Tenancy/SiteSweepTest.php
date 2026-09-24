<?php

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Background sweeps (retention, scheduled publishing, view counters) reach every site through
 * Tenancy — never by loading the site registry: ids come by keyset, a chunk at a time, and
 * eachSite() runs the work inside that site's own tenant context.
 */

beforeEach(function (): void {
    seedTenancy();
    DB::connection('pgsql_admin')->table('sites')->insert([
        'ulid' => (string) Str::ulid(), 'tenant_id' => 1, 'name' => 'C', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('lists every site id in order across chunk boundaries, without entering a site', function (): void {
    expect(app(Tenancy::class)->siteIds(2)->all())->toBe([1, 2, 3])
        ->and(app(Tenancy::class)->siteIds(1)->all())->toBe([1, 2, 3])
        ->and(app(CurrentSite::class)->hasId())->toBeFalse();
});

it('runs the work inside each site\'s own tenant context, one chunk at a time', function (): void {
    $seen = [];

    $visited = app(Tenancy::class)->eachSite(function (int $siteId) use (&$seen): void {
        $seen[] = [
            $siteId,
            app(CurrentSite::class)->id(),
            (int) DB::selectOne("select current_setting('app.current_site_id') as id")->id,
        ];
    }, 2);

    expect($visited)->toBe(3)
        ->and($seen)->toBe([[1, 1, 1], [2, 2, 2], [3, 3, 3]])
        ->and(app(CurrentSite::class)->hasId())->toBeFalse();
});
