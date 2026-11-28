<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaStorage;
use App\Modules\Media\Support\PurgedObjects;
use App\Modules\Shared\Support\Cast;
use Illuminate\Database\Eloquent\Builder;
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
 * deleted only after the caller's transaction commits (MediaObserver, then PurgedObjects: one last
 * look and one delete for the whole batch): when anything fails first — the totals' row held past
 * SiteCounters::LOCK_WAIT, say — the rows go back into the Trash and their files are still there. The work runs in a savepoint of its own, so a failed statement really rolls
 * it back, with the deletes it queued, even when the caller goes on and commits: a web request turns
 * the error into an error page inside its tenant transaction, which then ends with a COMMIT that
 * PostgreSQL treats as a rollback, and Laravel would still run the queued deletes. Who may delete for
 * good is the caller's question (the media.purge right).
 */
final readonly class PurgeMedia
{
    /** Files loaded at a time by purgeAll(). */
    public const int CHUNK = 200;

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

        // one savepoint, and one after-commit delete of the stored files for the whole page (PurgedObjects)
        return DB::transaction(fn (): array => app(PurgedObjects::class)->batch(function () use ($rows): array {
            $done = $this->deleteTrashed($rows);
            $this->storage->purged($done['bytes']);

            return $done;
        }));
    }

    /**
     * Deletes for good every file in the Trash that $trashed matches, CHUNK at a time by id, so a large
     * Trash is never loaded whole. All pages share one savepoint and one after-commit delete: when one
     * page fails, none is deleted and the caller sees the error, as with a single purge(). Their sizes
     * come off the totals once, after the last page, so the totals' row is not held while the later
     * pages delete.
     *
     * @param  Builder<Media>  $trashed  files in the Trash (Media::onlyTrashed()…)
     * @return array{files: int, bytes: int} how many files were deleted, and their size
     */
    public function purgeAll(Builder $trashed, int $chunk = self::CHUNK): array
    {
        $chunk = max(1, $chunk);

        // one savepoint, and one after-commit delete of the stored files for all the pages
        return DB::transaction(fn (): array => app(PurgedObjects::class)->batch(function () use ($trashed, $chunk): array {
            $done = ['files' => 0, 'bytes' => 0];
            $after = 0;
            do {
                $page = (clone $trashed)->where($trashed->qualifyColumn('id'), '>', $after)->orderBy($trashed->qualifyColumn('id'))->limit($chunk)->get();
                $part = $this->deleteTrashed($page);
                $done = ['files' => $done['files'] + $part['files'], 'bytes' => $done['bytes'] + $part['bytes']];
                $after = Cast::int($page->last()?->id, $after);
            } while ($page->count() === $chunk);
            $this->storage->purged($done['bytes']);

            return $done;
        }));
    }

    /**
     * Locks $rows in id order and deletes for good those still in the Trash under the lock. The caller
     * takes their size off the totals afterwards, as its last write.
     *
     * @param  Collection<int, Media>  $rows
     * @return array{files: int, bytes: int}
     */
    private function deleteTrashed(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return ['files' => 0, 'bytes' => 0];
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

        return ['files' => $gone, 'bytes' => $bytes];
    }
}
