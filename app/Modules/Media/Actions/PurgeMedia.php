<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaStorage;
use App\Modules\Shared\Support\Cast;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deletes files in the Trash for good — the only place a media row is force-deleted (an arch test
 * keeps it so), so the library's stored total (`media.bytes`) and Trash total (`media.trash_bytes`)
 * always go down with the files, in the same transaction.
 *
 * The rows are locked first, in id order, and only those still in the Trash under the lock are
 * deleted: one another request deleted meanwhile is skipped, never subtracted twice, and one taken
 * back out of the Trash a moment before (after the caller listed it) is kept. Each goes one by one,
 * so MediaObserver still drops its attachments and its stored file (and refuses support staff
 * signed in for a member); their sizes come off both totals as the last write. The stored files are
 * deleted only after the caller's transaction commits (MediaObserver): when anything fails first —
 * the totals' row held past SiteCounters::LOCK_WAIT, say — the rows go back into the Trash and their
 * files are still there. The work runs in a savepoint of its own, so a failed statement really rolls
 * it back, with the deletes it queued, even when the caller goes on and commits: a web request turns
 * the error into an error page inside its tenant transaction, which then ends with a COMMIT that
 * PostgreSQL treats as a rollback, and Laravel would still run the queued deletes. Who may delete for
 * good is the caller's question (the media.purge right).
 */
final readonly class PurgeMedia
{
    public function __construct(private MediaStorage $storage) {}

    /**
     * @param  Collection<int, Media>  $rows
     * @return int how many files were deleted
     */
    public function handle(Collection $rows): int
    {
        return $this->purge($rows)['files'];
    }

    /**
     * @param  Collection<int, Media>  $rows
     * @return array{files: int, bytes: int} how many files were deleted, and their size
     */
    public function purge(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return ['files' => 0, 'bytes' => 0];
        }

        return DB::transaction(function () use ($rows): array {
            // a row restored meanwhile no longer matches once its lock is taken (the newest version is re-checked)
            $state = Media::onlyTrashed()->whereKey($rows->modelKeys())->orderBy('id')->lockForUpdate()
                ->toBase()->get(['id', 'size'])->keyBy('id');
            $gone = 0;
            $bytes = 0;
            foreach ($rows as $media) {
                $row = $state->get($media->id);
                if ($row === null) {
                    continue; // deleted or taken out of the Trash meanwhile
                }
                $media->forceDelete();
                $bytes += max(0, Cast::int($row->size));
                $gone++;
            }
            $this->storage->purged($bytes);

            return ['files' => $gone, 'bytes' => $bytes];
        });
    }
}
