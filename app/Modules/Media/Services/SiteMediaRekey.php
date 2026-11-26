<?php

declare(strict_types=1);

namespace App\Modules\Media\Services;

use App\Modules\Media\Exceptions\SeedFileCopyFailedException;
use App\Modules\Media\Exceptions\UnplannableMediaKeyException;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Media\Support\MediaKeyRewriter;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use League\Flysystem\FilesystemException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Moves a website's files filed before website codes (`an-vinh/hero/x-ab12.jpg`,
 * `{slug(name)}/library/x-ab12.jpg`) to `{code}/{file}`, the address every new upload already gets,
 * and rewrites the places that name a file by its key instead of its id. The steps, which the
 * media:rekey-site command runs in this order (each opens its own tenant transactions):
 *
 * 1. plan(): every media row of the website, the Trash included, whose key is not under its code,
 *    with its new key: the same file name under the code; on a clash with a key the website already
 *    holds (under any extension, as MediaKey::unique() judges a stem) the hash suffix grows. A key
 *    that cannot be given one (no file name with an extension) is listed as skipped, for a person.
 * 2. copyObjects(): a server-side copy of each object to its new key, outside any transaction; the
 *    old object stays, so every address already printed or shared keeps working. An object already
 *    at the new key is kept only when its checksum (ETag on R2/S3) equals the source's, what an
 *    interrupted run leaves; any other object there, or one that cannot be compared, makes the hash
 *    suffix grow instead.
 * 3. moveChunk(), MOVE_CHUNK moves per transaction: the content rows that name one of the chunk's
 *    old keys are locked (FOR NO KEY UPDATE, which still lets a usage row's foreign key check
 *    through), then the chunk's media rows (FOR UPDATE); each row still holding its planned key
 *    gets the new one and `meta.moved_from` (the old one, its own object, which a permanent delete
 *    then removes too; an old key under a demo seed source, `media.seed_folders`, is a shared seed
 *    file instead and goes in `meta.seed_source`, which nothing deletes), and the locked content
 *    rows are rewritten, all in one commit. A save that changes one of those content rows
 *    meanwhile either commits first (the rewrite then reads it)
 *    or waits for the commit; it is never lost. Content before media is the order an entry save
 *    takes them (it writes the entry, then its usage rows, whose foreign key share-locks the media
 *    row). A brand save takes them the other way round (SetBrandImages locks the media rows, then
 *    the settings row), so it can conflict with a chunk: PostgreSQL then aborts one of the two
 *    (deadlock, or a lock wait past lock_timeout), and a chunk that lost is retried whole, up to
 *    MOVE_TRIES times with a short pause.
 * 4. rewriteReferences() with previousKeys(): every content row of the website that still names an
 *    old key of ANY moved row, from this run or an earlier one, or the shared seed file a seeded
 *    row was copied from (`meta.seed_source`), is rewritten (a chunk of rows per transaction, FOR
 *    NO KEY UPDATE). So an old key written back after its chunk committed (an editor
 *    opened before the run saving) is repaired by this pass or by the next run, and a dry run counts
 *    it as a reference to rewrite.
 *
 * 5. seedAdoptions() and adoptSeedFiles(), between steps 3 and 4: a demo seed file (`media.seed_folders`)
 *    that a live place names (bare or as an address on the media host) while the website holds no row
 *    for it, nor one copied from it, is adopted: EnsureMediaFromKey::fromSeedSource() copies it to
 *    `{code}/{file}` and makes the row with `meta.seed_source`, the seeder's own path, so step 4
 *    rewrites the reference like any other (its address form kept). History (a revision snapshot)
 *    is never a reason to adopt; a seed file under a folder with an owner other than this website
 *    (another website's, or one several share), missing from storage, with the name of another one
 *    adopted, or whose new key is taken, is left as it is and reported, and so is one whose copy fails
 *    or whose key another writer took (the rest are still adopted). Any other error stops the run.
 *
 * A row whose key is already under the code is never planned, so a run stopped part way leaves its
 * committed chunks consistent (keys and references together), and running again finishes the rest.
 * Single-site installs keep the collection layout (`media.tenant_prefix` false) and are never
 * rekeyed.
 *
 * Stateless; Octane-safe.
 */
final readonly class SiteMediaRekey
{
    /** Rows read or written at a time. */
    public const int CHUNK = 500;

    /** Moves per transaction. */
    public const int MOVE_CHUNK = 200;

    /** Tries of one chunk that lost a lock conflict (deadlock or lock_timeout), the first included. */
    public const int MOVE_TRIES = 3;

    /** SQLSTATEs of a lost lock conflict: deadlock_detected, lock_not_available. */
    private const array LOCK_CONFLICTS = ['40P01', '55P03'];

    /** SQLSTATE unique_violation. */
    private const string UNIQUE_VIOLATION = '23505';

    /** A table or column name the reference scan may be given (they are code constants, never input). */
    private const string IDENTIFIER = '/\A[a-z_][a-z0-9_]*\z/';

    public function __construct(private Tenancy $tenancy, private EnsureMediaFromKey $ensure) {}

    /**
     * Every row of the website whose key is not under its code, with its new key, and the keys that
     * cannot be given one (re-keyed by hand), with why. Reads only.
     *
     * @return array{moves: list<array{id: int, from: string, to: string, hash: string, mime: string}>, skipped: list<array{key: string, reason: string}>}
     */
    public function plan(int $siteId, string $code): array
    {
        if (preg_match(MediaKey::CODE_PATTERN, $code) !== 1) {
            throw new InvalidArgumentException("'{$code}' is not a website code.");
        }

        return $this->tenancy->enter($siteId, function () use ($siteId, $code): array {
            $moves = [];
            $skipped = [];
            $assigned = [];
            $lastId = 0;
            do {
                $rows = DB::table('media')
                    ->where('site_id', $siteId)
                    ->where('id', '>', $lastId)
                    ->whereRaw("split_part(storage_key, '/', 1) <> ?", [$code])
                    ->orderBy('id')
                    ->limit(self::CHUNK)
                    ->get(['id', 'storage_key', 'hash', 'mime']);

                foreach ($rows as $row) {
                    $id = Cast::int($row->id);
                    $from = Cast::string($row->storage_key);
                    $hash = Cast::string($row->hash);
                    $lastId = $id;
                    try {
                        $to = $this->target($siteId, $code, $from, $hash, $assigned);
                    } catch (UnplannableMediaKeyException $e) {
                        $skipped[] = ['key' => $from, 'reason' => $e->getMessage()];

                        continue;
                    }
                    $assigned[self::stem($to)] = true;
                    $moves[] = ['id' => $id, 'from' => $from, 'to' => $to, 'hash' => $hash, 'mime' => Cast::string($row->mime, 'application/octet-stream')];
                }
            } while ($rows->count() === self::CHUNK);

            return ['moves' => $moves, 'skipped' => $skipped];
        });
    }

    /**
     * Old key => current key of every row of the website that was moved (`meta.moved_from`) or
     * copied from a shared seed file (`meta.seed_source`), the Trash included, for the reference
     * pass. An old key some row holds again is left out (a reference to it is right as it is); one
     * shared by two rows maps to the live, oldest one, a moved row before a seeded one.
     *
     * @return array<string, string>
     */
    public function previousKeys(int $siteId): array
    {
        return $this->tenancy->enter($siteId, function () use ($siteId): array {
            $rows = DB::select(<<<'SQL'
                SELECT DISTINCT ON (k.old_key) k.old_key AS previous_key, m.storage_key
                FROM media m
                CROSS JOIN LATERAL (VALUES (m.meta->>'moved_from', 0), (m.meta->>'seed_source', 1)) AS k(old_key, rank)
                WHERE m.site_id = ? AND k.old_key IS NOT NULL AND k.old_key <> m.storage_key
                  AND NOT EXISTS (SELECT 1 FROM media o WHERE o.site_id = m.site_id AND o.storage_key = k.old_key)
                ORDER BY k.old_key, (m.deleted_at IS NOT NULL), k.rank, m.id
                SQL, [$siteId]);

            $keys = [];
            foreach ($rows as $row) {
                if (is_object($row) && isset($row->previous_key, $row->storage_key)) {
                    $keys[Cast::string($row->previous_key)] = Cast::string($row->storage_key);
                }
            }

            return $keys;
        });
    }

    /**
     * The demo seed files (`media.seed_folders`) the website's $places name, bare or as an address on
     * the media host, that it holds no row for and that $known (old key => current key) does not
     * place, each with the key adopting it gives (`{code}/{file}`, fromSeedSource()'s). One whose
     * object is not in storage is listed as missing (never given a row). Listed as blocked: one under
     * a folder with an owner other than this website (closedFolders(): another website's, or one
     * shared by several, this one included: a website never takes in another's file); one with the file name of
     * another seed file adopted before it; one whose new key, or its stem under another extension, is
     * another file of the website or a key of $planned (the plan's new keys, not written yet in a dry
     * run); one whose object there differs. Reads only.
     *
     * @param  list<array{0: string, 1: string}>  $places  [table, jsonb column]; every table has id + site_id
     * @param  array<string, string>  $known
     * @param  list<string>  $planned
     * @return array{adopt: array<string, string>, missing: list<string>, blocked: list<array{key: string, reason: string}>}
     */
    public function seedAdoptions(int $siteId, string $code, array $places, array $known, array $planned = []): array
    {
        self::assertPlaces($places);
        $patterns = array_map(static fn (string $folder): string => '%'.self::likeEscape($folder).'/%', Cast::strings(config('media.seed_folders', [])));
        $result = ['adopt' => [], 'missing' => [], 'blocked' => []];
        if ($patterns === []) {
            return $result;
        }

        $rewriter = new MediaKeyRewriter([]);
        $mentions = 'LIKE ANY (ARRAY['.implode(', ', array_fill(0, count($patterns), '?')).']::text[])';
        $named = [];
        foreach ($places as [$table, $column]) {
            $lastId = 0;
            do {
                $count = $this->tenancy->enter($siteId, function () use ($siteId, $rewriter, $table, $column, $mentions, $patterns, &$named, &$lastId): int {
                    $rows = DB::select(
                        "SELECT id, {$column}::text AS doc FROM {$table} WHERE site_id = ? AND id > ? AND {$column}::text {$mentions} ORDER BY id LIMIT ".self::CHUNK,
                        [$siteId, $lastId, ...$patterns],
                    );
                    foreach ($rows as $row) {
                        if (! is_object($row) || ! isset($row->id, $row->doc)) {
                            continue;
                        }
                        $lastId = Cast::int($row->id);
                        foreach ($rewriter->seedKeys(json_decode(Cast::string($row->doc), false, 512, JSON_THROW_ON_ERROR)) as $key) {
                            $named[$key] = true;
                        }
                    }

                    return count($rows);
                });
            } while ($count === self::CHUNK);
        }

        $keys = array_map(strval(...), array_keys(array_diff_key($named, $known)));
        sort($keys);
        if ($keys === []) {
            return $result;
        }
        $targets = array_combine($keys, array_map(static fn (string $key): string => "{$code}/".basename($key), $keys));
        $held = $this->tenancy->enter($siteId, function () use ($siteId, $targets): array {
            $held = [];
            foreach (array_chunk([...array_keys($targets), ...array_values($targets)], self::CHUNK) as $chunk) {
                foreach (DB::table('media')->where('site_id', $siteId)->whereIn('storage_key', $chunk)->pluck('storage_key') as $key) {
                    $held[Cast::string($key)] = true;
                }
            }

            return $held;
        });

        $closed = $this->closedFolders($siteId, array_map(static fn (string $key): string => explode('/', $key, 2)[0], $keys));
        $assigned = array_fill_keys(array_map(self::stem(...), $planned), true);
        $adopting = [];
        $disk = Storage::disk(MediaKey::disk());
        foreach ($targets as $key => $target) {
            if (isset($held[$key])) {
                continue; // a row holds the seed file itself: plan() moves it
            }
            $folder = explode('/', $key, 2)[0];
            if (isset($closed[$folder])) {
                $result['blocked'][] = ['key' => $key, 'reason' => $closed[$folder]];

                continue;
            }
            if (! $disk->exists($key)) {
                $result['missing'][] = $key;

                continue;
            }
            $ext = pathinfo($target, PATHINFO_EXTENSION);
            $stem = self::stem($target);
            if (isset($adopting[$stem])) {
                $result['blocked'][] = ['key' => $key, 'reason' => "same name as {$adopting[$stem]}"];
            } elseif (isset($held[$target]) || $this->tenancy->enter($siteId, fn (): bool => $this->stemTaken($siteId, $stem, $ext, $assigned))) {
                $result['blocked'][] = ['key' => $key, 'reason' => "the website already has another file at {$target}"];
            } elseif ($disk->exists($target) && ! $this->sameObject($disk, $key, $target)) {
                $result['blocked'][] = ['key' => $key, 'reason' => "another object is stored at {$target}"];
            } elseif (preg_match(MediaKey::CLEAN_PATH_PATTERN, $target) !== 1) {
                $result['blocked'][] = ['key' => $key, 'reason' => "{$target} is not a clean key"];
            } else {
                $result['adopt'][$key] = $target;
                $adopting[$stem] = $key;
            }
        }

        return $result;
    }

    /**
     * Those of $folders the website may not adopt from, each with why: a folder with several owners
     * (`legacy_key` rows with a website), $siteId among them or not ("shared folder"), or whose one
     * owner is another website ("another website's folder"). Adopted are only the files of a folder
     * with no owner, or whose only owner is $siteId.
     *
     * A folder without an owner is a seed folder no website held a row in when it was frozen
     * (2098_01_01_000000_close_open_media_folders), or one whose owner was hard-deleted: the AFTER
     * DELETE trigger on sites leaves an ownerless row where no other owner is left. The demo-folder
     * files of such a folder may then be adopted by any website that names them; those bytes are
     * public (served from the media host), so a copy reveals nothing new. `reserved_site_codes` is
     * platform-wide, so no tenant context is needed.
     *
     * @param  list<string>  $folders
     * @return array<string, string>
     */
    private function closedFolders(int $siteId, array $folders): array
    {
        $owners = [];
        $rows = DB::table('reserved_site_codes')->where('reason', 'legacy_key')->whereNotNull('site_id')
            ->whereIn('code', array_values(array_unique($folders)))->get(['code', 'site_id']);
        foreach ($rows as $row) {
            $owners[Cast::string($row->code)][Cast::int($row->site_id)] = true;
        }

        $closed = [];
        foreach ($owners as $folder => $ids) {
            if (count($ids) > 1) {
                $closed[$folder] = 'shared folder';
            } elseif (! isset($ids[$siteId])) {
                $closed[$folder] = "another website's folder";
            }
        }

        return $closed;
    }

    /**
     * Adopts each seed file of $adopt (seed key => expected key) for the website, in its tenant
     * context, through EnsureMediaFromKey::fromSeedSource(): the object is copied to its new key and
     * the row made with `meta.seed_source`, so previousKeys() places every reference to the seed key.
     * Returns the seed keys adopted (=> their row's key) and those that failed, each with why: one
     * whose storage copy failed, or whose row lost to another writer of its key (a unique violation;
     * each in its own transaction, so it left no row; the next seed is still tried), or whose row
     * came out otherwise (a person took the key meanwhile). A failed one's references stay as they
     * are, and running again tries it again. Any other error (a tenancy assertion among them) is
     * thrown on: it is no reason to go on with the next seed.
     *
     * @param  array<string, string>  $adopt
     * @return array{adopted: array<string, string>, failed: list<array{key: string, reason: string}>}
     */
    public function adoptSeedFiles(int $siteId, array $adopt): array
    {
        $result = ['adopted' => [], 'failed' => []];
        foreach ($adopt as $key => $target) {
            try {
                $media = $this->tenancy->enter($siteId, fn (): Media => $this->ensure->fromSeedSource($key));
            } catch (SeedFileCopyFailedException|FilesystemException $e) {
                $result['failed'][] = ['key' => $key, 'reason' => 'copy failed: '.$e->getMessage()];

                continue;
            } catch (Throwable $e) {
                if (! self::uniqueViolation($e)) {
                    throw $e;
                }
                $result['failed'][] = ['key' => $key, 'reason' => "{$target} was taken meanwhile"];

                continue;
            }
            $meta = $media->getAttribute('meta');
            if ($media->storage_key === $target && is_array($meta) && ($meta['seed_source'] ?? null) === $key) {
                $result['adopted'][$key] = $target;
            } else {
                $result['failed'][] = ['key' => $key, 'reason' => "{$target} was taken meanwhile"];
            }
        }

        return $result;
    }

    /**
     * Copies each planned object to its new key ($progress gets the number done). Returns the moves
     * with the key each one ends up with (a grown suffix where another object sat at the new key).
     *
     * @param  list<array{id: int, from: string, to: string, hash: string, mime: string}>  $moves
     * @return array{moves: list<array{id: int, from: string, to: string, hash: string, mime: string}>, copied: int, present: int, regrown: int, missing: list<string>}
     */
    public function copyObjects(int $siteId, string $code, array $moves, ?Closure $progress = null): array
    {
        $disk = Storage::disk(MediaKey::disk());

        $taken = [];
        foreach ($moves as $move) {
            $taken[self::stem($move['to'])] = true;
        }
        $copied = 0;
        $present = 0;
        $regrown = 0;
        $missing = [];

        foreach ($moves as $i => $move) {
            $hasSource = $disk->exists($move['from']);
            $reuse = false;
            while ($disk->exists($move['to'])) {
                if ($hasSource && $this->sameObject($disk, $move['from'], $move['to'])) {
                    $reuse = true;

                    break;
                }
                // Not a copy of this file (or not one that can be told): never reuse it.
                $taken[self::stem($move['to'])] = true;
                $move['to'] = $this->tenancy->enter($siteId, fn (): string => $this->target($siteId, $code, $move['from'], $move['hash'], $taken));
                $taken[self::stem($move['to'])] = true;
                $regrown++;
            }
            $moves[$i] = $move;

            if ($reuse) {
                $present++;
            } elseif (! $hasSource) {
                $missing[] = $move['from']; // already gone from storage: only the key moves
            } elseif (MediaKey::copyAs($disk, $move['from'], $move['to'], $move['mime'])) {
                $copied++;
            } else {
                throw new RuntimeException("Copying '{$move['from']}' to '{$move['to']}' failed; nothing was written to the database. Run again.");
            }

            if ($progress !== null && (($i + 1) % self::CHUNK === 0 || $i === count($moves) - 1)) {
                $progress($i + 1);
            }
        }

        return ['moves' => $moves, 'copied' => $copied, 'present' => $present, 'regrown' => $regrown, 'missing' => $missing];
    }

    /**
     * One transaction for one chunk of moves (at most MOVE_CHUNK): locks the content rows naming
     * the chunk's old keys, then its media rows; moves each row still holding its planned key (one a
     * person changed meanwhile waits for the next run), records the old key in `meta.moved_from`
     * (`meta.seed_source` for a shared seed file, MediaKey::isSeedKey()),
     * and rewrites the locked content rows. A chunk that lost a lock conflict (a brand save takes
     * media before settings) left nothing and is run again whole, MOVE_TRIES times at most.
     *
     * @param  list<array{id: int, from: string, to: string, hash: string, mime: string}>  $chunk
     * @param  list<array{0: string, 1: string}>  $places  [table, jsonb column]; every table has id + site_id
     * @return array{moved: array<string, string>, references: array<string, array{rows: int, references: int, unmatched: int, samples: list<string>, other_host: int, other_samples: list<string>}>}
     */
    public function moveChunk(int $siteId, array $chunk, array $places): array
    {
        if (count($chunk) > self::MOVE_CHUNK) {
            throw new InvalidArgumentException('At most '.self::MOVE_CHUNK.' moves per transaction.');
        }
        self::assertPlaces($places);

        for ($try = 1; ; $try++) {
            try {
                return $this->moveChunkOnce($siteId, $chunk, $places);
            } catch (Throwable $e) {
                // Inside an outer transaction the conflict aborted that one too: only its owner can retry.
                if ($try >= self::MOVE_TRIES || DB::transactionLevel() > 0 || ! self::lostLockConflict($e)) {
                    throw $e;
                }
                usleep(100_000 * $try);
            }
        }
    }

    /**
     * Whether $e is a lock conflict PostgreSQL resolved by aborting this transaction (deadlock, or
     * a lock wait past lock_timeout), so running it again can succeed.
     */
    public static function lostLockConflict(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof DeadlockException) {
                return true;   // Laravel's rethrow out of a savepoint, code dropped
            }
            $state = $current instanceof QueryException ? ($current->errorInfo[0] ?? null) : null;
            if ($state === null && $current instanceof PDOException) {
                $state = (string) $current->getCode();
            }
            if (in_array($state, self::LOCK_CONFLICTS, true)) {
                return true;
            }
        }

        return false;
    }

    /** Whether $e is a unique violation (SQLSTATE 23505): another writer took the key first. */
    private static function uniqueViolation(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof UniqueConstraintViolationException) {
                return true;
            }
            $state = $current instanceof QueryException ? ($current->errorInfo[0] ?? null) : null;
            if ($state === null && $current instanceof PDOException) {
                $state = (string) $current->getCode();
            }
            if ($state === self::UNIQUE_VIOLATION) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{id: int, from: string, to: string, hash: string, mime: string}>  $chunk
     * @param  list<array{0: string, 1: string}>  $places
     * @return array{moved: array<string, string>, references: array<string, array{rows: int, references: int, unmatched: int, samples: list<string>, other_host: int, other_samples: list<string>}>}
     */
    private function moveChunkOnce(int $siteId, array $chunk, array $places): array
    {
        return $this->tenancy->enter($siteId, function () use ($siteId, $chunk, $places): array {
            // 1. The content rows that name one of the old keys, locked before any media row.
            $patterns = array_map(static fn (array $move): string => '%'.self::likeEscape($move['from']).'%', $chunk);
            $locked = [];
            foreach ($places as [$table, $column]) {
                $locked["{$table}.{$column}"] = $patterns === [] ? [] : array_map(
                    static fn (mixed $row): int => is_object($row) && isset($row->id) ? Cast::int($row->id) : 0,
                    DB::select(
                        "SELECT id FROM {$table} WHERE site_id = ? AND {$column}::text LIKE ANY (ARRAY[".implode(', ', array_fill(0, count($patterns), '?')).']::text[]) ORDER BY id FOR NO KEY UPDATE',
                        [$siteId, ...$patterns],
                    ),
                );
            }

            // 2. The media rows, moved only while they still hold the planned key.
            $held = DB::table('media')
                ->where('site_id', $siteId)
                ->whereIn('id', array_column($chunk, 'id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('storage_key', 'id')
                ->all();

            $moved = [];
            $values = [];
            $bindings = [];
            foreach ($chunk as $move) {
                if (($held[$move['id']] ?? null) !== $move['from']) {
                    continue;
                }
                $values[] = '(?::bigint, ?::text, ?::text, ?::text)';
                // A shared seed file is never this row's own object: recorded as its seed source, so
                // a permanent delete leaves it for every later seeding.
                array_push($bindings, $move['id'], $move['from'], $move['to'], MediaKey::isSeedKey($move['from']) ? 'seed_source' : 'moved_from');
                $moved[$move['from']] = $move['to'];
            }
            if ($values !== []) {
                DB::update(
                    'UPDATE media AS m SET storage_key = v.to_key, '.
                    "meta = COALESCE(m.meta, '{}'::jsonb) || jsonb_build_object(v.field, v.from_key) ".
                    'FROM (VALUES '.implode(', ', $values).') AS v(id, from_key, to_key, field) '.
                    'WHERE m.site_id = ? AND m.id = v.id AND m.storage_key = v.from_key',
                    [...$bindings, $siteId],
                );
            }

            // 3. The locked content rows, rewritten with what moved.
            $rewriter = new MediaKeyRewriter($moved);
            $report = [];
            foreach ($places as [$table, $column]) {
                $line = self::emptyLine();
                foreach (array_chunk($moved === [] ? [] : $locked["{$table}.{$column}"], self::CHUNK) as $ids) {
                    $rows = DB::select(
                        "SELECT id, {$column}::text AS doc FROM {$table} WHERE site_id = ? AND id IN (".implode(', ', array_fill(0, count($ids), '?')).') ORDER BY id',
                        [$siteId, ...$ids],
                    );
                    $this->rewriteRows($siteId, $rewriter, $table, $column, $rows, true, $line);
                }
                // This rewriter knows only the chunk's keys; strings it cannot place are judged by the
                // reference pass, which knows every moved key.
                $line['unmatched'] = 0;
                $line['samples'] = [];
                $line['other_host'] = 0;
                $line['other_samples'] = [];
                $report["{$table}.{$column}"] = $line;
            }

            return ['moved' => $moved, 'references' => $report];
        });
    }

    /**
     * Rewrites (or with $write false only counts) the keys $rewriter knows in each listed JSON
     * column of the website. Only rows whose text mentions one of its folders are read, CHUNK rows
     * per transaction by id; when writing, those rows are locked (FOR NO KEY UPDATE) for their rewrite.
     *
     * @param  list<array{0: string, 1: string}>  $places  [table, jsonb column]; every table has id + site_id
     * @return array<string, array{rows: int, references: int, unmatched: int, samples: list<string>, other_host: int, other_samples: list<string>}>
     */
    public function rewriteReferences(int $siteId, MediaKeyRewriter $rewriter, array $places, bool $write): array
    {
        self::assertPlaces($places);
        $patterns = array_map(static fn (string $folder): string => '%'.self::likeEscape($folder).'/%', $rewriter->folders());

        $report = [];
        foreach ($places as [$table, $column]) {
            $line = self::emptyLine();
            if ($patterns !== []) {
                $this->rewritePlace($siteId, $rewriter, $table, $column, $patterns, $write, $line);
            }
            $report["{$table}.{$column}"] = $line;
        }

        return $report;
    }

    /**
     * @param  list<string>  $patterns
     * @param  array{rows: int, references: int, unmatched: int, samples: list<string>, other_host: int, other_samples: list<string>}  $line
     */
    private function rewritePlace(int $siteId, MediaKeyRewriter $rewriter, string $table, string $column, array $patterns, bool $write, array &$line): void
    {
        $mentions = "{$column}::text LIKE ANY (ARRAY[".implode(', ', array_fill(0, count($patterns), '?')).']::text[])';
        $lock = $write ? ' FOR NO KEY UPDATE' : '';
        $lastId = 0;
        do {
            $count = $this->tenancy->enter($siteId, function () use ($siteId, $rewriter, $table, $column, $mentions, $patterns, $lock, $write, &$line, &$lastId): int {
                $rows = DB::select(
                    "SELECT id, {$column}::text AS doc FROM {$table} WHERE site_id = ? AND id > ? AND {$mentions} ORDER BY id LIMIT ".self::CHUNK.$lock,
                    [$siteId, $lastId, ...$patterns],
                );
                $lastId = $this->rewriteRows($siteId, $rewriter, $table, $column, $rows, $write, $line) ?? $lastId;

                return count($rows);
            });
        } while ($count === self::CHUNK);
    }

    /**
     * Rewrites each row's document; returns the last id seen (null for no rows).
     *
     * @param  array<mixed>  $rows  objects with id + doc (the column as text)
     * @param  array{rows: int, references: int, unmatched: int, samples: list<string>, other_host: int, other_samples: list<string>}  $line
     */
    private function rewriteRows(int $siteId, MediaKeyRewriter $rewriter, string $table, string $column, array $rows, bool $write, array &$line): ?int
    {
        $lastId = null;
        foreach ($rows as $row) {
            if (! is_object($row) || ! isset($row->id, $row->doc)) {
                continue;
            }
            $lastId = Cast::int($row->id);
            $document = json_decode(Cast::string($row->doc), false, 512, JSON_THROW_ON_ERROR);
            $rewritten = $rewriter->rewrite($document);

            $line['unmatched'] += count($rewriter->unmatched);
            foreach ($rewriter->unmatched as $sample) {
                if (count($line['samples']) < 5 && ! in_array($sample, $line['samples'], true)) {
                    $line['samples'][] = $sample;
                }
            }
            $line['other_host'] += count($rewriter->otherHost);
            foreach ($rewriter->otherHost as $sample) {
                if (count($line['other_samples']) < 5 && ! in_array($sample, $line['other_samples'], true)) {
                    $line['other_samples'][] = $sample;
                }
            }
            if ($rewriter->rewritten === 0) {
                continue;
            }
            $line['rows']++;
            $line['references'] += $rewriter->rewritten;

            if ($write) {
                DB::update(
                    "UPDATE {$table} SET {$column} = ?::jsonb WHERE site_id = ? AND id = ?",
                    [json_encode($rewritten, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), $siteId, $lastId],
                );
            }
        }

        return $lastId;
    }

    /** Whether two stored objects hold the same bytes, by the disk's checksum (the ETag on R2/S3). */
    private function sameObject(FilesystemAdapter $disk, string $one, string $two): bool
    {
        $a = $disk->checksum($one);
        $b = $disk->checksum($two);

        return is_string($a) && $a !== '' && $a === $b;
    }

    /**
     * The new key of $from: its file name under the code, the hash suffix grown while the stem is
     * taken. The suffix grows along the row's content hash when that hash starts with it (a key
     * the upload made), else along the suffix followed by the row's hash; a name without a suffix
     * gets one.
     *
     * @param  array<string, true>  $assigned  stems given to other rows of this plan (or refused)
     */
    private function target(int $siteId, string $code, string $from, string $rowHash, array $assigned): string
    {
        $file = basename($from);
        if (preg_match('/\A(?<name>.+?)(?:-(?<hex>[0-9a-f]{4,64}))?\.(?<ext>[A-Za-z0-9]+)\z/D', $file, $parts) !== 1) {
            throw new UnplannableMediaKeyException('no file name with an extension; re-key it by hand');
        }
        $name = $parts['name'];
        $hex = $parts['hex'];
        $ext = $parts['ext'];

        $key = "{$code}/{$file}";
        if (! $this->stemTaken($siteId, self::stem($key), $ext, $assigned)) {
            MediaKey::assertCleanPath($key);

            return $key;
        }

        $hash = preg_match('/\A[0-9a-f]{64}\z/D', $rowHash) === 1 ? $rowHash : hash('sha256', $from);
        $source = $hex !== '' && str_starts_with($hash, $hex) ? $hash : $hex.$hash;
        for ($length = max(strlen($hex) + 1, MediaKey::MIN_HASH_LENGTH); $length <= strlen($source); $length++) {
            $key = "{$code}/{$name}-".substr($source, 0, $length).".{$ext}";
            if (! $this->stemTaken($siteId, self::stem($key), $ext, $assigned)) {
                MediaKey::assertCleanPath($key);

                return $key;
            }
        }

        throw new UnplannableMediaKeyException("no free key under '{$code}/'");
    }

    /** @param  array<string, true>  $assigned */
    private function stemTaken(int $siteId, string $stem, string $ext, array $assigned): bool
    {
        if (isset($assigned[$stem])) {
            return true;
        }

        $keys = array_map(static fn (string $extension): string => "{$stem}.{$extension}", array_values(array_unique([...MediaKey::EXTENSIONS, $ext])));

        return DB::table('media')->where('site_id', $siteId)->whereIn('storage_key', $keys)->exists();
    }

    /** @return array{rows: int, references: int, unmatched: int, samples: list<string>, other_host: int, other_samples: list<string>} */
    private static function emptyLine(): array
    {
        return ['rows' => 0, 'references' => 0, 'unmatched' => 0, 'samples' => [], 'other_host' => 0, 'other_samples' => []];
    }

    /** @param  list<array{0: string, 1: string}>  $places */
    private static function assertPlaces(array $places): void
    {
        foreach ($places as [$table, $column]) {
            if (preg_match(self::IDENTIFIER, $table) !== 1 || preg_match(self::IDENTIFIER, $column) !== 1) {
                throw new InvalidArgumentException("Not a table and column: {$table}.{$column}.");
            }
        }
    }

    /** $value as a literal inside a LIKE pattern (backslash is PostgreSQL's default escape). */
    private static function likeEscape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** A key without its extension. */
    private static function stem(string $key): string
    {
        $dot = strrpos($key, '.');

        return $dot === false ? $key : substr($key, 0, $dot);
    }
}
