<?php

use App\Modules\Storefront\Models\ContactClickDaily;
use App\Modules\Storefront\Support\ContactClickCounter;
use App\Modules\Storefront\Support\ContactClickStats;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Chrome\FloatingContact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/*
 * The buffered contact button taps become daily tallies: contact:flush-clicks adds each tapped
 * site's buffer to its contact_click_daily rows (the site's own calendar day), keeps what it could
 * not write for the next run, and never lets one site stop the others; contact:prune-clicks keeps
 * 90 days; ContactClickStats reads the last days of the current site.
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

/** A tally row written straight into the site's table. */
function seedClickTally(int $site, string $day, string $channel, string $placement, int $clicks): void
{
    app(Tenancy::class)->enter($site, fn () => ContactClickDaily::query()->create([
        'day' => $day, 'channel' => $channel, 'placement' => $placement, 'clicks' => $clicks,
    ]));
}

it('adds the buffered taps to the site\'s daily tallies and empties the buffer', function (): void {
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

it('accepts exactly the built-in channels and the custom keys in its channel check', function (): void {
    $definition = (string) DB::connection('pgsql_admin')->scalar(
        "select pg_get_constraintdef(oid) from pg_constraint where conname = 'contact_click_daily_channel_check'",
    );
    preg_match_all("/'([a-z]+)'::/", $definition, $m);

    expect($m[1])->toEqualCanonicalizing(array_keys(FloatingContact::CHANNELS))
        ->and($definition)->toContain('^c[1-9][0-9]{0,5}$');
});
