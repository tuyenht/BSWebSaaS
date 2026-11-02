<?php

use App\Modules\Tenancy\Actions\CheckDomain;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Domains\DnsLookup;
use App\Modules\Tenancy\Domains\OwnershipProof;
use App\Modules\Tenancy\Domains\WaitingDomains;
use App\Modules\Tenancy\Events\DomainNeedsAttention;
use Carbon\Carbon;
use Illuminate\Cache\ArrayStore;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\FakeDns;

/*
 * The Domains screen tells the owner the platform checks a waiting name again every 15 minutes.
 * domains:recheck-waiting is that check, run for them: only names that never went live — still
 * to be pointed, or held by another site and waiting for their TXT proof — each in its own site,
 * with the same rules as a check by hand (the proof is per hostname). Live, failing and stopped
 * names are left to the daily sweep.
 */

beforeEach(function (): void {
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
    Cache::flush();
    config(['tenancy.custom_domains.target' => 'target.platform.test', 'tenancy.base_domains' => ['platform.test']]);
    $this->dns = FakeDns::install();
    Queue::fake();
});

/** A pair of spellings on $site: $runs runs with $columns; its twin redirects with $twin. */
function wdPair(int $site, string $runs, array $columns = [], array $twin = []): void
{
    $base = ['site_id' => $site, 'kind' => 'custom', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()];
    $table = DB::connection('pgsql_admin')->table('domains');
    $table->insert([...$base, 'hostname' => $runs, 'is_redirect' => false, ...$columns]);
    $table->insert([...$base, 'hostname' => str_starts_with($runs, 'www.') ? substr($runs, 4) : 'www.'.$runs, 'is_redirect' => true, ...$twin]);
}

function wdRow(string $host, int $site = 1): ?object
{
    return DB::connection('pgsql_admin')->table('domains')->where('site_id', $site)->where('hostname', $host)->first();
}

/** @return list<array{0: string, 1: int, 2: string}> action, site, hostname */
function wdTrail(): array
{
    return DB::connection('pgsql_admin')->table('audit_logs')->orderBy('id')->get(['action', 'site_id', 'meta', 'actor_label'])
        ->map(fn (object $row): array => [$row->action, $row->site_id, json_decode($row->meta, true)['hostname'] ?? '', $row->actor_label])
        ->all();
}

it('verifies a waiting name its owner pointed, with its other spelling, and notes it as the system', function (): void {
    wdPair(1, 'tencongty.vn');
    wdPair(1, 'chua-tro.vn');
    $this->dns->pointHere('tencongty.vn', 'www.tencongty.vn');

    $this->artisan('domains:recheck-waiting')->expectsOutputToContain('2 verified.')->assertSuccessful();

    expect(wdRow('tencongty.vn')?->verified_at)->not->toBeNull()
        ->and(wdRow('www.tencongty.vn')?->verified_at)->not->toBeNull()
        ->and(wdRow('chua-tro.vn')?->verified_at)->toBeNull()
        ->and(wdRow('chua-tro.vn')?->failure_reason)->toBe('not_pointing')
        ->and(wdTrail())->toBe([
            ['tenancy.domain.verified', 1, 'tencongty.vn', 'system'],
            ['tenancy.domain.verified', 1, 'www.tencongty.vn', 'system'],
        ]);
});

it('is idempotent: a second run finds nothing new and leaves the verified names alone', function (): void {
    wdPair(1, 'tencongty.vn');
    $this->dns->pointHere('tencongty.vn', 'www.tencongty.vn');
    $this->artisan('domains:recheck-waiting')->assertSuccessful();
    $this->dns->lookups = 0;

    $this->artisan('domains:recheck-waiting')->expectsOutputToContain('0 verified.')->assertSuccessful();

    // Only the target host is looked up: no verified name is asked about again.
    expect($this->dns->lookups)->toBe(1)->and(count(wdTrail()))->toBe(2);
});

it('verifies the other spelling of a live name once its owner points it', function (): void {
    // tencongty.vn is live; www was checked yesterday and was not pointed yet. The screen tells
    // the owner the platform checks again every 15 minutes, so the run must reach it.
    wdPair(1, 'tencongty.vn', ['verified_at' => now(), 'ssl_status' => 'active'],
        ['failure_reason' => 'not_pointing', 'checked_at' => now()->subDay()]);
    $this->dns->pointHere('tencongty.vn', 'www.tencongty.vn');

    $this->artisan('domains:recheck-waiting')->expectsOutputToContain('1 verified.')->assertSuccessful();

    expect(wdRow('www.tencongty.vn')?->verified_at)->not->toBeNull()
        ->and(wdRow('www.tencongty.vn')?->failure_reason)->toBeNull()
        ->and(wdTrail())->toBe([['tenancy.domain.verified', 1, 'www.tencongty.vn', 'system']]);
});

it('starts where the last run stopped, so a slow first name never keeps the others waiting', function (): void {
    wdPair(1, 'cham.vn');        // never pointed, and its nameserver answers slowly
    wdPair(1, 'tencongty.vn');   // pointed
    $this->dns->pointHere('tencongty.vn', 'www.tencongty.vn');
    // Each lookup of cham.vn's spellings takes five minutes, past the one-minute budget; any other
    // customer name takes one, so a run that checked tencongty.vn has no time left for cham.vn.
    $dns = new class($this->dns) implements DnsLookup
    {
        /** @var list<string> */
        public array $asked = [];

        public function __construct(private FakeDns $dns) {}

        public function addresses(string $host): array
        {
            if (str_ends_with($host, '.vn')) {
                $this->asked[] = $host;
                Carbon::setTestNow(now()->addMinutes(str_ends_with($host, 'cham.vn') ? 5 : 1));
            }

            return $this->dns->addresses($host);
        }

        public function txt(string $host): array
        {
            return $this->dns->txt($host);
        }
    };
    app()->instance(DnsLookup::class, $dns);

    // Run 1 checks the slow pair, both spellings at once, and stops before tencongty.vn.
    $this->artisan('domains:recheck-waiting', ['--budget' => 60])->assertSuccessful();
    expect(array_values(array_unique($dns->asked)))->toBe(['cham.vn', 'www.cham.vn'])
        ->and(wdRow('tencongty.vn')?->verified_at)->toBeNull();

    // Run 2 starts after the pair's second spelling, never at it: cham.vn is not checked again.
    $dns->asked = [];
    $this->artisan('domains:recheck-waiting', ['--budget' => 60])->assertSuccessful();
    expect(array_values(array_unique($dns->asked)))->toBe(['tencongty.vn', 'www.tencongty.vn'])
        ->and(wdRow('tencongty.vn')?->verified_at)->not->toBeNull()
        ->and(wdRow('cham.vn')?->failure_reason)->toBe('not_pointing');

    // Every waiting name was reached: the next run starts from the beginning again.
    $this->artisan('domains:recheck-waiting', ['--budget' => 3600])->assertSuccessful();
    expect(Cache::get(WaitingDomains::RESUME_KEY))->toBe(0);
    Carbon::setTestNow();
});

it('still checks every name and notes the verified ones when the cache cannot keep its place', function (): void {
    wdPair(1, 'tencongty.vn');
    wdPair(1, 'chua-tro.vn');
    $this->dns->pointHere('tencongty.vn', 'www.tencongty.vn');
    // The cache (Valkey) fails on the run's own place: reading it and saving it.
    Cache::extend('resume-down', fn () => Cache::repository(new class extends ArrayStore
    {
        public function get($key): mixed
        {
            return $key === WaitingDomains::RESUME_KEY ? throw new RuntimeException('cache down') : parent::get($key);
        }

        public function forever($key, $value): bool
        {
            return $key === WaitingDomains::RESUME_KEY ? throw new RuntimeException('cache down') : parent::forever($key, $value);
        }
    }));
    config(['cache.stores.resume-down' => ['driver' => 'resume-down'], 'cache.default' => 'resume-down']);
    Exceptions::fake();

    $this->artisan('domains:recheck-waiting')->expectsOutputToContain('2 verified.')->assertSuccessful();

    // Started from the beginning, reached every name, and kept the trail; both failures reported.
    expect(wdRow('chua-tro.vn')?->failure_reason)->toBe('not_pointing')
        ->and(wdTrail())->toBe([
            ['tenancy.domain.verified', 1, 'tencongty.vn', 'system'],
            ['tenancy.domain.verified', 1, 'www.tencongty.vn', 'system'],
        ]);
    Exceptions::assertReportedCount(2);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'cache down');
});

it('never starts a failing streak on a name that went live between its lookups and the write', function (): void {
    wdPair(1, 'tencongty.vn');
    $check = app(CheckDomain::class);
    $probe = $check->probe('tencongty.vn', 1);   // the 15-minute run: not pointed yet
    expect($probe->pointing)->toBe('not_pointing')->and($probe->twin?->pointing)->toBe('not_pointing');

    // Meanwhile the owner points it and presses "Kiểm tra": both spellings go live.
    $this->dns->pointHere('tencongty.vn', 'www.tencongty.vn');
    app(Tenancy::class)->enter(1, fn () => app(CheckDomain::class)->handle('tencongty.vn'));
    $live = [wdRow('tencongty.vn'), wdRow('www.tencongty.vn')];
    Event::fake([DomainNeedsAttention::class]);

    $result = app(Tenancy::class)->enter(1, fn () => $check->apply($probe));

    expect($result->streak)->toBeNull()
        ->and($result->twin)->toBeNull()
        ->and([wdRow('tencongty.vn'), wdRow('www.tencongty.vn')])->toEqual($live)
        ->and(wdRow('tencongty.vn')?->failing_since)->toBeNull()
        ->and(wdRow('tencongty.vn')?->failure_reason)->toBeNull();
    Event::assertNotDispatched(DomainNeedsAttention::class);
});

it('looks only at names that never went live: not at live, failing or stopped ones', function (): void {
    wdPair(1, 'song.vn', ['verified_at' => now(), 'ssl_status' => 'active'], ['verified_at' => now(), 'ssl_status' => 'active']);
    wdPair(1, 'hong.vn', ['verified_at' => now(), 'failing_since' => now()->subDays(3), 'failure_reason' => 'not_pointing'], ['verified_at' => now()]);
    wdPair(1, 'mat.vn', ['failing_since' => now()->subDays(40), 'failure_reason' => 'not_pointing'], ['failing_since' => now()->subDays(40)]);
    $this->dns->pointHere('hong.vn', 'mat.vn');
    $this->dns->lookups = 0;

    $this->artisan('domains:recheck-waiting')->expectsOutputToContain('0 verified.')->assertSuccessful();

    expect($this->dns->lookups)->toBe(1)   // the target host only
        ->and(wdRow('mat.vn')?->verified_at)->toBeNull()
        ->and(wdRow('hong.vn')?->failing_since)->not->toBeNull();
});

it('takes a contested name over once each spelling\'s proof is published, and notes it on the site that lost it', function (): void {
    wdPair(2, 'tranh-chap.vn', ['verified_at' => now(), 'ssl_status' => 'active'], ['verified_at' => now(), 'ssl_status' => 'active']);
    wdPair(1, 'tranh-chap.vn', ['failure_reason' => 'needs_proof'], ['failure_reason' => 'needs_proof']);
    $this->dns->pointHere('tranh-chap.vn', 'www.tranh-chap.vn');
    $proof = app(OwnershipProof::class);

    // Only the running spelling's line so far: it alone is proved; the other keeps waiting.
    $this->dns->txt['_site-verification.tranh-chap.vn'] = [$proof->recordValue(1, 'tranh-chap.vn')];
    $this->artisan('domains:recheck-waiting')->assertSuccessful();

    expect(wdRow('tranh-chap.vn')?->verified_at)->not->toBeNull()
        ->and(wdRow('www.tranh-chap.vn')?->verified_at)->toBeNull()
        ->and(wdRow('www.tranh-chap.vn')?->failure_reason)->toBe('needs_proof')
        ->and(wdRow('tranh-chap.vn', 2))->toBeNull()
        ->and(wdRow('www.tranh-chap.vn', 2))->not->toBeNull();

    // The second line arrives: the next run picks up the redirecting spelling on its own.
    $this->dns->txt['_site-verification.www.tranh-chap.vn'] = [$proof->recordValue(1, 'www.tranh-chap.vn')];
    $this->artisan('domains:recheck-waiting')->assertSuccessful();

    expect(wdRow('www.tranh-chap.vn')?->verified_at)->not->toBeNull()
        ->and(wdRow('www.tranh-chap.vn', 2))->toBeNull()
        ->and(wdTrail())->toBe([
            ['tenancy.domain.verified', 1, 'tranh-chap.vn', 'system'],
            ['tenancy.domain.taken_over', 2, 'tranh-chap.vn', 'system'],
            ['tenancy.domain.verified', 1, 'www.tranh-chap.vn', 'system'],
            ['tenancy.domain.taken_over', 2, 'www.tranh-chap.vn', 'system'],
        ]);
});

it('keeps a contested name waiting while its proof is missing', function (): void {
    wdPair(2, 'tranh-chap.vn', ['verified_at' => now()], ['verified_at' => now()]);
    wdPair(1, 'tranh-chap.vn', ['failure_reason' => 'needs_proof']);
    $this->dns->pointHere('tranh-chap.vn', 'www.tranh-chap.vn');

    $this->artisan('domains:recheck-waiting')->expectsOutputToContain('0 verified.')->assertSuccessful();

    expect(wdRow('tranh-chap.vn')?->failure_reason)->toBe('needs_proof')
        ->and(wdRow('tranh-chap.vn', 2)?->verified_at)->not->toBeNull();
});

it('checks nothing when the target host itself does not resolve', function (): void {
    wdPair(1, 'tencongty.vn');
    $this->dns->pointHere('tencongty.vn', 'www.tencongty.vn');
    $this->dns->addresses['target.platform.test'] = [];

    $this->artisan('domains:recheck-waiting')->expectsOutputToContain('0 verified.')->assertSuccessful();

    expect(wdRow('tencongty.vn')?->checked_at)->toBeNull();
});

it('stops taking new names once its time budget is spent', function (): void {
    wdPair(1, 'tencongty.vn');
    $this->dns->pointHere('tencongty.vn', 'www.tencongty.vn');

    $this->artisan('domains:recheck-waiting', ['--budget' => 0])->expectsOutputToContain('0 verified.')->assertSuccessful();

    expect(wdRow('tencongty.vn')?->checked_at)->toBeNull();
});

it('runs every 15 minutes, in the background, never two at once', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $e): bool => str_contains((string) $e->command, 'domains:recheck-waiting'));

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('*/15 * * * *')
        ->and($event?->withoutOverlapping)->toBeTrue()
        ->and($event?->expiresAt)->toBe(20)
        ->and($event?->runInBackground)->toBeTrue();
});
