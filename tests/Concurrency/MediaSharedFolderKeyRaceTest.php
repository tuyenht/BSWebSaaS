<?php

use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Two websites with the same name own one old media folder together (`trung-ten/…`). A key under it
 * may belong to only one of them, or trashing one copy would delete the other's file. The
 * media_storage_key_prefix_guard trigger looks for the key under the other owner, but two inserts
 * at once would each miss the other's uncommitted row, so it takes an advisory lock on the key first:
 * the second insert waits for the first to finish, then sees what it committed.
 *
 * Staged on two real connections, like the other lock tests here: site A's insert runs on pgsql_admin
 * and stays uncommitted while site B inserts the same key on the app connection (app_user, RLS on)
 * with a short lock_timeout. The rows differ in site_id, so no unique index makes B wait: only the
 * key's advisory lock does, and B times out (55P03). Once A has committed, B is refused (23514).
 */

const MEDIA_RACE_KEY = 'trung-ten/library/logo-ab12cd.png';

beforeEach(function (): void {
    seedTenancy();
    $admin = DB::connection('pgsql_admin');
    $this->reservedBefore = (int) $admin->table('reserved_site_codes')->max('id');
    $admin->table('reserved_site_codes')->insert([
        ['code' => 'trung-ten', 'reason' => 'legacy_key', 'site_id' => 1],
        ['code' => 'trung-ten', 'reason' => 'legacy_key', 'site_id' => 2],
    ]);
});

afterEach(function (): void {
    DB::connection('pgsql_admin')->table('reserved_site_codes')->where('id', '>', $this->reservedBefore)->delete();
});

function mediaRaceInsert(string $key): void
{
    Media::query()->create([
        'collection' => 'library', 'slug' => 'logo', 'hash' => hash('sha256', $key),
        'storage_key' => $key, 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1,
    ]);
}

/** Inserts $key on the app connection for site $site, waiting at most 300 ms for a lock: null, or the SQLSTATE it failed with. */
function mediaRaceOnAppConnection(int $site, string $key): ?string
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql');

    try {
        return app(Tenancy::class)->enter($site, function () use ($key): ?string {
            expect(DB::scalar('select current_user'))->toBe('app_user');
            DB::statement("set local lock_timeout = '300ms'");
            try {
                mediaRaceInsert($key);
            } catch (QueryException $e) {
                return (string) ($e->errorInfo[0] ?? $e->getCode());
            }

            return null;
        });
    } finally {
        DB::setDefaultConnection($previous);
    }
}

/** @return list<int> the websites holding $key, committed (read past RLS) */
function mediaRaceHolders(string $key): array
{
    return DB::connection('pgsql_admin')->table('media')->where('storage_key', $key)->orderBy('site_id')->pluck('site_id')
        ->map(fn ($id): int => (int) $id)->all();
}

it('makes a second website\'s insert of the same key under a shared folder wait for the first, then refuses it', function (): void {
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');

    try {
        // Site A inserts the key and stays open while site B inserts it too.
        $second = app(Tenancy::class)->enter(1, function (): ?string {
            mediaRaceInsert(MEDIA_RACE_KEY);

            return mediaRaceOnAppConnection(2, MEDIA_RACE_KEY);
        });
    } finally {
        DB::setDefaultConnection($previous);
    }

    expect($second)->toBe('55P03')
        ->and(mediaRaceHolders(MEDIA_RACE_KEY))->toBe([1]);

    // Now that A has committed, B is refused, and the key keeps one website.
    expect(mediaRaceOnAppConnection(2, MEDIA_RACE_KEY))->toBe('23514')
        ->and(mediaRaceHolders(MEDIA_RACE_KEY))->toBe([1]);
});

it('lets two websites insert different keys under a shared folder at the same time', function (): void {
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');

    try {
        $second = app(Tenancy::class)->enter(1, function (): ?string {
            mediaRaceInsert(MEDIA_RACE_KEY);

            return mediaRaceOnAppConnection(2, 'trung-ten/library/khac-ab12cd.png');
        });
    } finally {
        DB::setDefaultConnection($previous);
    }

    expect($second)->toBeNull()
        ->and(mediaRaceHolders(MEDIA_RACE_KEY))->toBe([1])
        ->and(mediaRaceHolders('trung-ten/library/khac-ab12cd.png'))->toBe([2]);
});
