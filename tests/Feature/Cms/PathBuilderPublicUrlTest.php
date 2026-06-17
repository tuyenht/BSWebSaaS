<?php

use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;

/*
 * C2 — PathBuilder::publicUrl is the single locale-prefix chokepoint (design.md §10/ADR-11).
 * The site's CURRENT default locale stays unprefixed (read dynamically from
 * site_locales.is_default — no hardcoded 'vi'); every other locale is prefixed /{locale},
 * with home '/' → '/{locale}' (no trailing slash). Input paths are UNPREFIXED.
 */

beforeEach(fn () => seedTenancy());   // seeds vi as each site's default

it('keeps the current default locale unprefixed', function () {
    [$category, $home] = app(Tenancy::class)->enter(1, function () {
        $paths = app(PathBuilder::class);

        return [$paths->publicUrl('/tin-tuc', 'vi'), $paths->publicUrl('/', 'vi')];
    });

    expect($category)->toBe('/tin-tuc')
        ->and($home)->toBe('/');
});

it('prefixes a non-default locale (home → /{locale}, no trailing slash)', function () {
    [$category, $home] = app(Tenancy::class)->enter(1, function () {
        $paths = app(PathBuilder::class);

        return [$paths->publicUrl('/tin-tuc', 'en'), $paths->publicUrl('/', 'en')];
    });

    expect($category)->toBe('/en/tin-tuc')
        ->and($home)->toBe('/en');
});

it('reads the default dynamically — flipping is_default flips the prefix', function () {
    // Make 'en' the site default via the source of truth (site_locales.is_default).
    $admin = DB::connection('pgsql_admin');
    $admin->table('site_locales')->where('site_id', 1)->update(['is_default' => false]);
    $admin->table('site_locales')->insert([
        'site_id' => 1, 'locale' => 'en', 'is_default' => true, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    [$en, $vi] = app(Tenancy::class)->enter(1, function () {
        $paths = app(PathBuilder::class);

        return [$paths->publicUrl('/tin-tuc', 'en'), $paths->publicUrl('/tin-tuc', 'vi')];
    });

    expect($en)->toBe('/tin-tuc')          // en is now default → unprefixed
        ->and($vi)->toBe('/vi/tin-tuc');   // vi is now non-default → prefixed
});
