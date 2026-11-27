<?php

use App\Modules\Media\Support\MediaStorage;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Services\SiteCounters;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Two uploads of one website counting themselves at the same moment. Each change is one upsert on
 * the website's counter row, so the second waits on that row until the first commits and then adds
 * to the first's total: nothing is lost, and with the storage switch on two files near the ceiling
 * never both pass. Another website's counter does not wait.
 *
 * PHP runs one statement at a time, so the race is staged on two real connections: the first
 * upload's change runs on pgsql_admin and stays uncommitted while the second runs on the app
 * connection with a short lock_timeout.
 */

beforeEach(function (): void {
    seedTenancy(); // sites 1 / 2; the cascade empties site_counters
});

/** The second upload's change on the app connection, waiting at most 300 ms for the row. */
function scrAttempt(int $site, callable $change): mixed
{
    return app(Tenancy::class)->enter($site, function () use ($change): mixed {
        DB::statement("set local lock_timeout = '300ms'");

        return $change(app(SiteCounters::class));
    });
}

function scrValue(int $site): int
{
    return (int) DB::connection('pgsql_admin')->table('site_counters')
        ->where('site_id', $site)->where('metric', MediaStorage::BYTES)->value('value');
}

it('makes the second change wait for the first and then counts both', function (): void {
    scrAttempt(1, fn (SiteCounters $c) => $c->add(MediaStorage::BYTES, 100));
    $admin = DB::connection('pgsql_admin');
    $admin->beginTransaction();

    try {
        // the first upload has counted itself and not committed yet
        $admin->update("update site_counters set value = value + 40 where site_id = 1 and metric = 'media.bytes'");

        $waited = null;
        try {
            scrAttempt(1, fn (SiteCounters $c) => $c->add(MediaStorage::BYTES, 7));
        } catch (QueryException $e) {
            $waited = $e->errorInfo[0] ?? null;
        }
        expect($waited)->toBe('55P03'); // it waited on the row: it did not read 100 and write 107

        // another website is not held up
        scrAttempt(2, fn (SiteCounters $c) => $c->add(MediaStorage::BYTES, 5));
    } finally {
        $admin->commit();
    }

    // the retry adds to the committed total: both changes count
    scrAttempt(1, fn (SiteCounters $c) => $c->add(MediaStorage::BYTES, 7));
    expect(scrValue(1))->toBe(147)->and(scrValue(2))->toBe(5);
});

it('lets exactly one of two files near the ceiling pass', function (): void {
    scrAttempt(1, fn (SiteCounters $c) => $c->add(MediaStorage::BYTES, 90)); // ceiling 100: room for one 8-byte file
    $admin = DB::connection('pgsql_admin');
    $admin->beginTransaction();

    try {
        // the first file fits and has counted itself, not committed yet
        $passed = $admin->select("update site_counters set value = value + 8 where site_id = 1 and metric = 'media.bytes' and value + 8 <= 100 returning value");
        expect($passed)->toHaveCount(1);

        $waited = null;
        try {
            scrAttempt(1, fn (SiteCounters $c): bool => $c->tryAdd(MediaStorage::BYTES, 8, 100));
        } catch (QueryException $e) {
            $waited = $e->errorInfo[0] ?? null;
        }
        expect($waited)->toBe('55P03'); // it waited instead of passing on the old total
    } finally {
        $admin->commit();
    }

    // once the first committed, the second does not fit
    expect(scrAttempt(1, fn (SiteCounters $c): bool => $c->tryAdd(MediaStorage::BYTES, 8, 100)))->toBeFalse()
        ->and(scrValue(1))->toBe(98);
});
