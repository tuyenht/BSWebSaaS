<?php

use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * C1 — default-locale source of truth = site_locales.is_default (design.md §10/ADR-11).
 * SiteLocale::default() reads from site_locales.is_default (the sole source of truth),
 * memoized per-site for the request; a missing default falls back to the first enabled
 * locale then 'vi' with a warning. A partial-unique (one default/site) enforces the
 * invariant at the DB. Seeding uses the privileged connection (RLS bypass for setup).
 */

beforeEach(fn () => seedTenancy());

/**
 * Replace a site's site_locales with an exact set (privileged connection).
 *
 * @param  list<array{locale: string, is_default?: bool, enabled?: bool}>  $rows
 */
function setSiteLocales(int $site, array $rows): void
{
    $admin = DB::connection('pgsql_admin');
    $admin->table('site_locales')->where('site_id', $site)->delete();
    foreach ($rows as $row) {
        $admin->table('site_locales')->insert([
            'site_id' => $site,
            'locale' => $row['locale'],
            'is_default' => $row['is_default'] ?? false,
            'enabled' => $row['enabled'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

it('reads the default from site_locales.is_default', function () {
    setSiteLocales(1, [
        ['locale' => 'en', 'is_default' => true],
        ['locale' => 'vi', 'is_default' => false],
    ]);

    $locale = app(Tenancy::class)->enter(1, fn () => app(SiteLocale::class)->default());

    expect($locale)->toBe('en');
});

it('rejects a second default locale for a site (partial-unique)', function () {
    setSiteLocales(1, [['locale' => 'vi', 'is_default' => true]]);

    expect(fn () => DB::connection('pgsql_admin')->table('site_locales')->insert([
        'site_id' => 1, 'locale' => 'en', 'is_default' => true, 'enabled' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('allows each site its own default (partial-unique is per-site)', function () {
    setSiteLocales(1, [['locale' => 'vi', 'is_default' => true]]);
    setSiteLocales(2, [['locale' => 'vi', 'is_default' => true]]);

    expect(DB::connection('pgsql_admin')->table('site_locales')->where('is_default', true)->count())->toBe(2);
});

it('falls back to the first enabled locale and warns when no default is set', function () {
    setSiteLocales(1, [
        ['locale' => 'en', 'is_default' => false, 'enabled' => true],
        ['locale' => 'ja', 'is_default' => false, 'enabled' => true],
    ]);
    Log::spy();

    $locale = app(Tenancy::class)->enter(1, fn () => app(SiteLocale::class)->default());

    expect($locale)->toBe('en');   // first enabled, deterministic by id
    Log::shouldHaveReceived('warning')->once();
});

it('falls back to vi and warns when a site has no locales at all', function () {
    setSiteLocales(1, []);
    Log::spy();

    $locale = app(Tenancy::class)->enter(1, fn () => app(SiteLocale::class)->default());

    expect($locale)->toBe('vi');
    Log::shouldHaveReceived('warning')->once();
});

it('memoizes the default within a request (one query)', function () {
    setSiteLocales(1, [['locale' => 'vi', 'is_default' => true]]);

    $count = app(Tenancy::class)->enter(1, function () {
        $service = app(SiteLocale::class);

        return countQueries(function () use ($service) {
            $service->default();
            $service->default();
        });
    });

    expect($count)->toBe(1);
});

it('does not leak the memoized default across sites', function () {
    setSiteLocales(1, [['locale' => 'en', 'is_default' => true]]);
    setSiteLocales(2, [['locale' => 'ja', 'is_default' => true]]);
    $tenancy = app(Tenancy::class);

    $a = $tenancy->enter(1, fn () => app(SiteLocale::class)->default());
    $b = $tenancy->enter(2, fn () => app(SiteLocale::class)->default());

    expect($a)->toBe('en')->and($b)->toBe('ja');
});
