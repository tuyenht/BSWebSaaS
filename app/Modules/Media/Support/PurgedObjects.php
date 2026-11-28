<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\Tenancy;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The stored objects that files deleted for good free, deleted once the delete's transaction has
 * committed, after one last look at the website's rows (MediaObserver decides which objects a file
 * frees; this class deletes them).
 *
 * Between the commit and the delete a key is free again: an upload of the same bytes under the same
 * name gets the same key and stores its object there. So the last look runs in the website's context
 * and keeps every key a row names by then, trashed or not: as its stored key, or, for an old address a
 * moved file kept (`meta.moved_from`), as another row's old address or seed source. When that look
 * fails nothing is deleted (a leak, never a row without its file).
 *
 * A purge collects its files' objects inside batch() and queues one delete for all of them, so the
 * last look is one tenant transaction per batch instead of one per file. The delete is queued in the
 * caller's (savepoint) transaction, so a purge that rolls back drops it with its rows, and so does a
 * site transaction PostgreSQL aborted (Tenancy::enter drops its after-commit work). A file deleted
 * outside a batch queues its own delete, the same way.
 *
 * Scoped (one per request or job): it holds the open batch only while batch() runs.
 */
final class PurgedObjects
{
    /** @var array<int, array{connection: Connection, objects: array<string, array<string, true>>, moved: array<string, true>}>|null site id => its freed objects (disk => keys) and the old addresses among them; null while no batch is open */
    private ?array $batch = null;

    /**
     * Runs $work, collecting the objects the files it deletes for good free, then queues one delete
     * for all of them on the transaction $work ran in. Call it inside that transaction. A batch opened
     * inside another one adds to the outer one, so the outer work must succeed or fail as a whole (the
     * last look still keeps the objects of rows an inner rollback brought back).
     *
     * @template TReturn
     *
     * @param  Closure():TReturn  $work
     * @return TReturn
     */
    public function batch(Closure $work): mixed
    {
        if ($this->batch !== null) {
            return $work();
        }

        $this->batch = [];
        try {
            $result = $work();
            $batch = $this->batch ?? [];
        } finally {
            $this->batch = null;
        }

        foreach ($batch as $siteId => $freed) {
            self::queue($freed['connection'], $siteId, $freed['objects'], array_map(strval(...), array_keys($freed['moved'])));
        }

        return $result;
    }

    /**
     * The objects one deleted file frees: its stored key and, when it was moved, its old address. A
     * row made in this request without a disk has none in memory (the column's default filled it in
     * the database): its objects are on the default disk.
     *
     * @param  list<string>  $keys
     */
    public function add(Connection $connection, int $siteId, ?string $disk, array $keys, ?string $movedFrom): void
    {
        if ($keys === []) {
            return;
        }
        $disk ??= Storage::getDefaultDriver();
        if ($this->batch === null) {
            self::queue($connection, $siteId, [$disk => array_fill_keys($keys, true)], $movedFrom === null ? [] : [$movedFrom]);

            return;
        }

        $this->batch[$siteId] ??= ['connection' => $connection, 'objects' => [], 'moved' => []];
        foreach ($keys as $key) {
            $this->batch[$siteId]['objects'][$disk][$key] = true;
        }
        if ($movedFrom !== null) {
            $this->batch[$siteId]['moved'][$movedFrom] = true;
        }
    }

    /**
     * @param  array<string, array<string, true>>  $objects  disk => keys
     * @param  list<string>  $moved  the old addresses among the keys
     */
    private static function queue(Connection $connection, int $siteId, array $objects, array $moved): void
    {
        $connection->afterCommit(static function () use ($siteId, $objects, $moved): void {
            $keys = [];
            foreach ($objects as $onDisk) {
                foreach (array_keys($onDisk) as $key) {
                    $keys[(string) $key] = true;
                }
            }
            try {
                // after the commit the website's context is gone: the last look enters it again
                $named = app(Tenancy::class)->enter($siteId, fn (): array => self::named(array_map(strval(...), array_keys($keys)), $moved));
            } catch (Throwable $e) {
                report($e);

                return;
            }
            foreach ($objects as $disk => $onDisk) {
                foreach (array_keys($onDisk) as $key) {
                    if (! isset($named[(string) $key])) {
                        Storage::disk((string) $disk)->delete((string) $key);
                    }
                }
            }
        });
    }

    /**
     * The keys among $keys some row of the website names now: as its stored key (the unique
     * (site_id, storage_key) index), or, for an old address, as its own old address or seed source.
     * The second look reads the rows' meta, so it runs only when the batch freed an old address.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $moved
     * @return array<string, true>
     */
    private static function named(array $keys, array $moved): array
    {
        $named = [];
        foreach (array_chunk($keys, 1000) as $chunk) {
            foreach (Media::query()->withTrashed()->whereIn('storage_key', $chunk)->toBase()->pluck('storage_key') as $key) {
                if (is_string($key)) {
                    $named[$key] = true;
                }
            }
        }
        foreach (array_chunk($moved, 1000) as $chunk) {
            $rows = Media::query()->withTrashed()
                ->where(fn (Builder $q) => $q->whereIn('meta->moved_from', $chunk)->orWhereIn('meta->seed_source', $chunk))
                ->toBase()->selectRaw("meta->>'moved_from' as moved_from, meta->>'seed_source' as seed_source")->get();
            foreach ($rows as $row) {
                foreach ([$row->moved_from, $row->seed_source] as $key) {
                    if (is_string($key) && in_array($key, $chunk, true)) {
                        $named[$key] = true;
                    }
                }
            }
        }

        return $named;
    }
}
