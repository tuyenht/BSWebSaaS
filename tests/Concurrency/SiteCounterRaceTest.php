<?php

use App\Modules\Media\Actions\PresignMediaUpload;
use App\Modules\Media\Actions\RegisterMediaUpload;
use App\Modules\Media\Actions\TrashMedia;
use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Console\PurgeTrashedMedia;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\EnsureMediaFromKey;
use App\Modules\Media\Support\MediaStorage;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Services\SiteCounters;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * Two uploads of one website counting themselves at the same moment. Each change is one upsert on
 * the website's counter row, so the second waits on that row until the first commits and then adds
 * to the first's total: nothing is lost, and with the storage switch on two files near the ceiling
 * never both pass. Another website's counter does not wait.
 *
 * PHP runs one statement at a time, so the race is staged on two real connections: the first
 * upload's change runs on pgsql_admin and stays uncommitted while the second runs on the app
 * connection with a short lock_timeout. The other way round, another request's change runs on
 * pgsql_admin from inside the code under test (a query listener, right after its statement on the
 * counter), with a short lock_timeout of its own: it either gets in (the code held nothing) or gives
 * up and runs again once the code has committed (CustomerPlanChangeRaceTest's way).
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

function scrValue(int $site, string $metric = MediaStorage::BYTES): int
{
    return (int) DB::connection('pgsql_admin')->table('site_counters')
        ->where('site_id', $site)->where('metric', $metric)->value('value');
}

/**
 * Another request's change on pgsql_admin, in a transaction of its own, waiting at most 300 ms for a
 * row lock: true when it committed, false when a row stayed locked.
 *
 * @param  list<array{0: string, 1: list<int|string>}>  $statements
 */
function scrRival(array $statements): bool
{
    $admin = DB::connection('pgsql_admin');
    try {
        $admin->transaction(function () use ($admin, $statements): void {
            $admin->statement("set local lock_timeout = '300ms'");
            foreach ($statements as [$sql, $bindings]) {
                $admin->statement($sql, $bindings);
            }
        });
    } catch (QueryException $e) {
        if (($e->errorInfo[0] ?? null) === '55P03') {
            return false;
        }
        throw $e;
    }

    return true;
}

/** Runs $rival once, right after the first app-connection statement whose SQL contains $marker. */
function scrRivalAfter(string $marker, Closure $rival): void
{
    $fired = false;
    DB::listen(function (QueryExecuted $query) use (&$fired, $marker, $rival): void {
        if ($fired || $query->connectionName === 'pgsql_admin' || ! str_contains(strtolower($query->sql), $marker)) {
            return;
        }
        $fired = true;
        $rival();
    });
}

/** A trashed library file of site 1 made straight on the model (no action counts it). */
function scrFile(string $name, int $size, bool $trashed = false): Media
{
    $media = Media::query()->create([
        'collection' => 'library', 'slug' => $name, 'hash' => hash('sha256', $name),
        'storage_key' => "s1/library/{$name}.png", 'mime' => 'image/png', 'size' => $size,
    ]);
    if ($trashed) {
        $media->delete();
    }

    return $media;
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

it('loses no change of another request that lands while a change is counted', function (string $how): void {
    scrAttempt(1, fn (SiteCounters $c) => $c->add(MediaStorage::BYTES, 100));
    $inline = null;
    scrRivalAfter('site_counters', function () use (&$inline): void {
        $inline = scrRival([["update site_counters set value = value + 40 where site_id = 1 and metric = 'media.bytes'", []]]);
    });

    app(Tenancy::class)->enter(1, fn () => $how === 'add'
        ? app(SiteCounters::class)->add(MediaStorage::BYTES, 7)
        : app(SiteCounters::class)->addMany([MediaStorage::BYTES => 7, MediaStorage::TRASH => 3]));

    // the other request found the row held until the change committed, and went in after it
    expect($inline)->toBeFalse()
        ->and(scrRival([["update site_counters set value = value + 40 where site_id = 1 and metric = 'media.bytes'", []]]))->toBeTrue()
        ->and(scrValue(1))->toBe(147);
})->with(['add', 'addMany']);

it('lets exactly one of two files near the ceiling pass, whichever reads the total first', function (): void {
    scrAttempt(1, fn (SiteCounters $c) => $c->add(MediaStorage::BYTES, 90)); // ceiling 100: room for one 8-byte file
    $rival = ["update site_counters set value = value + 8 where site_id = 1 and metric = 'media.bytes' and value + 8 <= 100", []];
    $inline = null;
    scrRivalAfter('site_counters', function () use (&$inline, $rival): void {
        $inline = scrRival([$rival]);
    });

    $passed = app(Tenancy::class)->enter(1, fn (): bool => app(SiteCounters::class)->tryAdd(MediaStorage::BYTES, 8, 100));

    expect($passed)->toBeTrue()->and($inline)->toBeFalse();
    scrRival([$rival]); // once the first committed, the other file no longer fits
    expect(scrValue(1))->toBe(98);
});

it('bounds a change\'s wait for a counter row another transaction holds, and puts the setting back', function (): void {
    scrAttempt(1, fn (SiteCounters $c) => $c->add(MediaStorage::BYTES, 100));
    $admin = DB::connection('pgsql_admin');
    $admin->beginTransaction();

    try {
        $admin->update("update site_counters set value = value + 1 where site_id = 1 and metric = 'media.bytes'");

        $failed = null;
        try {
            app(Tenancy::class)->enter(1, function (): void {
                DB::statement("set local statement_timeout = '8s'"); // a hang would end here, as query_canceled
                app(SiteCounters::class)->add(MediaStorage::BYTES, 7);
            });
        } catch (QueryException $e) {
            $failed = $e->errorInfo[0] ?? null;
        }
        expect($failed)->toBe('55P03'); // lock_not_available: it gave up after SiteCounters::LOCK_WAIT
    } finally {
        $admin->commit();
    }

    // a transaction with no bound of its own has none again afterwards; one with its own keeps it
    $after = app(Tenancy::class)->enter(1, function (): array {
        app(SiteCounters::class)->add(MediaStorage::BYTES, 7);
        $free = DB::scalar("select current_setting('lock_timeout')");
        DB::statement("set local lock_timeout = '300ms'");
        app(SiteCounters::class)->add(MediaStorage::BYTES, 7);

        return [$free, DB::scalar("select current_setting('lock_timeout')")];
    });
    expect($after)->toBe(['0', '300ms'])->and(scrValue(1))->toBe(115);
});

it('never holds the totals while the nightly purge deletes a later page of files', function (): void {
    Storage::fake('r2');
    $count = PurgeTrashedMedia::CHUNK + 1; // two pages
    app(Tenancy::class)->enter(1, function () use ($count): void {
        foreach (range(1, $count) as $i) {
            scrFile("old-{$i}", 10, trashed: true);
        }
        Media::onlyTrashed()->update(['deleted_at' => now()->subDays(40)]);
        app(SiteCounters::class)->addMany([MediaStorage::BYTES => $count * 10 + 500, MediaStorage::TRASH => $count * 10]);
    });

    // an upload of the same website counts itself while the second page is being deleted
    $deletes = 0;
    $inline = null;
    DB::listen(function (QueryExecuted $query) use (&$deletes, &$inline): void {
        if ($query->connectionName === 'pgsql_admin' || ! str_starts_with(strtolower($query->sql), 'delete from "media" where')) {
            return;
        }
        if (++$deletes === PurgeTrashedMedia::CHUNK + 1) {
            $inline = scrRival([["update site_counters set value = value + 64 where site_id = 1 and metric = 'media.bytes'", []]]);
        }
    });

    test()->artisan('media:purge-trash')->assertSuccessful();

    expect($deletes)->toBe($count)
        ->and($inline)->toBeTrue()
        ->and(scrValue(1))->toBe(500 + 64)
        ->and(scrValue(1, MediaStorage::TRASH))->toBe(0);
});

it('never holds the totals while counters:reconcile recounts a drifted website, and loses no change made meanwhile', function (): void {
    $files = app(Tenancy::class)->enter(1, function (): array {
        $files = [scrFile('a', 1000), scrFile('b', 250, trashed: true), scrFile('c', 5)];
        // drifted: the files hold 1255; the Trash total is right
        app(SiteCounters::class)->addMany([MediaStorage::BYTES => 9000, MediaStorage::TRASH => 250]);

        return $files;
    });

    // right after each recount statement of site 1 (the lock-free check, then the correction),
    // another request trashes a file and counts it, as TrashMedia does
    $plan = [$files[0], $files[2]];
    $results = [];
    DB::listen(function (QueryExecuted $query) use (&$plan, &$results): void {
        if ($plan === [] || $query->connectionName === 'pgsql_admin' || ! str_contains(strtolower($query->sql), 'from media')
            || ($query->bindings[0] ?? null) !== 1) {
            return;
        }
        $file = array_shift($plan);
        $results[] = scrRival([
            ['update media set deleted_at = now() where id = ?', [$file->id]],
            ["update site_counters set value = value + ? where site_id = 1 and metric = 'media.trash_bytes'", [$file->size]],
        ]);
    });

    test()->artisan('counters:reconcile')->assertSuccessful();

    $true = (array) DB::connection('pgsql_admin')->selectOne(
        'select sum(size) as bytes, coalesce(sum(size) filter (where deleted_at is not null), 0) as trash from media where site_id = 1',
    );
    expect($results)->toBe([true, true])->and((int) $true['trash'])->toBe(1255)
        ->and(scrValue(1))->toBe((int) $true['bytes'])
        ->and(scrValue(1, MediaStorage::TRASH))->toBe((int) $true['trash']);
});

/** A real 1×1 PNG; $tail after its end makes another file (another hash) that still sniffs as PNG. */
function scrPng(string $tail): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        true,
    ).$tail;
}

/**
 * Runs $work while another transaction (on pgsql_admin) holds site 1's `media.bytes` row, as a slow
 * upload of that website would; the SQLSTATE $work failed with, or null.
 */
function scrWhileBytesHeld(Closure $work): ?string
{
    $admin = DB::connection('pgsql_admin');
    $admin->beginTransaction();
    try {
        $admin->update("update site_counters set value = value where site_id = 1 and metric = 'media.bytes'");
        $work();
    } catch (QueryException $e) {
        return is_string($e->errorInfo[0] ?? null) ? $e->errorInfo[0] : 'query';
    } finally {
        $admin->rollBack();
    }

    return null;
}

it('keeps a website’s Trash and its files when its purge cannot count, and still purges the next website', function (): void {
    Storage::fake('r2');
    $old = [];
    foreach ([1, 2] as $site) {
        $old[$site] = app(Tenancy::class)->enter($site, function () use ($site): Media {
            $media = app(UploadMedia::class)->handle(UploadedFile::fake()->createWithContent("old-{$site}.png", scrPng("old-{$site}")));
            app(TrashMedia::class)->trash(new Collection([$media]));
            Media::onlyTrashed()->whereKey($media->id)->update(['deleted_at' => now()->subDays(40)]);

            return $media;
        });
    }

    // site 1's purge cannot take its storage total (another request holds it past SiteCounters::LOCK_WAIT)
    $exit = null;
    $failed = scrWhileBytesHeld(function () use (&$exit): void {
        $exit = test()->artisan('media:purge-trash')->run();
    });

    expect($failed)->toBeNull()->and($exit)->toBe(1); // the command ended, non-zero

    // site 1: the file is still in the Trash, its object still stored, the totals as they were
    expect(app(Tenancy::class)->enter(1, fn () => Media::onlyTrashed()->pluck('id')->all()))->toBe([$old[1]->id])
        ->and(Storage::disk('r2')->exists($old[1]->storage_key))->toBeTrue()
        ->and(scrValue(1))->toBe($old[1]->size)
        ->and(scrValue(1, MediaStorage::TRASH))->toBe($old[1]->size);

    // site 2 came after it and was purged
    expect(app(Tenancy::class)->enter(2, fn () => Media::withTrashed()->count()))->toBe(0)
        ->and(Storage::disk('r2')->exists($old[2]->storage_key))->toBeFalse()
        ->and(scrValue(2))->toBe(0)
        ->and(scrValue(2, MediaStorage::TRASH))->toBe(0);
});

it('keeps no stored copy of a new file whose count cannot be written', function (string $path, bool $enforce): void {
    Storage::fake('r2');
    config(['media.storage_enforce' => $enforce]);
    scrAttempt(1, fn (SiteCounters $c) => $c->add(MediaStorage::BYTES, 100)); // the total's row exists

    $tempKey = '_incoming/x/01race.png';
    if ($path === 'register') {
        Storage::disk('r2')->put($tempKey, scrPng('new'));
        Cache::put(PresignMediaUpload::ownershipKey($tempKey), 1, now()->addMinutes(5));
    }
    $failed = scrWhileBytesHeld(fn () => app(Tenancy::class)->enter(1, fn (): Media => $path === 'upload'
        ? app(UploadMedia::class)->handle(UploadedFile::fake()->createWithContent('new.png', scrPng('new')))
        : app(RegisterMediaUpload::class)->handle($tempKey, 'new.png')));

    expect($failed)->toBe('55P03')
        ->and(app(Tenancy::class)->enter(1, fn () => Media::withTrashed()->count()))->toBe(0)
        ->and(Storage::disk('r2')->allFiles())->toBe([])
        ->and(scrValue(1))->toBe(100);
})->with([
    'an upload, switch off' => ['upload', false],
    'an upload, switch on' => ['upload', true],
    'a presigned upload, switch off' => ['register', false],
    'a presigned upload, switch on' => ['register', true],
]);

/*
 * A seed files the shared demo files in one long transaction of its own (SeedAnVinhContent and the
 * like). Their rows record size 0 (the bytes are not fetched), so the seed adds nothing to the total
 * and never takes its row: an upload of that website meanwhile does not wait for the seed to end.
 */
it('never holds the totals while a seed files its files', function (): void {
    Storage::fake('r2');
    scrAttempt(1, fn (SiteCounters $c) => $c->add(MediaStorage::BYTES, 100));
    Storage::disk('r2')->put('an-vinh/hero/hero-home-7ca90eafe212.jpg', 'seed');

    $inline = app(Tenancy::class)->enter(1, function (): bool {
        app(EnsureMediaFromKey::class)->fromSeedSource('an-vinh/hero/hero-home-7ca90eafe212.jpg');

        return scrRival([["update site_counters set value = value + 64 where site_id = 1 and metric = 'media.bytes'", []]]);
    });

    expect($inline)->toBeTrue()->and(scrValue(1))->toBe(164)
        ->and(app(Tenancy::class)->enter(1, fn () => Media::query()->count()))->toBe(1);
});
