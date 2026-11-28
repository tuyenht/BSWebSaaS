<?php

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteCounter;
use App\Modules\Tenancy\Services\SiteCounters;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * site_counters, the websites' running totals: one row per (website, metric, subject), written only
 * by SiteCounters with an upsert in the caller's transaction. Each website sees and writes its own
 * rows alone (RLS, fail closed); a total never goes below 0; tryAdd() adds only under a ceiling.
 */

beforeEach(function (): void {
    seedTenancy(); // sites 1 / 2; the cascade empties site_counters
});

function scAdd(int $site, string $metric, int $delta): void
{
    app(Tenancy::class)->enter($site, fn () => app(SiteCounters::class)->add($metric, $delta));
}

/** @return array<string, int> */
function scRead(int $site, string ...$metrics): array
{
    return app(Tenancy::class)->enter($site, fn (): array => app(SiteCounters::class)->read(array_values($metrics))['values']);
}

it('keeps each website to its own counters (RLS, fail closed)', function (): void {
    scAdd(1, 'media.bytes', 500);
    scAdd(2, 'media.bytes', 7);

    expect(scRead(1, 'media.bytes'))->toBe(['media.bytes' => 500])
        ->and(scRead(2, 'media.bytes'))->toBe(['media.bytes' => 7])
        // site 2 sees one row: its own
        ->and(app(Tenancy::class)->enter(2, fn () => DB::table('site_counters')->pluck('value', 'site_id')->all()))->toBe([2 => 7])
        // the model's global scope and RLS agree
        ->and(app(Tenancy::class)->enter(2, fn () => SiteCounter::query()->sum('value')))->toEqual(7);

    // a row for another website cannot be written from this one (WITH CHECK)
    expect(fn () => app(Tenancy::class)->enter(2, fn () => DB::insert(
        "insert into site_counters (site_id, metric, subject_id, value, updated_at) values (1, 'media.trash_bytes', 0, 1, now())",
    )))->toThrow(QueryException::class);

    // nor changed (the row of site 1 is invisible, so nothing is updated)
    $changed = app(Tenancy::class)->enter(2, fn (): int => DB::update("update site_counters set value = 0 where site_id = 1 and metric = 'media.bytes'"));
    expect($changed)->toBe(0)->and(scRead(1, 'media.bytes'))->toBe(['media.bytes' => 500]);

    // with no website chosen, none is visible
    DB::transaction(function (): void {
        expect(DB::table('site_counters')->count())->toBe(0);
    });
});

it('adds with one upsert per change and never goes below zero', function (): void {
    scAdd(1, 'media.bytes', 100);
    scAdd(1, 'media.bytes', 50);
    expect(scRead(1, 'media.bytes'))->toBe(['media.bytes' => 150]);

    scAdd(1, 'media.bytes', -400); // a drift would take it lower: it stops at 0
    expect(scRead(1, 'media.bytes'))->toBe(['media.bytes' => 0]);

    scAdd(1, 'media.trash_bytes', -10); // a first change below zero creates the row at 0
    expect(scRead(1, 'media.trash_bytes', 'media.never'))->toBe(['media.trash_bytes' => 0, 'media.never' => 0])
        ->and(app(Tenancy::class)->enter(1, fn () => SiteCounter::query()->where('metric', 'media.trash_bytes')->value('value')))->toBe(0);
});

it('adds under a ceiling only while the total stays at or under it', function (): void {
    $try = fn (int $delta, int $ceiling): bool => app(Tenancy::class)->enter(1, fn (): bool => app(SiteCounters::class)->tryAdd('media.bytes', $delta, $ceiling));

    expect($try(80, 50))->toBeFalse()                 // no row yet, and 80 alone does not fit
        ->and(scRead(1, 'media.bytes'))->toBe(['media.bytes' => 0])
        ->and($try(40, 100))->toBeTrue()
        ->and($try(60, 100))->toBeTrue()                 // exactly the ceiling fits
        ->and($try(1, 100))->toBeFalse()                 // one more does not
        ->and(scRead(1, 'media.bytes'))->toBe(['media.bytes' => 100])
        ->and($try(-30, 0))->toBeTrue()                  // taking away always goes through
        ->and(scRead(1, 'media.bytes'))->toBe(['media.bytes' => 70]);
});

it('refuses a metric name that is not a dotted lower-case word', function (): void {
    expect(fn () => scAdd(1, 'Media Bytes', 1))->toThrow(QueryException::class);
});
