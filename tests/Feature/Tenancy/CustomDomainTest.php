<?php

use App\Modules\Tenancy\Actions\AttachDomain;
use App\Modules\Tenancy\Actions\CheckDomain;
use App\Modules\Tenancy\Actions\RemoveDomain;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Domains\DomainCheck;
use App\Modules\Tenancy\Domains\DomainName;
use App\Modules\Tenancy\Domains\OwnershipProof;
use App\Modules\Tenancy\Domains\PointingMethod;
use App\Modules\Tenancy\Exceptions\DomainChangeRejectedException;
use App\Modules\Tenancy\Models\SiteFeature;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\FakeDns;

/*
 * A customer's own domain on a site (design.md §28): attaching only makes it WAIT; pointing it at
 * the platform verifies it; a name two sites claim goes to the one that proves ownership by a TXT
 * record, and the other loses it; removing it never leaves the site without a primary domain.
 */

beforeEach(function (): void {
    seedTenancy();
    Cache::flush();
    config(['tenancy.custom_domains.target' => 'target.platform.test', 'tenancy.base_domains' => ['platform.test']]);
    $this->dns = FakeDns::install();
});

function cdOnSite(int $siteId, Closure $run): mixed
{
    return app(Tenancy::class)->enter($siteId, $run);
}

/** @return list<string> */
function cdAttach(int $siteId, string $input, ?PointingMethod $pointing = null, bool $useWww = false): array
{
    return cdOnSite($siteId, fn (): array => array_map(
        fn ($row): string => $row->hostname,
        app(AttachDomain::class)->handle($input, $pointing, $useWww),
    ));
}

function cdCheck(int $siteId, string $host): DomainCheck
{
    return cdOnSite($siteId, fn (): DomainCheck => app(CheckDomain::class)->handle($host));
}

function cdRow(int $siteId, string $host): ?object
{
    return DB::connection('pgsql_admin')->table('domains')->where('site_id', $siteId)->where('hostname', $host)->first();
}

function cdReason(Closure $run): ?string
{
    try {
        $run();
    } catch (DomainChangeRejectedException $e) {
        return $e->reason;
    }

    return null;
}

// ── The name ────────────────────────────────────────────────────────────────────────────────

it('stores what a person typed as a plain hostname', function (string $typed, string $stored): void {
    expect(DomainName::parse($typed))->toBe($stored);
})->with([
    'plain' => ['shop.example', 'shop.example'],
    'upper case, spaces' => ['  Shop.Example ', 'shop.example'],
    'a pasted address' => ['https://shop.example/lien-he?x=1#top', 'shop.example'],
    'with a port' => ['shop.example:8443', 'shop.example'],
    'trailing dot' => ['shop.example.', 'shop.example'],
    'diacritics become punycode' => ['Công-Ty.vn', 'xn--cng-ty-ixa.vn'],
]);

it('refuses what is not a domain name', function (string $typed): void {
    expect(cdReason(fn () => DomainName::parse($typed)))->toBe('invalid');
})->with(['', 'localhost', '203.0.113.7', 'user@shop.example', '*.shop.example', 'shop_1.example', 'http://']);

it('never lets a customer attach a name that belongs to the platform', function (string $host): void {
    config(['tenancy.platform_host' => 'console.platform.test']);

    expect(cdReason(fn () => cdAttach(1, $host)))->toBe('reserved')
        ->and(DB::connection('pgsql_admin')->table('domains')->where('kind', 'custom')->count())->toBe(0);
})->with([
    'the console host' => ['console.platform.test'],
    'the host customers point at' => ['target.platform.test'],
    'a platform base domain' => ['platform.test'],
    'any name under a base domain' => ['someone-else.platform.test'],
    'a reserved label under a base domain' => ['admin.platform.test'],
]);

// ── Attaching ───────────────────────────────────────────────────────────────────────────────

it('attaches a domain as waiting: not verified, not primary, not resolving', function (): void {
    expect(cdAttach(1, 'shop.example', PointingMethod::ARecord))->toBe(['shop.example', 'www.shop.example']);

    $row = cdRow(1, 'shop.example');

    expect($row->kind)->toBe('custom')
        ->and($row->verified_at)->toBeNull()
        ->and($row->is_primary)->toBeFalse()
        ->and($row->ssl_status)->toBeNull()
        ->and($row->pointing_method)->toBe('a_record')
        ->and(app(SiteResolver::class)->resolve('shop.example'))->toBeNull();
});

it('attaches both spellings of any name; the one without www runs unless the owner chose www', function (string $typed, bool $useWww, array $attached): void {
    expect(cdAttach(1, $typed, useWww: $useWww))->toBe($attached);

    $redirects = DB::connection('pgsql_admin')->table('domains')->where('site_id', 1)->where('is_redirect', true)->pluck('hostname')->all();

    expect($redirects)->toBe([$attached[1]]);
})->with([
    'root, default' => ['example.vn', false, ['example.vn', 'www.example.vn']],
    'www typed, default' => ['www.example.vn', false, ['example.vn', 'www.example.vn']],
    'root, www chosen' => ['example.vn', true, ['www.example.vn', 'example.vn']],
    'subdomain, default' => ['shop.example.vn', false, ['shop.example.vn', 'www.shop.example.vn']],
    'subdomain, www chosen' => ['shop.example.vn', true, ['www.shop.example.vn', 'shop.example.vn']],
]);

it('refuses a domain the site already has', function (): void {
    cdAttach(1, 'shop.example');

    expect(cdReason(fn () => cdAttach(1, 'SHOP.example')))->toBe('already_attached');
});

it('stops at the plan limit, counting a name and its www as one', function (): void {
    cdAttach(1, 'example.vn');

    expect(cdReason(fn () => cdAttach(1, 'second.example')))->toBe('limit_reached');

    cdOnSite(1, fn () => SiteFeature::query()->updateOrCreate(['feature_key' => 'custom_domains.max'], ['value' => 2]));

    expect(cdAttach(1, 'second.example'))->toBe(['second.example', 'www.second.example'])
        ->and(cdReason(fn () => cdAttach(1, 'third.example')))->toBe('limit_reached');
});

it('refuses the other spelling of a name the site already has', function (): void {
    cdAttach(1, 'example.vn');

    expect(cdReason(fn () => cdAttach(1, 'www.example.vn', useWww: true)))->toBe('already_attached');
});

it('lets two sites wait for the same name', function (): void {
    cdAttach(1, 'shop.example');

    expect(cdAttach(2, 'shop.example'))->toBe(['shop.example', 'www.shop.example']);
});

// ── Checking ────────────────────────────────────────────────────────────────────────────────

it('verifies a domain that points at the platform, and it starts resolving', function (): void {
    cdAttach(1, 'shop.example');
    $this->dns->pointHere('shop.example');

    $check = cdCheck(1, 'shop.example');

    expect($check->verified())->toBeTrue()
        ->and($check->evictedSiteIds)->toBe([])
        ->and(cdRow(1, 'shop.example')->verified_at)->not->toBeNull()
        ->and(app(SiteResolver::class)->resolve('shop.example')?->siteId)->toBe(1);
});

it('forgets the remembered miss when a domain is verified', function (): void {
    cdAttach(1, 'shop.example');
    app(SiteResolver::class)->resolve('shop.example');   // remembered as unknown
    $this->dns->pointHere('shop.example');

    cdCheck(1, 'shop.example');

    expect(app(SiteResolver::class)->resolve('shop.example')?->siteId)->toBe(1);
});

it('does not verify a domain that does not point at the platform', function (array $addresses, string $status): void {
    cdAttach(1, 'shop.example');
    $this->dns->addresses['shop.example'] = $addresses;

    $check = cdCheck(1, 'shop.example');
    $row = cdRow(1, 'shop.example');

    expect($check->status)->toBe($status)
        ->and($row->verified_at)->toBeNull()
        ->and($row->failure_reason)->toBe($status)
        ->and($row->checked_at)->not->toBeNull()
        ->and(app(SiteResolver::class)->resolve('shop.example'))->toBeNull();
})->with([
    'resolves to nothing' => [[], 'not_pointing'],
    'resolves to another server' => [['198.51.100.9'], 'points_elsewhere'],
    'an old IPv6 record still points away' => [[FakeDns::PLATFORM_IP, '2001:db8::1'], 'points_elsewhere'],
]);

it('refuses to check a domain the site does not have', function (): void {
    cdAttach(2, 'shop.example');

    expect(cdReason(fn () => cdCheck(1, 'shop.example')))->toBe('not_found');
});

it('asks for proof when another site also waits for the name, however early this site attached it', function (): void {
    cdAttach(1, 'shop.example');
    cdAttach(2, 'shop.example');
    $this->dns->pointHere('shop.example');

    $check = cdCheck(2, 'shop.example');

    expect($check->status)->toBe('needs_proof')
        ->and($check->txtName)->toBe('_site-verification.shop.example')
        ->and($check->txtValue)->toBe(app(OwnershipProof::class)->recordValue(2, 'shop.example'))
        ->and(cdRow(2, 'shop.example')->verified_at)->toBeNull()
        ->and(cdCheck(1, 'shop.example')->status)->toBe('needs_proof');
});

it('gives a contested name to the site that publishes its TXT record, and the other loses it', function (): void {
    cdAttach(1, 'shop.example');
    cdAttach(2, 'shop.example');
    $this->dns->pointHere('shop.example');
    $this->dns->txt['_site-verification.shop.example'] = [app(OwnershipProof::class)->recordValue(2, 'shop.example')];

    expect(cdCheck(1, 'shop.example')->status)->toBe('needs_proof');   // site 1's value is not the one published

    $check = cdCheck(2, 'shop.example');

    expect($check->verified())->toBeTrue()
        ->and($check->evictedSiteIds)->toBe([1])
        ->and(cdRow(1, 'shop.example'))->toBeNull()
        ->and(app(SiteResolver::class)->resolve('shop.example')?->siteId)->toBe(2);
});

it('lets the real owner take a live domain back with the TXT record', function (): void {
    // Site 1 got the name first and made it its primary domain.
    cdAttach(1, 'shop.example');
    $this->dns->pointHere('shop.example');
    cdCheck(1, 'shop.example');
    DB::connection('pgsql_admin')->table('domains')->where('site_id', 1)->update(['is_primary' => false]);
    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')->update(['is_primary' => true]);
    app(SiteResolver::class)->resolve('shop.example');   // remembered as site 1

    cdAttach(2, 'shop.example');
    expect(cdCheck(2, 'shop.example')->status)->toBe('needs_proof');

    $this->dns->txt['_site-verification.shop.example'] = [app(OwnershipProof::class)->recordValue(2, 'shop.example')];
    $check = cdCheck(2, 'shop.example');

    expect($check->evictedSiteIds)->toBe([1])
        ->and(app(SiteResolver::class)->resolve('shop.example')?->siteId)->toBe(2)
        ->and(cdRow(1, 'shop.example'))->toBeNull()
        ->and(cdRow(1, 'a.test')->is_primary)->toBeTrue();   // site 1 falls back to its subdomain
});

it('keeps a live domain with its site when a newcomer merely waits for it', function (): void {
    cdAttach(1, 'shop.example');
    $this->dns->pointHere('shop.example');
    cdCheck(1, 'shop.example');
    cdAttach(2, 'shop.example');

    expect(cdCheck(1, 'shop.example')->verified())->toBeTrue()
        ->and(app(SiteResolver::class)->resolve('shop.example')?->siteId)->toBe(1);
});

it('gives each site and each domain its own proof value', function (): void {
    $proof = app(OwnershipProof::class);

    expect($proof->recordValue(1, 'shop.example'))->not->toBe($proof->recordValue(2, 'shop.example'))
        ->and($proof->recordValue(1, 'shop.example'))->not->toBe($proof->recordValue(1, 'other.example'))
        ->and($proof->recordValue(1, 'shop.example'))->toMatch('/^[a-f0-9]{40}$/');
});

it('marks a live domain as failing when it stops pointing here, without unverifying it', function (): void {
    cdAttach(1, 'shop.example');
    $this->dns->pointHere('shop.example');
    cdCheck(1, 'shop.example');

    $this->dns->addresses['shop.example'] = ['198.51.100.9'];
    cdCheck(1, 'shop.example');
    $row = cdRow(1, 'shop.example');

    expect($row->verified_at)->not->toBeNull()
        ->and($row->failing_since)->not->toBeNull()
        ->and($row->failure_reason)->toBe('points_elsewhere');

    $this->dns->pointHere('shop.example');
    cdCheck(1, 'shop.example');

    expect(cdRow(1, 'shop.example')->failing_since)->toBeNull();
});

// ── Removing ────────────────────────────────────────────────────────────────────────────────

it('removes a name together with its www, and they stop resolving', function (): void {
    cdAttach(1, 'example.vn');
    $this->dns->pointHere('example.vn', 'www.example.vn');
    cdCheck(1, 'example.vn');

    $removed = cdOnSite(1, fn (): array => app(RemoveDomain::class)->handle('www.example.vn'));

    expect($removed)->toEqualCanonicalizing(['example.vn', 'www.example.vn'])
        ->and(app(SiteResolver::class)->resolve('example.vn'))->toBeNull()
        ->and(app(SiteResolver::class)->resolve('www.example.vn'))->toBeNull();
});

it('makes the platform subdomain primary again when the primary domain is removed', function (): void {
    cdAttach(1, 'shop.example');
    $this->dns->pointHere('shop.example');
    cdCheck(1, 'shop.example');
    DB::connection('pgsql_admin')->table('domains')->where('site_id', 1)->update(['is_primary' => false]);
    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')->update(['is_primary' => true]);

    cdOnSite(1, fn (): array => app(RemoveDomain::class)->handle('shop.example'));

    expect(cdRow(1, 'a.test')->is_primary)->toBeTrue()
        ->and(DB::connection('pgsql_admin')->table('domains')->where('site_id', 1)->where('is_primary', true)->count())->toBe(1);
});

it('never removes the platform subdomain, or another site\'s domain', function (): void {
    cdAttach(2, 'shop.example');

    expect(cdReason(fn () => cdOnSite(1, fn () => app(RemoveDomain::class)->handle('a.test'))))->toBe('platform_subdomain')
        ->and(cdReason(fn () => cdOnSite(1, fn () => app(RemoveDomain::class)->handle('shop.example'))))->toBe('not_found')
        ->and(cdReason(fn () => cdOnSite(1, fn () => app(RemoveDomain::class)->handle('b.test'))))->toBe('not_found')
        ->and(cdRow(2, 'shop.example'))->not->toBeNull();
});
