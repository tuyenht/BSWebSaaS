<?php

use App\Modules\Admin\Support\Domains\DomainGroup;
use App\Modules\Admin\Support\Domains\DomainGroups;
use App\Modules\Tenancy\Domains\DomainName;
use App\Modules\Tenancy\Domains\PointingRecords;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * What the Domains screen shows for each of a site's names (design.md §28), read from the columns
 * the domain engine already keeps: waiting, contested, pointed, getting HTTPS, HTTPS failed, live,
 * failing, about to stop, stopped. `domains` has no row-level security, so the read names the
 * site itself — another site's rows never show.
 */

beforeEach(function (): void {
    seedTenancy();
    config(['tenancy.custom_domains.target' => 'target.platform.test', 'tenancy.base_domains' => ['platform.test']]);
    CarbonImmutable::setTestNow('2026-11-02 03:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** One pair of spellings on $site; $runs gets $columns, its twin $twin. */
function dgPair(int $site, string $runs, array $columns = [], array $twin = []): void
{
    $now = CarbonImmutable::now();
    $base = ['site_id' => $site, 'kind' => 'custom', 'is_primary' => false, 'created_at' => $now, 'updated_at' => $now];

    $table = DB::connection('pgsql_admin')->table('domains');
    $table->insert([...$base, 'hostname' => $runs, 'is_redirect' => false, ...$columns]);
    $table->insert([...$base, 'hostname' => DomainName::sibling($runs), 'is_redirect' => true, ...$twin]);
}

/** @return array<string, DomainGroup> */
function dgGroups(int $site = 1): array
{
    $groups = [];
    foreach (app(DomainGroups::class)->forSite($site)->groups as $group) {
        $groups[$group->name] = $group;
    }

    return $groups;
}

it('reads every state from the columns the engine keeps', function (array $runs, array $twin, string $state): void {
    dgPair(1, 'tencongty.vn', $runs, $twin);

    expect(dgGroups()['tencongty.vn']->state)->toBe($state);
})->with([
    'never pointed' => [[], [], 'wait'],
    'a check found it not pointed yet' => [['failure_reason' => 'not_pointing', 'checked_at' => '2026-11-01'], [], 'wait'],
    'another site holds it' => [['failure_reason' => 'needs_proof'], [], 'claim'],
    'pointed, no HTTPS' => [['verified_at' => '2026-11-01'], [], 'verified'],
    'certificate on its way' => [['verified_at' => '2026-11-01', 'ssl_status' => 'pending'], [], 'issuing'],
    'certificate failed' => [['verified_at' => '2026-11-01', 'ssl_status' => 'failed', 'failure_reason' => 'certificate_not_issued'], [], 'https_err'],
    'live' => [['verified_at' => '2026-11-01', 'ssl_status' => 'active'], ['verified_at' => '2026-11-01', 'ssl_status' => 'active'], 'active'],
    'failing for 3 days' => [['verified_at' => '2026-10-01', 'ssl_status' => 'active', 'failing_since' => '2026-10-30 03:00:00', 'failure_reason' => 'points_elsewhere'], [], 'failing'],
    'failing for 25 days' => [['verified_at' => '2026-09-01', 'ssl_status' => 'active', 'failing_since' => '2026-10-08 03:00:00', 'failure_reason' => 'points_elsewhere'], [], 'expiring'],
    'stopped after 30 days' => [['failing_since' => '2026-09-20', 'failure_reason' => 'not_pointing'], [], 'lost'],
]);

it('counts the days left and the dates people must act by, in the site\'s time', function (): void {
    dgPair(1, 'sap-ngung.vn', ['verified_at' => '2026-09-01', 'ssl_status' => 'active', 'failing_since' => '2026-10-08 03:00:00', 'failure_reason' => 'points_elsewhere']);
    dgPair(1, 'dang-cho.vn', ['created_at' => '2026-11-01 20:00:00']);

    $groups = dgGroups();

    expect($groups['sap-ngung.vn']->daysLeft)->toBe(5)
        ->and($groups['sap-ngung.vn']->until?->format('d/m/Y'))->toBe('07/11/2026')
        ->and($groups['dang-cho.vn']->removeOn?->format('d/m/Y'))->toBe('09/11/2026')
        ->and($groups['dang-cho.vn']->removeOn?->getTimezone()->getName())->toBe('Asia/Ho_Chi_Minh');
});

it('sees a name verified on another site as contested without saying which site holds it', function (): void {
    dgPair(2, 'tranh-chap.vn', ['verified_at' => '2026-10-01', 'ssl_status' => 'active']);
    dgPair(1, 'tranh-chap.vn');

    $groups = dgGroups();

    expect($groups['tranh-chap.vn']->state)->toBe('claim')
        ->and(array_keys($groups))->toBe(['a.test', 'tranh-chap.vn'])
        ->and(dgGroups(2)['tranh-chap.vn']->state)->toBe('active');
});

it('lists only the site\'s own names: the platform subdomain first, then each pair once', function (): void {
    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'a.test')->update(['is_primary' => false]);
    dgPair(1, 'www.tencongty.vn', ['verified_at' => '2026-10-01', 'ssl_status' => 'active', 'is_primary' => true], ['verified_at' => '2026-10-01']);
    dgPair(2, 'cua-site-b.vn');

    $overview = app(DomainGroups::class)->forSite(1);
    $group = $overview->groups[1];

    expect(array_map(fn (DomainGroup $g): string => $g->name, $overview->groups))->toBe(['a.test', 'tencongty.vn'])
        ->and($overview->groups[0]->platform)->toBeTrue()
        ->and($group->runs)->toBe('www.tencongty.vn')
        ->and($group->alt)->toBe('tencongty.vn')
        ->and($group->www())->toBeTrue()
        ->and($group->primary)->toBeTrue()
        ->and($overview->primary)->toBe('www.tencongty.vn')
        ->and($overview->used)->toBe(1);
});

it('counts a stopped name against the plan and says how many stopped', function (): void {
    dgPair(1, 'mot.vn', ['failing_since' => '2026-09-20', 'failure_reason' => 'not_pointing']);
    dgPair(1, 'hai.vn');

    $overview = app(DomainGroups::class)->forSite(1);

    expect($overview->used)->toBe(2)->and($overview->lost)->toBe(1);
});

it('marks the other spelling optional while the name is live without it', function (): void {
    dgPair(1, 'tencongty.vn', ['verified_at' => '2026-10-01', 'ssl_status' => 'active']);

    $group = dgGroups()['tencongty.vn'];

    expect($group->altOptional)->toBeTrue()
        ->and($group->rowState(true))->toBe('ok')
        ->and($group->rowState(false))->toBe('optional');
});

it('names the HTTPS problem the certificate step left behind', function (?string $reason, string $code): void {
    dgPair(1, 'tencongty.vn', ['verified_at' => '2026-10-01', 'ssl_status' => 'failed', 'failure_reason' => $reason]);

    expect(dgGroups()['tencongty.vn']->httpsError)->toBe($code);
})->with([
    [null, 'notpointed'],
    ['certificate_not_issued', 'notpointed'],
    ['not_pointing', 'notpointed'],
    ['points_elsewhere', 'elsewhere'],
]);

it('writes the DNS rows under the registered name', function (string $runs, ?string $alt, array $labels): void {
    expect(array_column(PointingRecords::for($runs, $alt), 'label'))->toBe($labels)
        ->and(array_unique(array_column(PointingRecords::for($runs, $alt), 'target')))->toBe(['target.platform.test']);
})->with([
    ['tencongty.vn', 'www.tencongty.vn', ['@', 'www']],
    ['www.tencongty.com.vn', 'tencongty.com.vn', ['www', '@']],
    ['shop.khuyen-mai.tencongty.com.vn', 'www.shop.khuyen-mai.tencongty.com.vn', ['shop.khuyen-mai', 'www.shop.khuyen-mai']],
]);

it('points only the registered name by number when the owner chose the server address', function (): void {
    $records = PointingRecords::for('tencongty.vn', 'www.tencongty.vn', '203.0.113.10');

    expect($records[0])->toMatchArray(['label' => '@', 'kind' => 'ip', 'target' => '203.0.113.10', 'runs' => true])
        ->and($records[1])->toMatchArray(['label' => 'www', 'kind' => 'name', 'target' => 'target.platform.test', 'runs' => false]);
});

it('shows names with diacritics as people write them', function (): void {
    expect(DomainName::display(DomainName::parse('bảo-sơn.vn')))->toBe('bảo-sơn.vn')
        ->and(DomainName::display('tencongty.vn'))->toBe('tencongty.vn');
});
