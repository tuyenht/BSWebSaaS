<?php

use App\Modules\Shared\Contracts\EdgeCache;
use App\Modules\Storefront\EdgeCache\NullEdgeCache;
use App\Modules\Storefront\EdgeCache\PurgeEdgeHosts;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;

/*
 * `storefront:purge-edge` drops edge-cached pages by host name, 100 names per Cloudflare call: every
 * website's (--all, after a deploy that changes how pages look) or the ones named. Names outside the
 * platform's zone are never sent. With no edge configured it says so and does nothing.
 */

beforeEach(function (): void {
    seedTenancy(); // sites 1 (a.test) / 2 (b.test)
    Cache::flush();
});

/** Gives site 1 and site 2 seventy platform host names each, and site 1 a customer domain. */
function purgeEdgeManyHosts(): void
{
    $rows = [['site_id' => 1, 'hostname' => 'congty-an.vn', 'kind' => 'custom', 'is_primary' => false, 'is_redirect' => false, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]];
    foreach (range(1, 140) as $n) {
        $rows[] = ['site_id' => $n % 2 + 1, 'hostname' => "ten-{$n}.test", 'kind' => 'subdomain', 'is_primary' => false, 'is_redirect' => false, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    }
    DB::connection('pgsql_admin')->table('domains')->insert($rows);
}

it('purges every website\'s hosts in the zone, 100 names a call, never a customer domain', function (): void {
    $edge = edgeCacheRecorder();
    purgeEdgeManyHosts();

    $this->artisan('storefront:purge-edge', ['--all' => true])
        ->expectsOutputToContain('Queued the purge of 142 host names in 2 jobs.')
        ->assertSuccessful();

    $sent = array_merge(...array_map(fn (array $call): array => $call[1], $edge->calls));
    expect(array_column($edge->calls, 0))->toBe(['hosts', 'hosts'])
        ->and(array_map(fn (array $call): int => count($call[1]), $edge->calls))->toBe([100, 42])
        ->and($sent)->toHaveCount(142)->toContain('a.test', 'b.test', 'ten-140.test')->not->toContain('congty-an.vn');
});

it('spaces the jobs of one sweep out, so a deploy\'s purge does not hit the zone all at once', function (): void {
    edgeCacheRecorder();
    purgeEdgeManyHosts();
    config(['storefront.edge_cache.purge_pace_seconds' => 5]);
    Bus::fake();

    $this->artisan('storefront:purge-edge', ['--all' => true])->assertSuccessful();

    $delays = [];
    Bus::assertDispatched(PurgeEdgeHosts::class, function (PurgeEdgeHosts $job) use (&$delays): bool {
        $delays[] = $job->delay;

        return true;
    });
    expect($delays)->toBe([0, 5]);
});

it('purges only the websites named, by host name or by id', function (): void {
    $edge = edgeCacheRecorder();
    DB::connection('pgsql_admin')->table('domains')->insert([
        ['site_id' => 2, 'hostname' => 'www.b.test', 'kind' => 'subdomain', 'is_primary' => false, 'is_redirect' => false, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->artisan('storefront:purge-edge', ['--site' => ['WWW.B.TEST.']])->assertSuccessful();
    expect($edge->calls)->toBe([['hosts', ['b.test', 'www.b.test']]]);

    $edge->calls = [];
    $this->artisan('storefront:purge-edge', ['--site' => ['1', 'b.test']])
        ->expectsOutputToContain('Queued the purge of 3 host names in 1 job.')
        ->assertSuccessful();
    expect($edge->calls)->toBe([['hosts', ['a.test', 'b.test', 'www.b.test']]]);
});

it('names a website it does not know, and fails', function (): void {
    $edge = edgeCacheRecorder();

    $this->artisan('storefront:purge-edge', ['--site' => ['khong-co.test', '99']])
        ->expectsOutputToContain('No website has the id or host name "khong-co.test".')
        ->expectsOutputToContain('No website has the id or host name "99".')
        ->assertFailed();
    $this->artisan('storefront:purge-edge')->assertExitCode(2);

    expect($edge->calls)->toBe([]);
});

it('retries a refused batch later, purging its homes at once', function (): void {
    $edge = edgeCacheRecorder(hostsAccepted: false);
    Exceptions::fake(); // the report would add a log line of its own
    Log::spy();

    $job = (new PurgeEdgeHosts(['a.test', 'congty-an.vn', 'b.test']))->withFakeQueueInteractions();
    $job->job->attempts = 2;
    $job->handle($edge);

    $job->assertReleased();
    expect($job->job->releaseDelay)->toBeGreaterThanOrEqual(120)->toBeLessThanOrEqual(150);
    expect($edge->calls)->toBe([['hosts', ['a.test', 'b.test']], ['urls', ['https://a.test/', 'https://b.test/']]]);

    $last = (new PurgeEdgeHosts(['a.test']))->withFakeQueueInteractions();
    $last->job->attempts = 5;
    $last->handle($edge);
    $last->assertNotReleased();
    Log::shouldHaveReceived('error')->once();
});

it('says nothing is configured, and queues nothing, without an edge', function (): void {
    app()->instance(EdgeCache::class, new NullEdgeCache);
    Bus::fake();

    $this->artisan('storefront:purge-edge', ['--all' => true])
        ->expectsOutputToContain('No edge cache is configured')
        ->assertSuccessful();

    Bus::assertNothingDispatched();
});
