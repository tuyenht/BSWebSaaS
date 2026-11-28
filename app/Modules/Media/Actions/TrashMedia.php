<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaStorage;
use App\Modules\Shared\Support\Cast;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sends files to the Trash and takes them back out, keeping the library's Trash total
 * (`media.trash_bytes`, MediaStorage) in step in the same transaction. The stored total
 * (`media.bytes`) does not change: a file in the Trash still takes its room.
 *
 * The caller has already chosen the rows the person may act on (MediaDeleteRights). Their rows are
 * locked first, in id order, and only the ones still in the state the step starts from are acted
 * on: a file someone else trashed (or took back) meanwhile is neither moved nor counted twice. Each
 * goes one by one (the model's own soft delete records who trashed it; restore clears it), then
 * their sizes are counted as the last write.
 */
final readonly class TrashMedia
{
    public function __construct(private MediaStorage $storage) {}

    /**
     * @param  Collection<int, Media>  $rows
     * @return Collection<int, Media> the files that went to the Trash
     */
    public function trash(Collection $rows): Collection
    {
        [$go, $bytes] = $this->still($rows, trashed: false);
        $go->each(fn (Media $media) => $media->delete());
        $this->storage->trashed($bytes);

        return $go;
    }

    /**
     * @param  Collection<int, Media>  $rows
     * @return Collection<int, Media> the files that came back out of the Trash
     */
    public function restore(Collection $rows): Collection
    {
        [$back, $bytes] = $this->still($rows, trashed: true);
        $back->each(fn (Media $media) => $media->restore());
        $this->storage->restored($bytes);

        return $back;
    }

    /**
     * The rows still (not) in the Trash, under a row lock, and their sizes added up.
     *
     * @param  Collection<int, Media>  $rows
     * @return array{0: Collection<int, Media>, 1: int}
     */
    private function still(Collection $rows, bool $trashed): array
    {
        if ($rows->isEmpty()) {
            return [$rows, 0];
        }

        $state = Media::withTrashed()->whereKey($rows->modelKeys())->orderBy('id')->lockForUpdate()
            ->toBase()->get(['id', 'size', 'deleted_at'])->keyBy('id');
        $bytes = 0;
        $kept = $rows->filter(function (Media $media) use ($state, $trashed, &$bytes): bool {
            $row = $state->get($media->id);
            if ($row === null || ($row->deleted_at !== null) !== $trashed) {
                return false;
            }
            $bytes += max(0, Cast::int($row->size));

            return true;
        })->values();

        return [$kept, $bytes];
    }
}
