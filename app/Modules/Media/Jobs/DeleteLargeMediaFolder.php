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

/**
 * Deletes a media folder that, with its sub-folders, holds more files than one web request should
 * touch (config media.folder_delete_inline_max_files): their files (live and in the Trash) go back
 * to no folder in bounded batches, then the folder row goes (its sub-folders with it, CASCADE). Deleting the row at once would make the foreign
 * key's SET NULL rewrite every file in one statement, inside the request and under the folder lock.
 *
 *   - Every batch is its own short tenant transaction (Tenancy::enter: SET LOCAL
 *     app.current_site_id, RLS + the global scope), keyset by id over the (folder_id, id) index,
 *     one folder of the family at a time (the sub-folders, then the folder itself).
 *   - The row is deleted only when at most one batch of files is left in the family (a file moved in meanwhile
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

    /** $after: the last file id done in $folderId (the folder itself) or in the sub-folder being swept. */
    public function __construct(public int $siteId, public int $folderId, public int $after = 0, public ?int $sweeping = null)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->siteId.':'.$this->folderId;
    }

    /** True when the run finished the folder; false when it handed over to a next run. */
    public function handle(Tenancy $tenancy): bool
    {
        $batch = max(1, Cast::int(config('media.folder_delete_batch_files', 2000)));
        $stopAt = microtime(true) + self::RUN_SECONDS;
        $after = $this->after;
        $resume = $this->sweeping;

        while (true) {
            foreach ($this->family($tenancy) as $folderId) {
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
            $ids = Media::withTrashed()->where('folder_id', $folderId)->where('id', '>', $after)
                ->orderBy('id')->limit($batch)->pluck('id')->map(fn (mixed $id): int => Cast::int($id))->all();
            if ($ids === []) {
                return null;
            }
            Media::withTrashed()->where('folder_id', $folderId)->whereIn('id', $ids)->update(['folder_id' => null]);

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
