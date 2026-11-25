<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * media:rekey-site rewrites a post's meta while an editor may be saving the same post. Its rewrite
 * reads the row, then writes it back with the new key; a save committed in between would be lost.
 * So it locks each content row it rewrites (FOR NO KEY UPDATE, which an UPDATE of the row still
 * waits for) in the transaction that writes it: a save either commits before the lock (the rewrite
 * then reads it) or waits until the rewrite commits.
 *
 * Staged on two real connections, like the other lock tests here: the rival save runs on pgsql_admin
 * with a short lock_timeout, from inside the run, right after the rekey has read the post's text
 * (the moment a rewrite without the lock would be exposed). Under the lock the rival gives up
 * (55P03) and saves again once the run is over; both its change and the new key are kept. Once in a
 * chunk of moves, once in the pass that repairs an old key written back after an earlier run.
 */

const REKEY_RACE_KEY = 'cu-race/library/hero-ab12cd.jpg';

beforeEach(function (): void {
    seedTenancy();
    Storage::fake(MediaKey::disk());
    DB::connection('pgsql_admin')->table('reserved_site_codes')->insert(['code' => 'cu-race', 'reason' => 'legacy_key', 'site_id' => 1]);
});

/** The rival save: sets the post's subtitle, as another editor's request would. False when the row stayed locked. */
function rekeyRivalSave(int $entryId, string $subtitle): bool
{
    $admin = DB::connection('pgsql_admin');

    try {
        $admin->transaction(function () use ($admin, $entryId, $subtitle): void {
            $admin->statement("SET LOCAL lock_timeout = '500ms'");
            $admin->update("update entries set meta = meta || jsonb_build_object('subtitle', ?::text) where id = ?", [$subtitle, $entryId]);
        });
    } catch (QueryException $e) {
        if (($e->errorInfo[0] ?? null) === '55P03') {   // lock_not_available
            return false;
        }

        throw $e;
    }

    return true;
}

it('never loses a save that races its rewrite of a post', function (string $pass): void {
    Storage::disk(MediaKey::disk())->put(REKEY_RACE_KEY, 'bytes');
    app(Tenancy::class)->enter(1, fn () => Media::query()->create([
        'collection' => 'library', 'slug' => 'hero', 'hash' => hash('sha256', REKEY_RACE_KEY),
        'storage_key' => REKEY_RACE_KEY, 'disk' => 'r2', 'mime' => 'image/jpeg', 'size' => 5,
    ]));
    $post = app(Tenancy::class)->enter(1, fn (): Entry => Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => REKEY_RACE_KEY]]));
    $newKey = siteCode(1).'/hero-ab12cd.jpg';

    if ($pass === 'repair') {
        expect(Artisan::call('media:rekey-site', ['site' => '1', '--apply' => true]))->toBe(0);
        // An editor opened before that run saves the old key back.
        DB::connection('pgsql_admin')->table('entries')->where('id', $post->id)->update(['meta' => json_encode(['image_url' => REKEY_RACE_KEY])]);
    }

    // The read of the post's text that the rewrite writes back: in a chunk (by id, after the lock
    // taken first) or in the repair pass (a keyset read that takes the lock itself).
    $rivalFirst = null;
    DB::listen(function (QueryExecuted $query) use (&$rivalFirst, $post, $pass): void {
        $sql = strtolower($query->sql);
        if ($rivalFirst !== null || $query->connectionName === 'pgsql_admin' || ! str_contains($sql, 'meta::text as doc from entries')) {
            return;
        }
        if (! str_contains($sql, $pass === 'chunk' ? 'and id in (' : 'and id > ?')) {
            return;
        }
        $rivalFirst = false;   // set before running it, so the rival's own queries never re-enter
        $rivalFirst = rekeyRivalSave($post->id, 'Mới');
    });

    $exit = Artisan::call('media:rekey-site', ['site' => '1', '--apply' => true]);

    expect($exit)->toBe(0, Artisan::output())
        ->and($rivalFirst)->toBeFalse();   // the rival did race the rewrite, and the row was locked
    expect(rekeyRivalSave($post->id, 'Mới'))->toBeTrue();   // it saves once the run committed

    $meta = app(Tenancy::class)->enter(1, fn (): array => Entry::query()->findOrFail($post->id)->meta);
    expect($meta)->toEqual(['image_url' => $newKey, 'subtitle' => 'Mới']);
})->with(['chunk', 'repair']);
