<?php

use App\Modules\Shared\Contracts\DomainProvisioner;
use App\Modules\Tenancy\Actions\AttachDomain;
use App\Modules\Tenancy\Actions\CheckDomain;
use App\Modules\Tenancy\Actions\EnableHttps;
use App\Modules\Tenancy\Actions\SetPrimaryDomain;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Domains\CaddyOnDemandProvisioner;
use App\Modules\Tenancy\Domains\DomainHealth;
use App\Modules\Tenancy\Exceptions\DomainChangeRejectedException;
use App\Modules\Tenancy\Jobs\IssueDomainCertificate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Fixtures\FakeDns;

/*
 * HTTPS, the primary domain and the daily re-check of a customer's own domain (design.md §28):
 * HTTPS is a step of its own after verification; the primary is always exactly one, and a
 * customer's domain becomes it only once HTTPS is on; a domain that stops pointing here is marked,
 * and after 30 days it is no longer served — the site falls back to its platform subdomain.
 */

beforeEach(function (): void {
    seedTenancy();
    Cache::flush();
    RateLimiter::clear('domain-https:shop.example');
    config(['tenancy.custom_domains.target' => 'target.platform.test', 'tenancy.base_domains' => ['platform.test']]);
    $this->dns = FakeDns::install();
    Queue::fake();
});

function httpsOnSite(int $siteId, Closure $run): mixed
{
    return app(Tenancy::class)->enter($siteId, $run);
}

/** A domain attached to the site and verified (it points at the platform). */
function liveDomain(int $siteId, string $host, FakeDns $dns): void
{
    $dns->pointHere($host);
    httpsOnSite($siteId, function () use ($host): void {
        app(AttachDomain::class)->handle($host);
        app(CheckDomain::class)->handle($host);
    });
}

function httpsRow(string $host): ?object
{
    return DB::connection('pgsql_admin')->table('domains')->where('hostname', $host)->first();
}

function httpsReason(Closure $run): ?string
{
    try {
        $run();
    } catch (DomainChangeRejectedException $e) {
        return $e->reason;
    }

    return null;
}

/** Stands in for the web server: answers what the test says a certificate request does. */
function provisioner(?string $problem): void
{
    app()->instance(DomainProvisioner::class, new class($problem) implements DomainProvisioner
    {
        public function __construct(private ?string $problem) {}

        #[Override]
        public function ensureCertificate(string $host): ?string
        {
            return $this->problem;
        }
    });
}

// ── HTTPS ───────────────────────────────────────────────────────────────────────────────────

it('turns HTTPS on: the domain waits for its certificate and the certificate gate opens', function (): void {
    liveDomain(1, 'shop.example', $this->dns);

    expect(app(SiteResolver::class)->resolve('shop.example')?->mayHaveCertificate())->toBeFalse();

    httpsOnSite(1, fn () => app(EnableHttps::class)->handle('shop.example'));

    expect(httpsRow('shop.example')->ssl_status)->toBe('pending')
        ->and(app(SiteResolver::class)->resolve('shop.example')?->mayHaveCertificate())->toBeTrue();

    Queue::assertPushed(IssueDomainCertificate::class, fn (IssueDomainCertificate $job): bool => $job->siteId === 1 && $job->host === 'shop.example');
});

it('refuses HTTPS for a domain that is not verified, and requests no certificate', function (): void {
    httpsOnSite(1, fn () => app(AttachDomain::class)->handle('shop.example'));

    expect(httpsReason(fn () => httpsOnSite(1, fn () => app(EnableHttps::class)->handle('shop.example'))))->toBe('not_verified')
        ->and(httpsRow('shop.example')->ssl_status)->toBeNull();

    Queue::assertNothingPushed();
});

it('refuses HTTPS for a domain the site does not have, the platform subdomain included', function (string $host): void {
    liveDomain(2, 'shop.example', $this->dns);

    expect(httpsReason(fn () => httpsOnSite(1, fn () => app(EnableHttps::class)->handle($host))))->toBe('not_found');
})->with(['shop.example', 'a.test']);

it('allows one HTTPS attempt per domain every five minutes', function (): void {
    liveDomain(1, 'shop.example', $this->dns);
    httpsOnSite(1, fn () => app(EnableHttps::class)->handle('shop.example'));

    expect(httpsReason(fn () => httpsOnSite(1, fn () => app(EnableHttps::class)->handle('shop.example'))))->toBe('too_many_attempts');

    Queue::assertPushed(IssueDomainCertificate::class, 1);
});

it('leaves a domain whose HTTPS is already on alone', function (): void {
    liveDomain(1, 'shop.example', $this->dns);
    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')->update(['ssl_status' => 'active']);

    httpsOnSite(1, fn () => app(EnableHttps::class)->handle('shop.example'));

    expect(httpsRow('shop.example')->ssl_status)->toBe('active');
    Queue::assertNothingPushed();
});

it('records the outcome of the certificate request', function (?string $problem, string $status): void {
    liveDomain(1, 'shop.example', $this->dns);
    httpsOnSite(1, fn () => app(EnableHttps::class)->handle('shop.example'));
    provisioner($problem);

    app()->call([new IssueDomainCertificate(1, 'shop.example'), 'handle']);
    $row = httpsRow('shop.example');

    expect($row->ssl_status)->toBe($status)
        ->and($row->failure_reason)->toBe($problem)
        ->and($row->verified_at)->not->toBeNull();   // a failed certificate never unverifies the domain
})->with([
    'issued' => [null, 'active'],
    'not issued' => ['certificate_not_issued', 'failed'],
]);

it('does nothing when the domain was removed before the certificate job ran', function (): void {
    provisioner(null);

    app()->call([new IssueDomainCertificate(1, 'gone.example'), 'handle']);

    expect(httpsRow('gone.example'))->toBeNull();
});

it('lets a failed domain try again after the wait', function (): void {
    liveDomain(1, 'shop.example', $this->dns);
    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')->update(['ssl_status' => 'failed', 'failure_reason' => 'certificate_not_issued']);

    httpsOnSite(1, fn () => app(EnableHttps::class)->handle('shop.example'));
    $row = httpsRow('shop.example');

    expect($row->ssl_status)->toBe('pending')->and($row->failure_reason)->toBeNull();
});

it('asks the web server for the certificate with one HTTPS request to the domain', function (): void {
    Http::fake(['https://shop.example/*' => Http::response('', 301, ['Location' => 'https://elsewhere.example/'])]);

    expect((new CaddyOnDemandProvisioner)->ensureCertificate('shop.example'))->toBeNull();   // any answer = the handshake worked

    Http::assertSent(fn ($request): bool => $request->url() === 'https://shop.example/');
});

it('reports a certificate that could not be issued', function (): void {
    Http::fake(fn () => throw new ConnectionException('SSL: no alternative certificate subject name matches'));

    expect((new CaddyOnDemandProvisioner)->ensureCertificate('shop.example'))->toBe('certificate_not_issued');
});

// ── Primary ─────────────────────────────────────────────────────────────────────────────────

it('makes a domain with HTTPS on the primary, and the site still has exactly one', function (): void {
    liveDomain(1, 'shop.example', $this->dns);
    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')->update(['ssl_status' => 'active']);

    httpsOnSite(1, fn () => app(SetPrimaryDomain::class)->handle('shop.example'));

    expect(httpsRow('shop.example')->is_primary)->toBeTrue()
        ->and(httpsRow('a.test')->is_primary)->toBeFalse()
        ->and(DB::connection('pgsql_admin')->table('domains')->where('site_id', 1)->where('is_primary', true)->count())->toBe(1)
        ->and(httpsRow('b.test')->is_primary)->toBeTrue();   // the other site is untouched
});

it('refuses a primary that could not answer visitors', function (?string $sslStatus, bool $verified, string $reason): void {
    httpsOnSite(1, fn () => app(AttachDomain::class)->handle('shop.example'));
    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')
        ->update(['ssl_status' => $sslStatus, 'verified_at' => $verified ? now() : null]);

    expect(httpsReason(fn () => httpsOnSite(1, fn () => app(SetPrimaryDomain::class)->handle('shop.example'))))->toBe($reason)
        ->and(httpsRow('a.test')->is_primary)->toBeTrue();
})->with([
    'not verified' => [null, false, 'not_verified'],
    'HTTPS not turned on' => [null, true, 'https_not_on'],
    'certificate still being issued' => ['pending', true, 'https_not_on'],
    'certificate failed' => ['failed', true, 'https_not_on'],
]);

it('can make the platform subdomain primary again, and never another site\'s domain', function (): void {
    liveDomain(1, 'shop.example', $this->dns);
    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')->update(['ssl_status' => 'active']);
    httpsOnSite(1, fn () => app(SetPrimaryDomain::class)->handle('shop.example'));

    httpsOnSite(1, fn () => app(SetPrimaryDomain::class)->handle('a.test'));

    expect(httpsRow('a.test')->is_primary)->toBeTrue()
        ->and(httpsRow('shop.example')->is_primary)->toBeFalse()
        ->and(httpsReason(fn () => httpsOnSite(1, fn () => app(SetPrimaryDomain::class)->handle('b.test'))))->toBe('not_found');
});

// ── Daily re-check ──────────────────────────────────────────────────────────────────────────

it('marks a live domain that stopped pointing here, and clears the mark when it is back', function (): void {
    liveDomain(1, 'shop.example', $this->dns);
    $this->dns->addresses['shop.example'] = ['198.51.100.9'];

    expect(app(DomainHealth::class)->sweep())->toBe([['site_id' => 1, 'hostname' => 'shop.example', 'event' => 'failing']])
        ->and(app(DomainHealth::class)->sweep())->toBe([])   // still failing: reported once
        ->and(httpsRow('shop.example')->failure_reason)->toBe('points_elsewhere')
        ->and(httpsRow('shop.example')->verified_at)->not->toBeNull();

    $this->dns->pointHere('shop.example');

    expect(app(DomainHealth::class)->sweep())->toBe([['site_id' => 1, 'hostname' => 'shop.example', 'event' => 'recovered']])
        ->and(httpsRow('shop.example')->failing_since)->toBeNull();
});

it('stops serving a domain that has been failing for 30 days and hands the primary back', function (): void {
    liveDomain(1, 'shop.example', $this->dns);
    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')->update(['ssl_status' => 'active']);
    httpsOnSite(1, fn () => app(SetPrimaryDomain::class)->handle('shop.example'));
    app(SiteResolver::class)->resolve('shop.example');   // remembered as site 1

    $this->dns->addresses['shop.example'] = [];
    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')->update(['failing_since' => now()->subDays(31)]);

    expect(app(DomainHealth::class)->sweep())->toBe([['site_id' => 1, 'hostname' => 'shop.example', 'event' => 'lost']]);

    $row = httpsRow('shop.example');

    expect($row->verified_at)->toBeNull()
        ->and($row->ssl_status)->toBeNull()
        ->and($row->is_primary)->toBeFalse()
        ->and(httpsRow('a.test')->is_primary)->toBeTrue()
        ->and(app(SiteResolver::class)->resolve('shop.example'))->toBeNull();
});

it('keeps the row of a lost domain on later sweeps, so its owner can see why and check it again', function (): void {
    liveDomain(1, 'shop.example', $this->dns);
    $this->dns->addresses['shop.example'] = [];
    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')
        ->update(['failing_since' => now()->subDays(31), 'created_at' => now()->subDays(60)]);

    expect(array_column(app(DomainHealth::class)->sweep(), 'event'))->toBe(['lost'])
        ->and(app(DomainHealth::class)->sweep())->toBe([])
        ->and(httpsRow('shop.example')?->failure_reason)->toBe('not_pointing');

    // Pointed back and checked: it serves again.
    $this->dns->pointHere('shop.example');
    httpsOnSite(1, fn () => app(CheckDomain::class)->handle('shop.example'));

    expect(app(SiteResolver::class)->resolve('shop.example')?->siteId)->toBe(1)
        ->and(httpsRow('shop.example')->failing_since)->toBeNull();
});

it('skips the whole re-check when the target host itself does not resolve', function (): void {
    liveDomain(1, 'shop.example', $this->dns);
    $this->dns->addresses['target.platform.test'] = [];   // a resolver outage, not the customers' fault

    expect(app(DomainHealth::class)->sweep())->toBe([])
        ->and(httpsRow('shop.example')->failing_since)->toBeNull();
});

it('forgets a host only once the change is committed', function (): void {
    liveDomain(1, 'shop.example', $this->dns);
    $sites = app(SiteResolver::class);
    $sites->resolve('shop.example');   // remembered

    DB::transaction(function () use ($sites): void {
        DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')->delete();
        $sites->forget('shop.example');

        // Still inside the transaction: a concurrent request must not be able to re-read the old
        // row and remember it again, so the remembered answer stays until the commit.
        expect($sites->resolve('shop.example'))->not->toBeNull();
    });

    expect($sites->resolve('shop.example'))->toBeNull();
});

it('keeps a domain that has been failing for less than 30 days', function (): void {
    liveDomain(1, 'shop.example', $this->dns);
    $this->dns->addresses['shop.example'] = [];
    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')->update(['failing_since' => now()->subDays(29)]);

    expect(array_column(app(DomainHealth::class)->sweep(), 'event'))->toBe(['expiring'])   // the last week: warned, once
        ->and(app(DomainHealth::class)->sweep())->toBe([])
        ->and(httpsRow('shop.example')->verified_at)->not->toBeNull();
});

it('drops a domain nobody pointed here within 7 days, and keeps a fresh one', function (): void {
    httpsOnSite(1, fn () => app(AttachDomain::class)->handle('old.example'));
    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'old.example')->update(['created_at' => now()->subDays(8)]);
    httpsOnSite(2, fn () => app(AttachDomain::class)->handle('fresh.example'));

    expect(app(DomainHealth::class)->sweep())->toBe([['site_id' => 1, 'hostname' => 'old.example', 'event' => 'wait_expired']])
        ->and(httpsRow('old.example'))->toBeNull()
        ->and(httpsRow('fresh.example'))->not->toBeNull();
});

it('never touches a platform subdomain', function (): void {
    expect(app(DomainHealth::class)->sweep())->toBe([])
        ->and(httpsRow('a.test')->verified_at)->not->toBeNull();
});

it('runs the daily re-check as a command and audits each change', function (): void {
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    liveDomain(1, 'shop.example', $this->dns);
    $this->dns->addresses['shop.example'] = [];

    $this->artisan('domains:recheck')->expectsOutputToContain('failing: shop.example')->assertSuccessful();

    expect(DB::connection('pgsql_admin')->table('audit_logs')->where('site_id', 1)->pluck('action')->all())->toBe(['tenancy.domain.failing']);
});
