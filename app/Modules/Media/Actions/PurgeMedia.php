<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaStorage;
use App\Modules\Shared\Support\Cast;
use Illuminate\Database\Eloquent\Collection;

/**
 * Deletes files in the Trash for good — the only place a media row is force-deleted (an arch test
 * keeps it so), so the library's stored total (`media.bytes`) and Trash total (`media.trash_bytes`)
 * always go down with the files, in the same transaction.
 *
 * The rows are locked first, in id order, and only those still in the Trash under the lock are
 * deleted: one another request deleted meanwhile is skipped, never subtracted twice, and one taken
 * back out of the Trash a moment before (after the caller listed it) is kept. Each goes one by one,
 * so MediaObserver still drops its attachments and its stored file (and refuses support staff
 * signed in for a member); their sizes come off both totals as the last write. Who may delete for
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
        if ($rows->isEmpty()) {
            return 0;
        }

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

        return $gone;
    }
}
