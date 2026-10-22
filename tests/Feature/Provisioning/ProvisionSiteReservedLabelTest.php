<?php

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Provisioning\Exceptions\HostnameNotPlatformSubdomainException;
use App\Modules\Provisioning\Exceptions\HostnameReservedLabelException;
use App\Modules\Tenancy\Domains\DomainName;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\DB;

/*
 * §2 domain ownership: some names under a platform base are kept for the platform's own services
 * (admin., api., mail., sgtm. — ADR-22…), whether or not that service runs yet. A new site may
 * never start on one, or an operator typo (or a malicious operator) would hand a customer a name
 * the platform needs. The list lives in config: defaults always apply, the env only adds to them.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();
    config([
        'tenancy.base_domains' => ['platform.test', 'second.test'],
        'tenancy.primary_base' => 'platform.test',
    ]);
});

function reservedProvision(string $host): Site
{
    return app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Alpha',
        siteName: 'Alpha',
        hostname: $host,
        ownerEmail: 'owner@example.test',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-password',
    ));
}

/** @return array{sites: int, tenants: int, domains: int, users: int} */
function reservedFootprint(): array
{
    $admin = DB::connection('pgsql_admin');

    return [
        'sites' => $admin->table('sites')->count(),
        'tenants' => $admin->table('tenants')->count(),
        'domains' => $admin->table('domains')->count(),
        'users' => $admin->table('users')->count(),
    ];
}

it('keeps every default reserved label off every platform base', function (string $label): void {
    expect(DomainName::isPlatformSubdomain("{$label}.platform.test"))->toBeFalse()
        ->and(DomainName::isPlatformSubdomain("{$label}.second.test"))->toBeFalse()
        ->and(DomainName::reservedLabel("{$label}.second.test"))->toBe($label);
})->with(fn (): array => (require dirname(__DIR__, 3).'/config/tenancy.php')['reserved_labels']);

it('ships a default list of plain labels that covers the platform services the design names', function (): void {
    $labels = config('tenancy.reserved_labels');

    expect($labels)->toBeList()->not->toBeEmpty()
        ->and($labels)->toContain('admin', 'api', 'app', 'mail', 'static', 'sgtm', 'companion', 'files', 'img')
        ->and($labels)->each->toMatch('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/');
});

it('refuses a new site on a reserved label in any spelling, writing nothing', function (string $host): void {
    $before = reservedFootprint();

    expect(fn () => reservedProvision($host))->toThrow(HostnameReservedLabelException::class);

    expect(reservedFootprint())->toBe($before);
})->with([
    'admin' => 'admin.platform.test',
    'api on the second base' => 'api.second.test',
    'upper case with a trailing dot' => 'https://ADMIN.Platform.Test./',
    'the tracking collector (ADR-22)' => 'sgtm.platform.test',
]);

it('names the refused label in the exception', function (): void {
    try {
        reservedProvision('Mail.platform.test');
    } catch (HostnameReservedLabelException $e) {
        // Still a refusal of a non-platform name, so any caller catching that one catches this too.
        expect($e)->toBeInstanceOf(HostnameNotPlatformSubdomainException::class)
            ->and($e->hostname)->toBe('mail.platform.test')
            ->and($e->label)->toBe('mail');

        return;
    }

    $this->fail('No HostnameReservedLabelException was thrown.');
});

it('matches the whole label only, so look-alike slugs still start a site', function (string $host): void {
    $site = reservedProvision($host);

    expect(Domain::query()->where('site_id', $site->id)->sole()->hostname)->toBe($host);
})->with([
    'admins.platform.test',
    'my-admin.platform.test',
    'admin1.platform.test',
    'shop.platform.test',
]);

it('adds labels from the env without dropping the defaults', function (): void {
    $_SERVER['TENANCY_RESERVED_LABELS'] = $_ENV['TENANCY_RESERVED_LABELS'] = ' Partners ,, a.b , ';

    try {
        $labels = (require config_path('tenancy.php'))['reserved_labels'];
    } finally {
        unset($_SERVER['TENANCY_RESERVED_LABELS'], $_ENV['TENANCY_RESERVED_LABELS']);
    }

    expect($labels)->toContain('partners', 'admin', 'api')
        ->and($labels)->not->toContain('', 'a.b', ' Partners ')
        ->and($labels)->toBe(array_values(array_unique($labels)));
});

it('refuses a label added in config', function (): void {
    config(['tenancy.reserved_labels' => [...config('tenancy.reserved_labels'), 'partners']]);

    expect(fn () => reservedProvision('partners.platform.test'))->toThrow(HostnameReservedLabelException::class);
});

it('keeps the default labels when the config key is missing, as in a stale config cache', function (?array $configured): void {
    config(['tenancy.reserved_labels' => $configured]);

    expect(DomainName::reservedLabel('admin.platform.test'))->toBe('admin')
        ->and(DomainName::reservedLabel('website.platform.test'))->toBe('website')
        ->and(fn () => reservedProvision('admin.platform.test'))->toThrow(HostnameReservedLabelException::class);
})->with(['missing' => [null], 'emptied' => [[]]]);

it('refuses a punycode label, so a look-alike of a reserved name cannot start a site', function (): void {
    // Cyrillic "а" + "dmin": shown by some browsers as admin.platform.test.
    $punycode = DomainName::parse("\u{0430}dmin.platform.test");
    expect($punycode)->toStartWith('xn--')
        ->and(DomainName::isPlatformSubdomain($punycode))->toBeFalse();

    $before = reservedFootprint();

    expect(fn () => reservedProvision("\u{0430}dmin.platform.test"))
        ->toThrow(HostnameNotPlatformSubdomainException::class);

    expect(reservedFootprint())->toBe($before);
});

it('still re-runs a site that already lives on a label reserved later', function (): void {
    $site = reservedProvision('partners.platform.test');
    config(['tenancy.reserved_labels' => [...config('tenancy.reserved_labels'), 'partners']]);

    expect(reservedProvision('partners.platform.test')->id)->toBe($site->id)
        ->and(Site::query()->count())->toBe(1);
});

it('refuses through site:provision with a plain-language message and fails', function (): void {
    $before = reservedFootprint();

    // Refused before the password prompt (no question is expected), naming the label.
    $this->artisan('site:provision', [
        '--name' => 'Alpha',
        '--host' => 'ADMIN.platform.test.',
        '--new-tenant' => 'Alpha',
        '--owner-email' => 'owner@example.test',
    ])
        ->expectsOutput(__('provisioning.hostname_reserved_label', [
            'label' => 'admin',
            'base' => 'platform.test',
        ]))
        ->assertFailed();

    expect(reservedFootprint())->toBe($before);
});
