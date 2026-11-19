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
use Throwable;

/**
 * Deletes a media folder that holds more files than one web request should touch (config
 * media.folder_delete_inline_max_files): its files (live and in the Trash) go back to no folder
 * in bounded batches, then the folder row goes. Deleting the row at once would make the foreign
 * key's SET NULL rewrite every file in one statement, inside the request and under the folder lock.
 *
 *   - Every batch is its own short tenant transaction (Tenancy::enter: SET LOCAL
 *     app.current_site_id, RLS + the global scope), keyset by id over the (folder_id, id) index.
 *   - The row is deleted only when at most one batch of files is left (a file moved in meanwhile
 *     below the cursor is caught by the next sweep), under the site's folder-list lock like every
 *     folder write, so the final SET NULL stays bounded too.
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

    public function __construct(public int $siteId, public int $folderId, public int $after = 0)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->siteId.':'.$this->folderId;
    }

    /** Out of tries: the folder stays, partly emptied, and a new delete of it starts again where this left off. */
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

        while (true) {
            while (($last = $this->unfileBatch($tenancy, $after, $batch)) !== null) {
                $after = $last;
                if (microtime(true) >= $stopAt) {
                    self::dispatch($this->siteId, $this->folderId, $after);

                    return false;
                }
            }
            if ($this->deleteRowIfSmall($tenancy, $batch)) {
                return true;
            }
            $after = 0; // files came in below the cursor meanwhile: one more sweep
        }
    }

    /** One batch of the folder's files after $after back to no folder; the last id done, or null when none is left. */
    private function unfileBatch(Tenancy $tenancy, int $after, int $batch): ?int
    {
        return $tenancy->enter($this->siteId, function () use ($after, $batch): ?int {
            $ids = Media::withTrashed()->where('folder_id', $this->folderId)->where('id', '>', $after)
                ->orderBy('id')->limit($batch)->pluck('id')->map(fn (mixed $id): int => Cast::int($id))->all();
            if ($ids === []) {
                return null;
            }
            // toBase: like the foreign key's SET NULL, the files' updated_at is left alone
            Media::withTrashed()->where('folder_id', $this->folderId)->whereIn('id', $ids)->toBase()->update(['folder_id' => null]);

            return $ids[count($ids) - 1];
        });
    }

    /** Deletes the folder row once at most one batch of files is left in it (or it is gone already). */
    private function deleteRowIfSmall(Tenancy $tenancy, int $batch): bool
    {
        return $tenancy->enter($this->siteId, function () use ($batch): bool {
            MediaFolder::lockTree($this->siteId);
            $row = MediaFolder::query()->whereKey($this->folderId)->lockForUpdate()->first();
            if ($row === null) {
                return true;
            }
            if (Media::withTrashed()->where('folder_id', $this->folderId)->offset($batch)->exists()) {
                return false;
            }
            $row->delete();

            return true;
        });
    }
}
