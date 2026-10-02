<?php

use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Domains\OwnershipProof;
use App\Modules\Tenancy\Jobs\IssueDomainCertificate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Fixtures\FakeDns;

/*
 * site:domain — the operator's way to attach a customer's own domain until the Domains screens
 * exist. It goes through the same actions the screens will, and leaves an audit trail on the site
 * that gained or lost the domain.
 */

beforeEach(function (): void {
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    Cache::flush();
    config(['tenancy.custom_domains.target' => 'target.platform.test', 'tenancy.base_domains' => ['platform.test']]);
    $this->dns = FakeDns::install();
});

/** @return list<string> */
function auditedDomainActions(int $siteId): array
{
    return DB::connection('pgsql_admin')->table('audit_logs')
        ->where('site_id', $siteId)->where('action', 'like', 'tenancy.domain.%')
        ->orderBy('id')->pluck('action')->all();
}

it('lists a site\'s domains', function (): void {
    $this->artisan('site:domain', ['host' => 'a.test'])
        ->expectsOutputToContain('a.test')
        ->assertSuccessful();
});

it('attaches, verifies and removes a domain, auditing each step', function (): void {
    $this->artisan('site:domain', ['host' => 'a.test', 'action' => 'attach', 'domain' => 'https://Shop.Example/'])
        ->expectsOutputToContain('target.platform.test')
        ->assertSuccessful();

    $row = DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')->first();
    expect($row->site_id)->toBe(1)->and($row->pointing_method)->toBe('alias')->and($row->verified_at)->toBeNull();

    $this->artisan('site:domain', ['host' => 'a.test', 'action' => 'check', 'domain' => 'shop.example'])
        ->expectsOutputToContain('not_pointing')
        ->assertSuccessful();

    $this->dns->pointHere('shop.example');

    $this->artisan('site:domain', ['host' => 'a.test', 'action' => 'check', 'domain' => 'shop.example'])
        ->expectsOutputToContain('Verified')
        ->assertSuccessful();

    expect(app(SiteResolver::class)->resolve('shop.example')?->siteId)->toBe(1);

    $this->artisan('site:domain', ['host' => 'shop.example', 'action' => 'remove', 'domain' => 'shop.example'])
        ->expectsOutputToContain('Removed')
        ->assertSuccessful();

    expect(app(SiteResolver::class)->resolve('shop.example'))->toBeNull()
        ->and(auditedDomainActions(1))->toBe(['tenancy.domain.attached', 'tenancy.domain.verified', 'tenancy.domain.removed']);
});

it('records how the customer pointed the domain', function (): void {
    $this->artisan('site:domain', ['host' => 'a.test', 'action' => 'attach', 'domain' => 'shop.example', '--a-record' => true])
        ->assertSuccessful();

    expect(DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')->value('pointing_method'))->toBe('a_record');
});

it('prints the TXT record for a contested domain and audits the site that loses it', function (): void {
    $this->artisan('site:domain', ['host' => 'a.test', 'action' => 'attach', 'domain' => 'shop.example'])->assertSuccessful();
    $this->artisan('site:domain', ['host' => 'b.test', 'action' => 'attach', 'domain' => 'shop.example'])->assertSuccessful();
    $this->dns->pointHere('shop.example');

    $value = app(OwnershipProof::class)->recordValue(2, 'shop.example');

    $this->artisan('site:domain', ['host' => 'b.test', 'action' => 'check', 'domain' => 'shop.example'])
        ->expectsOutputToContain('_site-verification.shop.example = '.$value)
        ->assertSuccessful();

    $this->dns->txt['_site-verification.shop.example'] = [$value];

    $this->artisan('site:domain', ['host' => 'b.test', 'action' => 'check', 'domain' => 'shop.example'])
        ->expectsOutputToContain('Verified')
        ->assertSuccessful();

    expect(auditedDomainActions(2))->toBe(['tenancy.domain.attached', 'tenancy.domain.verified'])
        ->and(auditedDomainActions(1))->toBe(['tenancy.domain.attached', 'tenancy.domain.taken_over']);
});

it('explains a refusal and changes nothing', function (array $arguments, string $says): void {
    $this->artisan('site:domain', ['host' => 'a.test'] + $arguments)
        ->expectsOutputToContain($says)
        ->assertFailed();

    expect(DB::connection('pgsql_admin')->table('domains')->where('kind', 'custom')->count())->toBe(0)
        ->and(auditedDomainActions(1))->toBe([]);
})->with([
    'a platform name' => [['action' => 'attach', 'domain' => 'other.platform.test'], 'reserved'],
    'not a domain' => [['action' => 'attach', 'domain' => 'localhost'], 'invalid'],
    'removing the platform subdomain' => [['action' => 'remove', 'domain' => 'a.test'], 'platform_subdomain'],
    'an unknown action' => [['action' => 'promote', 'domain' => 'shop.example'], 'attach, check, https, primary or remove'],
    'no domain given' => [['action' => 'attach'], 'Give the domain'],
]);

it('turns HTTPS on and makes the domain primary once its certificate is active', function (): void {
    Queue::fake();
    RateLimiter::clear('domain-https:shop.example');
    $this->artisan('site:domain', ['host' => 'a.test', 'action' => 'attach', 'domain' => 'shop.example'])->assertSuccessful();

    $this->artisan('site:domain', ['host' => 'a.test', 'action' => 'https', 'domain' => 'shop.example'])
        ->expectsOutputToContain('not_verified')
        ->assertFailed();

    $this->dns->pointHere('shop.example');
    $this->artisan('site:domain', ['host' => 'a.test', 'action' => 'check', 'domain' => 'shop.example'])->assertSuccessful();

    $this->artisan('site:domain', ['host' => 'a.test', 'action' => 'https', 'domain' => 'shop.example'])
        ->expectsOutputToContain('HTTPS requested')
        ->assertSuccessful();
    Queue::assertPushed(IssueDomainCertificate::class, 1);

    $this->artisan('site:domain', ['host' => 'a.test', 'action' => 'primary', 'domain' => 'shop.example'])
        ->expectsOutputToContain('https_not_on')
        ->assertFailed();

    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')->update(['ssl_status' => 'active']);

    $this->artisan('site:domain', ['host' => 'a.test', 'action' => 'primary', 'domain' => 'shop.example'])
        ->expectsOutputToContain('Primary domain is now shop.example')
        ->assertSuccessful();

    expect(DB::connection('pgsql_admin')->table('domains')->where('site_id', 1)->where('is_primary', true)->value('hostname'))->toBe('shop.example')
        ->and(auditedDomainActions(1))->toBe([
            'tenancy.domain.attached', 'tenancy.domain.verified', 'tenancy.domain.https_requested', 'tenancy.domain.primary_changed',
        ]);
});

it('fails for a host that is not a site', function (): void {
    $this->artisan('site:domain', ['host' => 'nobody.example'])->assertFailed();
});
