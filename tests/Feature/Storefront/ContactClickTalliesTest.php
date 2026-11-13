<?php

use App\Modules\Storefront\Models\ContactClickDaily;
use App\Modules\Storefront\Support\ContactClickCounter;
use App\Modules\Storefront\Support\ContactClickStats;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Chrome\FloatingContact;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/*
 * The buffered contact button taps become daily tallies: contact:flush-clicks adds each tapped
 * site's buffer to its contact_click_daily rows (the site's own calendar day), keeps what it could
 * not write for the next run, never writes the same taps twice, and never lets one site stop the
 * others; contact:prune-clicks keeps 90 days of each site's own calendar; ContactClickStats reads
 * the last days of the current site.
 */

beforeEach(function (): void {
    seedTenancy(); // sites 1 (a.test) / 2 (b.test)
    forgetContactClicks();
});

afterEach(function (): void {
    forgetContactClicks();
    $this->travelBack();
});

/** The site's tallies as "day|channel|placement" → clicks, read inside its own context. */
function clickTallies(int $site): array
{
    return app(Tenancy::class)->enter($site, function (): array {
        $rows = [];
        foreach (ContactClickDaily::query()->orderBy('day')->orderBy('channel')->orderBy('placement')->get() as $row) {
            $rows[$row->day->toDateString().'|'.$row->channel.'|'.$row->placement] = $row->clicks;
        }

        return $rows;
    });
}

/** Save the site's floating contact setting with its own channels `keys` (key → switched on). */
function saveContactChannels(int $site, array $keys): void
{
    $channels = [];
    foreach ($keys as $key => $on) {
        $channels[] = ['key' => $key, 'on' => $on, 'value' => 'https://example.com/'.$key, 'name' => 'Kênh '.$key];
    }
    app(Tenancy::class)->enter($site, fn () => SiteSetting::query()->updateOrCreate(
        ['group_name' => 'storefront', 'key' => 'floating_contact'],
        ['value' => ['enabled' => true, 'channels' => $channels]],
    ));
}

/** A tally row written straight into the site's table. */
function seedClickTally(int $site, string $day, string $channel, string $placement, int $clicks): void
{
    app(Tenancy::class)->enter($site, fn () => ContactClickDaily::query()->create([
        'day' => $day, 'channel' => $channel, 'placement' => $placement, 'clicks' => $clicks,
    ]));
}

it('adds the buffered taps to the site\'s daily tallies and empties the buffer', function (): void {
    saveContactChannels(1, ['c3' => true]);
    $counter = app(ContactClickCounter::class);
    $noonHanoi = CarbonImmutable::parse('2026-11-13 12:00', 'Asia/Ho_Chi_Minh')->getTimestamp();
    $counter->record(1, 'zalo', 'bar', $noonHanoi);
    $counter->record(1, 'zalo', 'bar', $noonHanoi + 1200);   // another slot, the same day
    $counter->record(1, 'c3', 'floating', $noonHanoi);
    $counter->record(2, 'phone', 'dial', $noonHanoi);

    $this->artisan('contact:flush-clicks')->expectsOutputToContain('Flushed 3 contact click row(s) on 2 site(s), 0 failed.')->assertSuccessful();

    expect(clickTallies(1))->toBe(['2026-11-13|c3|floating' => 1, '2026-11-13|zalo|bar' => 2])
        ->and(clickTallies(2))->toBe(['2026-11-13|phone|dial' => 1])
        ->and(Redis::exists('contact_clicks:1', 'contact_clicks:2', 'contact_clicks:flushing:1', 'contact_clicks:flushing:2'))->toBe(0)
        ->and(Redis::smembers('contact_clicks:dirty'))->toBe([]);
});

it('adds a second flush to the tallies instead of overwriting them', function (): void {
    $counter = app(ContactClickCounter::class);
    $at = CarbonImmutable::parse('2026-11-13 09:00', 'Asia/Ho_Chi_Minh')->getTimestamp();
    $counter->record(1, 'messenger', 'sheet', $at);
    $counter->record(1, 'messenger', 'sheet', $at);
    $this->artisan('contact:flush-clicks')->assertSuccessful();

    $counter->record(1, 'messenger', 'sheet', $at + 60);
    $this->artisan('contact:flush-clicks')->assertSuccessful();
    $this->artisan('contact:flush-clicks')->expectsOutputToContain('Flushed 0 contact click row(s) on 0 site(s), 0 failed.')->assertSuccessful();

    expect(clickTallies(1))->toBe(['2026-11-13|messenger|sheet' => 3]);
});

it('files a tap under the site\'s own calendar day', function (): void {
    app(Tenancy::class)->enter(2, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'general', 'key' => 'timezone'], ['value' => ['tz' => 'UTC']]));
    $counter = app(ContactClickCounter::class);
    $lateUtc = CarbonImmutable::parse('2026-11-13 18:30', 'UTC')->getTimestamp(); // 01:30 on the 14th in Hanoi
    $counter->record(1, 'email', 'floating', $lateUtc);
    $counter->record(2, 'email', 'floating', $lateUtc);

    $this->artisan('contact:flush-clicks')->assertSuccessful();

    expect(clickTallies(1))->toBe(['2026-11-14|email|floating' => 1])   // no setting: Asia/Ho_Chi_Minh
        ->and(clickTallies(2))->toBe(['2026-11-13|email|floating' => 1]);
});

it('keeps the taps of a site it could not write for the next run, and still flushes the others', function (): void {
    $counter = app(ContactClickCounter::class);
    $at = CarbonImmutable::parse('2026-11-13 10:00', 'Asia/Ho_Chi_Minh')->getTimestamp();
    $counter->record(1, 'zalo', 'floating', $at);
    $counter->record(1, 'zalo', 'floating', $at);
    $counter->record(2, 'phone', 'bar', $at);

    $failing = true;
    DB::connection()->beforeExecuting(function (string $sql, array $bindings) use (&$failing): void {
        if ($failing && str_starts_with(ltrim($sql), 'insert into contact_click_daily') && ($bindings[0] ?? null) === 1) {
            throw new RuntimeException('database unavailable');
        }
    });

    $this->artisan('contact:flush-clicks')->expectsOutputToContain('on 1 site(s), 1 failed.')->assertFailed();

    expect(clickTallies(1))->toBe([])
        ->and(clickTallies(2))->toBe(['2026-11-13|phone|bar' => 1])
        ->and(array_map(intval(...), Redis::smembers('contact_clicks:dirty')))->toBe([1]);

    // A tap meanwhile, then the database is back: everything lands once, nothing twice.
    $counter->record(1, 'zalo', 'floating', $at + 60);
    $failing = false;
    $this->artisan('contact:flush-clicks')->assertSuccessful();
    $this->artisan('contact:flush-clicks')->assertSuccessful();   // the live hash the first run left waiting

    expect(clickTallies(1))->toBe(['2026-11-13|zalo|floating' => 3])
        ->and(clickTallies(2))->toBe(['2026-11-13|phone|bar' => 1])
        ->and(Redis::exists('contact_clicks:1', 'contact_clicks:flushing:1'))->toBe(0)
        ->and(Redis::smembers('contact_clicks:dirty'))->toBe([]);
});

it('drops a buffered field it never writes instead of failing the site', function (): void {
    Redis::hincrby('contact_clicks:1', 'nonsense', 5);
    Redis::hincrby('contact_clicks:1', '1999999|snapchat|bar', 5);
    Redis::sadd('contact_clicks:dirty', '1');

    $this->artisan('contact:flush-clicks')->expectsOutputToContain('Flushed 0 contact click row(s) on 1 site(s), 0 failed.')->assertSuccessful();

    expect(clickTallies(1))->toBe([])
        ->and(Redis::exists('contact_clicks:1', 'contact_clicks:flushing:1'))->toBe(0);
});

it('counts a site\'s own channel only while its floating contact setting has it', function (): void {
    saveContactChannels(1, ['c3' => true, 'c7' => false]);   // c7 switched off keeps its taps
    $counter = app(ContactClickCounter::class);
    $at = CarbonImmutable::parse('2026-11-13 10:00', 'Asia/Ho_Chi_Minh')->getTimestamp();
    $counter->record(1, 'c3', 'floating', $at);
    $counter->record(1, 'c7', 'floating', $at);
    $counter->record(1, 'c9', 'floating', $at);   // never saved: a made-up channel
    $counter->record(1, 'zalo', 'bar', $at);      // a built-in channel always counts
    $counter->record(2, 'c3', 'bar', $at);        // site 2 has no channel of its own

    $this->artisan('contact:flush-clicks')->expectsOutputToContain('Flushed 3 contact click row(s) on 2 site(s), 0 failed.')->assertSuccessful();

    expect(clickTallies(1))->toBe(['2026-11-13|c3|floating' => 1, '2026-11-13|c7|floating' => 1, '2026-11-13|zalo|bar' => 1])
        ->and(clickTallies(2))->toBe([])
        ->and(Redis::exists('contact_clicks:1', 'contact_clicks:2', 'contact_clicks:applying:1', 'contact_clicks:applying:2'))->toBe(0);
});

it('counts “Mua hàng” taps on every site like a built-in channel, in the phone bar only', function (): void {
    $counter = app(ContactClickCounter::class);
    $at = CarbonImmutable::parse('2026-11-13 10:00', 'Asia/Ho_Chi_Minh')->getTimestamp();
    $counter->record(1, 'buy', 'bar', $at);
    $counter->record(1, 'buy', 'bar', $at + 60);
    $counter->record(1, 'buy', 'floating', $at);   // "Mua hàng" is drawn in the phone bar only
    $counter->record(2, 'buy', 'bar', $at);        // no setting saved: still counted

    $this->artisan('contact:flush-clicks')->expectsOutputToContain('Flushed 2 contact click row(s) on 2 site(s), 0 failed.')->assertSuccessful();

    expect(clickTallies(1))->toBe(['2026-11-13|buy|bar' => 2])
        ->and(clickTallies(2))->toBe(['2026-11-13|buy|bar' => 1])
        ->and(app(Tenancy::class)->enter(1, fn (): array => app(ContactClickStats::class)->lastDays(30)['channels']))->toBe(['buy' => 2]);
});

it('takes no new field of a site\'s own channel once its buffer holds the cap, and still counts built-in channels and the fields it has', function (): void {
    $fields = [];
    for ($slot = 2_000_000; $slot < 2_000_000 + ContactClickCounter::FIELD_CAP; $slot++) {
        $fields["{$slot}|zalo|bar"] = 1;
    }
    Redis::hmset('contact_clicks:1', $fields);
    $counter = app(ContactClickCounter::class);
    $now = CarbonImmutable::parse('2026-11-13 10:00', 'UTC')->getTimestamp();

    $slot = intdiv($now, ContactClickCounter::SLOT_SECONDS);
    $counter->record(1, 'c3', 'bar', $now);                                       // a new field of a custom channel: refused
    $counter->record(1, 'phone', 'bar', $now);                                    // a new field of a built-in channel: counted
    $counter->record(1, 'zalo', 'bar', 2_000_000 * ContactClickCounter::SLOT_SECONDS); // a field it has: counted
    $counter->record(2, 'c3', 'bar', $now);                                       // another site is not affected

    expect(Redis::hlen('contact_clicks:1'))->toBe(ContactClickCounter::FIELD_CAP + 1)
        ->and((int) Redis::hget('contact_clicks:1', '2000000|zalo|bar'))->toBe(2)
        ->and((int) Redis::hget('contact_clicks:1', "{$slot}|phone|bar"))->toBe(1)
        ->and(Redis::hexists('contact_clicks:1', "{$slot}|c3|bar"))->toBeFalse()
        ->and(Redis::hlen('contact_clicks:2'))->toBe(1);
});

it('never writes the same taps twice when Valkey fails right after the write committed', function (): void {
    $counter = app(ContactClickCounter::class);
    $at = CarbonImmutable::parse('2026-11-13 10:00', 'Asia/Ho_Chi_Minh')->getTimestamp();
    $counter->record(1, 'zalo', 'bar', $at);
    $counter->record(1, 'zalo', 'bar', $at);

    // The same Valkey, except that clearing the written taps fails once: the upsert has committed by then.
    $config = config('database.redis');
    $flaky = new class(app(), Arr::pull($config, 'client', 'phpredis'), $config) extends RedisManager
    {
        public bool $failClear = true;

        public function __call($method, $parameters)
        {
            if ($this->failClear && strtolower($method) === 'del' && str_starts_with((string) ($parameters[0] ?? ''), 'contact_clicks:applying:')) {
                $this->failClear = false;

                throw new RuntimeException('Valkey went away');
            }

            return parent::__call($method, $parameters);
        }
    };
    $real = app('redis');
    app()->instance('redis', $flaky);
    Redis::clearResolvedInstance('redis');
    try {
        $this->artisan('contact:flush-clicks')
            ->expectsOutputToContain('the written taps could not be cleared; the next run drops them.')
            ->assertSuccessful();
    } finally {
        app()->instance('redis', $real);
        Redis::clearResolvedInstance('redis');
    }

    expect($flaky->failClear)->toBeFalse()
        ->and(clickTallies(1))->toBe(['2026-11-13|zalo|bar' => 2])
        ->and(Redis::smembers('contact_clicks:dirty'))->toBe([]);

    // The next runs find the written taps left behind and drop them: still 2, not 4.
    $this->artisan('contact:flush-clicks')->assertSuccessful();
    $this->artisan('contact:flush-clicks')->assertSuccessful();

    expect(clickTallies(1))->toBe(['2026-11-13|zalo|bar' => 2])
        ->and(Redis::exists('contact_clicks:1', 'contact_clicks:flushing:1', 'contact_clicks:applying:1'))->toBe(0)
        ->and(Redis::smembers('contact_clicks:dirty'))->toBe([]);
});

it('drains taps a run that died left in its working key, with no new tap to mark the site', function (): void {
    $slot = intdiv(CarbonImmutable::parse('2026-11-13 10:00', 'Asia/Ho_Chi_Minh')->getTimestamp(), ContactClickCounter::SLOT_SECONDS);
    Redis::hincrby('contact_clicks:flushing:2', "{$slot}|phone|sheet", 4);   // taken, never written, not in the dirty set

    $this->artisan('contact:flush-clicks')->expectsOutputToContain('Flushed 1 contact click row(s) on 1 site(s), 0 failed.')->assertSuccessful();

    expect(clickTallies(2))->toBe(['2026-11-13|phone|sheet' => 4])
        ->and(Redis::exists('contact_clicks:flushing:2', 'contact_clicks:applying:2'))->toBe(0)
        ->and(Redis::smembers('contact_clicks:dirty'))->toBe([]);
});

it('drains taps a run killed mid-batch left in a live buffer, with no new tap to mark the site', function (): void {
    $slot = intdiv(CarbonImmutable::parse('2026-11-13 10:00', 'Asia/Ho_Chi_Minh')->getTimestamp(), ContactClickCounter::SLOT_SECONDS);
    Redis::hincrby('contact_clicks:2', "{$slot}|zalo|floating", 3);   // buffered, but already popped off the dirty set

    $this->artisan('contact:flush-clicks')->expectsOutputToContain('Flushed 1 contact click row(s) on 1 site(s), 0 failed.')->assertSuccessful();

    expect(clickTallies(2))->toBe(['2026-11-13|zalo|floating' => 3])
        ->and(Redis::exists('contact_clicks:2', 'contact_clicks:flushing:2'))->toBe(0);
});

it('refuses to claim or put back taps another run already moved, so they are never written twice', function (): void {
    $counter = app(ContactClickCounter::class);

    expect(fn () => $counter->claim(2))->toThrow(RuntimeException::class, 'another run has them')
        ->and(fn () => $counter->release(2))->toThrow(RuntimeException::class, 'cannot be put back');
});

it('drops, once, the taps of a site that no longer exists', function (): void {
    $at = CarbonImmutable::parse('2026-11-13 10:00', 'Asia/Ho_Chi_Minh')->getTimestamp();
    app(ContactClickCounter::class)->record(999, 'zalo', 'bar', $at);   // no site 999: deleted
    app(ContactClickCounter::class)->record(1, 'phone', 'bar', $at);

    $this->artisan('contact:flush-clicks')->expectsOutputToContain('Dropped the taps of 1 site(s) that no longer exist.')->assertSuccessful();

    expect(clickTallies(1))->toBe(['2026-11-13|phone|bar' => 1])
        ->and(Redis::exists('contact_clicks:999', 'contact_clicks:flushing:999', 'contact_clicks:applying:999'))->toBe(0)
        ->and(Redis::smembers('contact_clicks:dirty'))->toBe([]);

    $this->artisan('contact:flush-clicks')->expectsOutputToContain('Flushed 0 contact click row(s) on 0 site(s), 0 failed.')->assertSuccessful();
});

it('shows a site only its own tallies, whatever the query asks (RLS)', function (): void {
    seedClickTally(1, '2026-11-10', 'zalo', 'bar', 4);
    seedClickTally(2, '2026-11-10', 'zalo', 'bar', 9);

    $seen = app(Tenancy::class)->enter(1, fn (): array => [
        DB::table('contact_click_daily')->sum('clicks'),
        DB::table('contact_click_daily')->where('site_id', 2)->count(),
        ContactClickDaily::query()->withoutGlobalScopes()->count(),
    ]);

    expect(array_map(intval(...), $seen))->toBe([4, 0, 1]);
});

it('keeps 90 days of tallies and removes older ones on every site', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 12:00', 'UTC'));
    seedClickTally(1, '2026-08-14', 'zalo', 'bar', 1);   // 91 days ago
    seedClickTally(1, '2026-08-15', 'zalo', 'bar', 2);   // 90 days ago: kept
    seedClickTally(1, '2026-11-13', 'zalo', 'bar', 3);
    seedClickTally(2, '2026-01-01', 'phone', 'dial', 4);

    $this->artisan('contact:prune-clicks')->expectsOutputToContain('Removed 2 contact click row(s) older than 90 days on 2 site(s), 0 failed.')->assertSuccessful();

    expect(clickTallies(1))->toBe(['2026-08-15|zalo|bar' => 2, '2026-11-13|zalo|bar' => 3])
        ->and(clickTallies(2))->toBe([]);
});

it('counts the 90 days back from each site\'s own today', function (): void {
    app(Tenancy::class)->enter(2, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'general', 'key' => 'timezone'], ['value' => ['tz' => 'UTC']]));
    $this->travelTo(CarbonImmutable::parse('2026-11-13 18:30', 'UTC'));   // already the 14th in Hanoi
    seedClickTally(1, '2026-08-15', 'zalo', 'bar', 1);   // 91 days before Hanoi's today
    seedClickTally(2, '2026-08-15', 'zalo', 'bar', 2);   // 90 days before UTC's today: kept

    $this->artisan('contact:prune-clicks')->expectsOutputToContain('Removed 1 contact click row(s) older than 90 days on 2 site(s), 0 failed.')->assertSuccessful();

    expect(clickTallies(1))->toBe([])
        ->and(clickTallies(2))->toBe(['2026-08-15|zalo|bar' => 2]);
});

it('sums the current site\'s last days per channel, placement and day in one query', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 08:00', 'Asia/Ho_Chi_Minh'));
    seedClickTally(1, '2026-11-13', 'zalo', 'bar', 5);
    seedClickTally(1, '2026-11-13', 'phone', 'floating', 2);
    seedClickTally(1, '2026-11-11', 'zalo', 'floating', 1);
    seedClickTally(1, '2026-11-10', 'zalo', 'floating', 7);   // before the last 3 days
    seedClickTally(2, '2026-11-13', 'phone', 'bar', 50);      // another site

    $queries = [];
    $stats = app(Tenancy::class)->enter(1, function () use (&$queries): array {
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        return app(ContactClickStats::class)->lastDays(3);
    });

    expect($stats)->toBe([
        'total' => 8,
        'channels' => ['zalo' => 6, 'phone' => 2],
        'placements' => ['bar' => 5, 'floating' => 3],
        'days' => ['2026-11-11' => 1, '2026-11-12' => 0, '2026-11-13' => 7],
    ])->and(array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'contact_click_daily'))))->toHaveCount(1);

    $sql = collect($queries)->first(fn (string $sql): bool => str_contains($sql, 'contact_click_daily'));
    expect($sql)->toContain('"contact_click_daily"."site_id" = ?')->not->toContain('*');
});

it('accepts exactly the built-in channels, “Mua hàng” and the custom keys in its channel check', function (): void {
    $definition = (string) DB::connection('pgsql_admin')->scalar(
        "select pg_get_constraintdef(oid) from pg_constraint where conname = 'contact_click_daily_channel_check'",
    );
    preg_match_all("/'([a-z]+)'::/", $definition, $m);

    expect($m[1])->toEqualCanonicalizing([...array_keys(FloatingContact::CHANNELS), ContactClickCounter::BUY])
        ->and($definition)->toContain('^c[1-9][0-9]{0,5}$');
});

it('takes “Mua hàng” out of the channel check only while no “Mua hàng” tally exists, and puts it back', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2057_01_01_000000_add_buy_to_contact_click_channels.php');
    seedClickTally(1, '2026-11-13', 'buy', 'bar', 4);
    $admin = DB::connection('pgsql_admin');
    $definition = fn (): string => (string) $admin->scalar(
        "select pg_get_constraintdef(oid) from pg_constraint where conname = 'contact_click_daily_channel_check'",
    );
    $insertBuy = fn () => $admin->table('contact_click_daily')->insert(['site_id' => 2, 'day' => '2026-11-13', 'channel' => 'buy', 'placement' => 'bar', 'clicks' => 1]);

    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'channel = buy');
        $refused = $definition();

        $admin->table('contact_click_daily')->where('channel', 'buy')->delete();
        $migration->down();
        $narrow = $definition();
        $buyRefused = false;
        try {
            $insertBuy();
        } catch (QueryException) {
            $buyRefused = true;
        }
    } finally {
        $migration->up();
        DB::setDefaultConnection($original);
    }

    expect($refused)->toContain("'buy'::")
        ->and($narrow)->not->toContain("'buy'")->toContain('^c[1-9][0-9]{0,5}$')
        ->and($buyRefused)->toBeTrue()
        ->and($definition())->toContain("'buy'::")->not->toContain('NOT VALID')
        ->and($insertBuy)->not->toThrow(QueryException::class)
        ->and(clickTallies(2))->toBe(['2026-11-13|buy|bar' => 1]);
});

it('puts “Mua hàng” back in the channel check when a tally arrives while it is being taken out', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2057_01_01_000000_add_buy_to_contact_click_channels.php');
    $admin = DB::connection('pgsql_admin');
    $definition = fn (): string => (string) $admin->scalar(
        "select pg_get_constraintdef(oid) from pg_constraint where conname = 'contact_click_daily_channel_check'",
    );

    // A visitor's tap is flushed right after down() looked and found no "Mua hàng" tally.
    $arrived = false;
    DB::listen(function (QueryExecuted $query) use ($admin, &$arrived): void {
        if (! $arrived && $query->connectionName === 'pgsql_admin' && str_contains($query->sql, 'exists') && str_contains($query->sql, 'contact_click_daily')) {
            $arrived = true;
            $admin->table('contact_click_daily')->insert(['site_id' => 1, 'day' => '2026-11-13', 'channel' => 'buy', 'placement' => 'bar', 'clicks' => 1]);
        }
    });

    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'channel = buy');
    } finally {
        DB::setDefaultConnection($original);
    }

    expect($arrived)->toBeTrue()
        ->and($definition())->toContain("'buy'::")->not->toContain('NOT VALID')
        ->and(clickTallies(1))->toBe(['2026-11-13|buy|bar' => 1]);
});

it('repairs a channel check left narrow by a run that stopped half way, and still refuses', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2057_01_01_000000_add_buy_to_contact_click_channels.php');
    $admin = DB::connection('pgsql_admin');
    $definition = fn (): string => (string) $admin->scalar(
        "select pg_get_constraintdef(oid) from pg_constraint where conname = 'contact_click_daily_channel_check'",
    );
    seedClickTally(1, '2026-11-13', 'buy', 'bar', 2);
    $narrow = "'".implode("', '", array_keys(FloatingContact::CHANNELS))."'";
    $admin->statement('ALTER TABLE contact_click_daily DROP CONSTRAINT contact_click_daily_channel_check');
    $admin->statement("ALTER TABLE contact_click_daily ADD CONSTRAINT contact_click_daily_channel_check CHECK (channel IN ({$narrow}) OR channel ~ '^c[1-9][0-9]{0,5}\$') NOT VALID");

    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'channel = buy');
    } finally {
        DB::setDefaultConnection($original);
    }

    expect($definition())->toContain("'buy'::")->not->toContain('NOT VALID')
        ->and(clickTallies(1))->toBe(['2026-11-13|buy|bar' => 2]);
});
