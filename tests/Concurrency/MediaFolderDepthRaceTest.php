<?php

use App\Modules\Media\Models\MediaFolder;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Media folders have two levels at most, and the database refuses a third (or a cycle) with the
 * media_folders_two_levels trigger. Two moves at once could each pass that check against the
 * other's old state: A into B while B goes into C makes C → B → A, and A into B while B goes into A
 * makes a cycle. The trigger therefore takes the website's folder-tree advisory lock first, so the
 * second move waits for the first to finish, then checks against what it committed.
 *
 * PHP runs one statement at a time, so the race is staged on two real connections, like the other
 * lock tests here: the first move runs on pgsql_admin and stays uncommitted while the second runs
 * on the app connection (app_user, RLS on) with a short lock_timeout. The two moves write different
 * rows, so the only thing the second can wait on is the folder-tree lock: it times out (55P03).
 * Once the first has committed, the second runs again and is refused (23514).
 */

beforeEach(function (): void {
    seedTenancy();
    $this->ids = app(Tenancy::class)->enter(1, fn (): array => collect(['a', 'b', 'c'])
        ->mapWithKeys(fn (string $slug, int $i): array => [$slug => MediaFolder::query()->create(['name' => strtoupper($slug), 'slug' => $slug, 'position' => $i + 1])->id])
        ->all());
});

/** Gives folder $id the parent $parentId with one raw UPDATE. */
function mfRaceMove(int $id, int $parentId): void
{
    DB::statement('update media_folders set parent_id = ? where id = ?', [$parentId, $id]);
}

/** Runs $move on the app connection in site 1, waiting at most 300 ms for a lock: null, or the SQLSTATE it failed with. */
function mfRaceOnAppConnection(Closure $move): ?string
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql');

    try {
        return app(Tenancy::class)->enter(1, function () use ($move): ?string {
            expect(DB::scalar('select current_user'))->toBe('app_user');
            DB::statement("set local lock_timeout = '300ms'");
            try {
                $move();
            } catch (QueryException $e) {
                return (string) $e->getCode();
            }

            return null;
        });
    } finally {
        DB::setDefaultConnection($previous);
    }
}

/** Runs $first on pgsql_admin in site 1 and keeps it uncommitted while $second runs on the app connection; returns what the second got. */
function mfRaceWhileFirstOpen(Closure $first, Closure $second): ?string
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');

    try {
        return app(Tenancy::class)->enter(1, function () use ($first, $second): ?string {
            $first();

            return mfRaceOnAppConnection($second);
        });
    } finally {
        DB::setDefaultConnection($previous);
    }
}

/** @return array<string, int|null> each folder's parent, committed (read past RLS) */
function mfRaceParents(): array
{
    return DB::connection('pgsql_admin')->table('media_folders')->where('site_id', 1)->orderBy('slug')->pluck('parent_id', 'slug')
        ->map(fn ($id): ?int => $id === null ? null : (int) $id)->all();
}

it('makes a move that would give a third level wait for a concurrent move, then refuses it', function (): void {
    ['a' => $a, 'b' => $b, 'c' => $c] = $this->ids;

    // A goes into B (open), while B goes into C: B would get a parent while it has a sub-folder
    $second = mfRaceWhileFirstOpen(fn () => mfRaceMove($a, $b), fn () => mfRaceMove($b, $c));
    expect($second)->toBe('55P03')
        ->and(mfRaceParents())->toBe(['a' => $b, 'b' => null, 'c' => null]);

    // now that A is in B, the same move is refused, and the tree keeps two levels
    expect(mfRaceOnAppConnection(fn () => mfRaceMove($b, $c)))->toBe('23514')
        ->and(mfRaceParents())->toBe(['a' => $b, 'b' => null, 'c' => null]);
});

it('makes a move that would close a cycle wait for a concurrent move, then refuses it', function (): void {
    ['a' => $a, 'b' => $b] = $this->ids;

    // A goes into B (open), while B goes into A
    $second = mfRaceWhileFirstOpen(fn () => mfRaceMove($a, $b), fn () => mfRaceMove($b, $a));
    expect($second)->toBe('55P03')
        ->and(mfRaceParents())->toBe(['a' => $b, 'b' => null, 'c' => null]);

    expect(mfRaceOnAppConnection(fn () => mfRaceMove($b, $a)))->toBe('23514')
        ->and(mfRaceParents())->toBe(['a' => $b, 'b' => null, 'c' => null]);
});

it('lets a move into a sub-folder\'s parent wait for, then follow, a concurrent move that keeps two levels', function (): void {
    ['a' => $a, 'b' => $b, 'c' => $c] = $this->ids;

    // A goes into B (open), while C goes into B too: both are fine once they run one after the other
    expect(mfRaceWhileFirstOpen(fn () => mfRaceMove($a, $b), fn () => mfRaceMove($c, $b)))->toBe('55P03');
    expect(mfRaceOnAppConnection(fn () => mfRaceMove($c, $b)))->toBeNull()
        ->and(mfRaceParents())->toBe(['a' => $b, 'b' => null, 'c' => $b]);
});
