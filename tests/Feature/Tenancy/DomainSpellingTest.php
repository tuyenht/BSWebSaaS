<?php

use App\Modules\Tenancy\Actions\AttachDomain;
use App\Modules\Tenancy\Actions\CheckDomain;
use App\Modules\Tenancy\Actions\ChooseRunningSpelling;
use App\Modules\Tenancy\Actions\EnableHttps;
use App\Modules\Tenancy\Actions\SetPrimaryDomain;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Exceptions\DomainChangeRejectedException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Fixtures\FakeDns;

/*
 * One name, two spellings (design.md §28, owner 03/10): `shop.example` and `www.shop.example`
 * come together; the owner picks the one that runs — by default the one without `www.` — and the
 * other answers 301 to it on every page, admin included, path and query kept.
 */

beforeEach(function (): void {
    seedTenancy();
    seedHomepage(1);
    Cache::flush();
    foreach (['shop.example', 'www.shop.example'] as $host) {
        RateLimiter::clear('domain-https:'.$host);
    }
    config(['tenancy.custom_domains.target' => 'target.platform.test', 'tenancy.base_domains' => ['platform.test']]);
    $this->dns = FakeDns::install();
    Queue::fake();
});

function spOnSite(Closure $run, int $siteId = 1): mixed
{
    return app(Tenancy::class)->enter($siteId, $run);
}

function spReason(Closure $run): ?string
{
    try {
        $run();
    } catch (DomainChangeRejectedException $e) {
        return $e->reason;
    }

    return null;
}

function spRow(string $host): ?object
{
    return DB::connection('pgsql_admin')->table('domains')->where('hostname', $host)->first();
}

/** Both spellings attached and pointed here; the one without www runs unless $useWww. */
function spLivePair(FakeDns $dns, bool $useWww = false): void
{
    $dns->pointHere('shop.example', 'www.shop.example');
    spOnSite(function () use ($useWww): void {
        app(AttachDomain::class)->handle('shop.example', null, $useWww);
        app(CheckDomain::class)->handle('shop.example');
    });
}

/** HTTPS on for both spellings, and the running one made the site's primary domain. */
function spPrimaryPair(FakeDns $dns, bool $useWww = false): void
{
    spLivePair($dns, $useWww);
    DB::connection('pgsql_admin')->table('domains')->where('kind', 'custom')->update(['ssl_status' => 'active']);
    spOnSite(fn () => app(SetPrimaryDomain::class)->handle($useWww ? 'www.shop.example' : 'shop.example'));
    Cache::flush();
}

it('verifies both spellings with one check, and reports the twin alongside', function (): void {
    spLivePair($this->dns);

    expect(spRow('shop.example')->verified_at)->not->toBeNull()
        ->and(spRow('www.shop.example')->verified_at)->not->toBeNull()
        ->and(spRow('www.shop.example')->is_redirect)->toBeTrue();
});

it('lets the twin lag behind: the name runs while its www is not pointed yet', function (): void {
    $this->dns->pointHere('shop.example');

    $check = spOnSite(function () {
        app(AttachDomain::class)->handle('shop.example');

        return app(CheckDomain::class)->handle('shop.example');
    });

    expect($check->verified())->toBeTrue()
        ->and($check->twin?->status)->toBe('not_pointing')
        ->and(app(SiteResolver::class)->resolve('shop.example')?->siteId)->toBe(1);
});

it('turns HTTPS on for both spellings at once, skipping a twin that is not verified', function (): void {
    spLivePair($this->dns);

    expect(spOnSite(fn () => app(EnableHttps::class)->handle('shop.example')))->toBe(['shop.example', 'www.shop.example'])
        ->and(spRow('www.shop.example')->ssl_status)->toBe('pending');
});

it('finishes HTTPS for a twin whose name already had it', function (): void {
    spLivePair($this->dns);
    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')->update(['ssl_status' => 'active']);

    expect(spOnSite(fn () => app(EnableHttps::class)->handle('shop.example')))->toBe(['www.shop.example']);
});

it('sends every page of the redirecting spelling to the one that runs, path and query kept', function (string $path, string $location): void {
    spPrimaryPair($this->dns);

    $this->get('http://www.shop.example'.$path)->assertStatus(301)->assertHeader('Location', $location);
})->with([
    'a public page' => ['/gioi-thieu?utm=x', 'http://shop.example/gioi-thieu?utm=x'],
    'the admin' => ['/admin/login', 'http://shop.example/admin/login'],
]);

it('runs the www spelling when the owner chose it, and sends the bare name there', function (): void {
    spPrimaryPair($this->dns, useWww: true);

    $this->get('http://shop.example/gioi-thieu')->assertStatus(301)->assertHeader('Location', 'http://www.shop.example/gioi-thieu');
    $this->get('http://www.shop.example/')->assertOk();
});

it('sends public pages straight to the site\'s primary, and the admin to the running spelling', function (): void {
    spLivePair($this->dns);   // a.test stays the site's primary

    $this->get('http://www.shop.example/gioi-thieu')->assertStatus(301)->assertHeader('Location', 'http://a.test/gioi-thieu');
    $this->get('http://www.shop.example/admin/login')->assertStatus(301)->assertHeader('Location', 'http://shop.example/admin/login');
    $this->get('http://shop.example/admin/login')->assertOk();
});

it('lets the redirecting spelling answer itself while the running one cannot', function (): void {
    spLivePair($this->dns);
    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')->update(['failing_since' => now()]);
    Cache::flush();

    $this->get('http://www.shop.example/admin/login')->assertOk();
});

it('never redirects a form post', function (): void {
    spPrimaryPair($this->dns);

    expect($this->post('http://www.shop.example/forms/contact', [])->getStatusCode())->not->toBe(301);
});

it('switches which spelling runs, and the primary follows when it was the primary', function (): void {
    spPrimaryPair($this->dns);

    spOnSite(fn () => app(ChooseRunningSpelling::class)->handle('www.shop.example'));

    expect(spRow('www.shop.example'))->is_redirect->toBeFalse()->is_primary->toBeTrue()
        ->and(spRow('shop.example'))->is_redirect->toBeTrue()->is_primary->toBeFalse();

    $this->get('http://shop.example/gioi-thieu')->assertStatus(301)->assertHeader('Location', 'http://www.shop.example/gioi-thieu');
    $this->get('http://www.shop.example/')->assertOk();
});

it('refuses to hand the primary to a spelling without HTTPS, changing nothing', function (): void {
    spPrimaryPair($this->dns);
    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'www.shop.example')->update(['ssl_status' => null]);

    expect(spReason(fn () => spOnSite(fn () => app(ChooseRunningSpelling::class)->handle('www.shop.example'))))->toBe('https_not_on')
        ->and(spRow('shop.example')->is_primary)->toBeTrue()
        ->and(spRow('www.shop.example')->is_redirect)->toBeTrue();
});

it('switches freely while the pair is not the site\'s primary', function (): void {
    spLivePair($this->dns);

    spOnSite(fn () => app(ChooseRunningSpelling::class)->handle('www.shop.example'));

    expect(spRow('www.shop.example')->is_redirect)->toBeFalse()
        ->and(spRow('shop.example')->is_redirect)->toBeTrue()
        ->and(spRow('a.test')->is_primary)->toBeTrue();
});

it('never makes the redirecting spelling the site\'s primary', function (): void {
    spLivePair($this->dns);
    DB::connection('pgsql_admin')->table('domains')->where('kind', 'custom')->update(['ssl_status' => 'active']);

    expect(spReason(fn () => spOnSite(fn () => app(SetPrimaryDomain::class)->handle('www.shop.example'))))->toBe('redirect_only');
});

it('enforces in the database that a redirect is neither primary nor a platform subdomain', function (): void {
    $admin = DB::connection('pgsql_admin');

    expect(fn () => $admin->table('domains')->where('hostname', 'a.test')->update(['is_redirect' => true]))
        ->toThrow(QueryException::class);
});

it('gives existing pairs the default when the column arrives: www redirects unless www is the primary', function (): void {
    $admin = DB::connection('pgsql_admin');
    $admin->table('domains')->where('site_id', 2)->update(['is_primary' => false]);

    foreach ([
        [1, 'shop.example', false], [1, 'www.shop.example', false],
        [2, 'brand.example', false], [2, 'www.brand.example', true],
    ] as [$site, $host, $primary]) {
        $admin->table('domains')->insert([
            'site_id' => $site, 'hostname' => $host, 'kind' => 'custom', 'is_primary' => $primary,
            'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @var Migration $migration */
    $migration = require database_path('migrations/2032_01_01_000000_add_redirect_flag_to_domains.php');
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');

    try {
        $migration->up();   // re-runnable: the column exists, only the default is applied
    } finally {
        DB::setDefaultConnection($original);
    }

    expect($admin->table('domains')->where('is_redirect', true)->orderBy('hostname')->pluck('hostname')->all())
        ->toBe(['brand.example', 'www.shop.example']);
});
