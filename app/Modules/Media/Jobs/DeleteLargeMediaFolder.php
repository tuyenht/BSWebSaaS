<?php

declare(strict_types=1);

namespace App\Modules\Media\Jobs;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Deletes a media folder that, with its sub-folders, holds more files than one web request should
 * touch (config media.folder_delete_inline_max_files): their files (live and in the Trash) go back
 * to no folder in bounded batches, then the folder row goes (its sub-folders with it, CASCADE).
 * Deleting the row at once would make the foreign key's SET NULL rewrite every file in one
 * statement, inside the request and under the folder lock.
 *
 *   - Every batch is its own short tenant transaction (Tenancy::enter: SET LOCAL
 *     app.current_site_id, RLS + the global scope), keyset by id over the (folder_id, id) index,
 *     one folder of the family at a time (the sub-folders, then the folder itself).
 *   - Each batch takes the site's folder-list lock and first checks that its folder is still the
 *     folder or one of its sub-folders: a sub-folder moved out meanwhile keeps its files.
 *   - While it runs the folder is marked (pending()): the library puts nothing new under it — no
 *     new sub-folder, no move into it, no Hoàn tác back into it. Files moved into the folder itself
 *     go back to no folder with the others, as the delete asked.
 *   - The row is deleted only when at most one batch of files is left in the family (a file moved
 *     in meanwhile below the cursor is caught by the next sweep), under the folder-list lock, so
 *     the final SET NULL stays bounded too.
 *   - A run stops after a time budget and queues the next one, well inside the worker's timeout.
 *   - Idempotent: a retry, a second run or a folder already gone only finds less (or nothing) to do.
 */
final class DeleteLargeMediaFolder implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /** Seconds one run works before it hands over to a fresh run. */
    public const int RUN_SECONDS = 30;

    /** $after: the last file id done in $folderId (the folder itself) or in the sub-folder being swept. */
    public function __construct(public int $siteId, public int $folderId, public int $after = 0, public ?int $sweeping = null)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->siteId.':'.$this->folderId;
    }

    /** Marks the folder as being deleted (for a day at most: a lost job leaves the folder usable again). */
    public static function markPending(int $siteId, int $folderId): void
    {
        Cache::put(self::pendingKey($siteId, $folderId), true, now()->addDay());
    }

    /** Whether the folder is being deleted by this job. */
    public static function pending(int $siteId, int $folderId): bool
    {
        return Cache::has(self::pendingKey($siteId, $folderId));
    }

    private static function pendingKey(int $siteId, int $folderId): string
    {
        return 'media:folder-deleting:'.$siteId.':'.$folderId;
    }

    /**
     * Out of tries: the folder stays, partly emptied, and a new delete of it starts again where this
     * left off. Its mark stays to its expiry: another run of the same folder may still be working.
     */
    public function failed(?Throwable $e): void
    {
        if ($e !== null) {
            report($e);
        }
    }

    /** True when the run finished the folder; false when it handed over to a next run. */
    public function handle(Tenancy $tenancy): bool
    {
        $batch = max(1, Cast::int(config('media.folder_delete_batch_files', 2000)));
        $stopAt = microtime(true) + self::RUN_SECONDS;
        $after = $this->after;
        $resume = $this->sweeping;
        self::markPending($this->siteId, $this->folderId);

        while (true) {
            $family = $this->family($tenancy);
            if ($resume !== null && ! in_array($resume, $family, true)) {
                [$resume, $after] = [null, 0]; // the folder the last run was in is gone: its cursor means nothing here
            }
            foreach ($family as $folderId) {
                if ($resume !== null && $folderId !== $resume) {
                    continue; // a handed-over run starts at the folder the last one was in
                }
                $resume = null;
                while (($last = $this->unfileBatch($tenancy, $folderId, $after, $batch)) !== null) {
                    $after = $last;
                    if (microtime(true) >= $stopAt) {
                        self::dispatch($this->siteId, $this->folderId, $after, $folderId);

                        return false;
                    }
                }
                $after = 0;
            }
            $resume = null;
            if ($this->deleteRowIfSmall($tenancy, $batch)) {
                Cache::forget(self::pendingKey($this->siteId, $this->folderId));

                return true;
            }
            // files came in below the cursor meanwhile: one more sweep
        }
    }

    /** @return list<int> the sub-folders, then the folder itself (empty when it is gone) */
    private function family(Tenancy $tenancy): array
    {
        return $tenancy->enter($this->siteId, function (): array {
            if (! MediaFolder::query()->whereKey($this->folderId)->exists()) {
                return [];
            }
            $kids = MediaFolder::query()->where('parent_id', $this->folderId)->orderBy('id')->pluck('id')
                ->map(fn (mixed $id): int => Cast::int($id))->all();

            return [...array_values($kids), $this->folderId];
        });
    }

    /** One batch of $folderId's files after $after back to no folder; the last id done, or null when none is left. */
    private function unfileBatch(Tenancy $tenancy, int $folderId, int $after, int $batch): ?int
    {
        return $tenancy->enter($this->siteId, function () use ($folderId, $after, $batch): ?int {
            MediaFolder::lockTree($this->siteId);
            $inFamily = MediaFolder::query()->whereKey($folderId)
                ->where(fn ($q) => $q->whereKey($this->folderId)->orWhere('parent_id', $this->folderId))->exists();
            if (! $inFamily) {
                return null; // moved out or gone since the family was read: its files stay
            }
            $ids = Media::withTrashed()->where('folder_id', $folderId)->where('id', '>', $after)
                ->orderBy('id')->limit($batch)->pluck('id')->map(fn (mixed $id): int => Cast::int($id))->all();
            if ($ids === []) {
                return null;
            }
            // toBase: like the foreign key's SET NULL, the files' updated_at is left alone
            Media::withTrashed()->where('folder_id', $folderId)->whereIn('id', $ids)->toBase()->update(['folder_id' => null]);

            return $ids[count($ids) - 1];
        });
    }

    /** Deletes the folder row (its sub-folders with it) once at most one batch of files is left in them, or it is gone already. */
    private function deleteRowIfSmall(Tenancy $tenancy, int $batch): bool
    {
        return $tenancy->enter($this->siteId, function () use ($batch): bool {
            MediaFolder::lockTree($this->siteId);
            $row = MediaFolder::query()->whereKey($this->folderId)->lockForUpdate()->first();
            if ($row === null) {
                return true;
            }
            $family = MediaFolder::query()->where('parent_id', $row->id)->pluck('id')->push($row->id)->all();
            if (Media::withTrashed()->whereIn('folder_id', $family)->offset($batch)->exists()) {
                return false;
            }
            $row->delete();

            return true;
        });
    }
}
