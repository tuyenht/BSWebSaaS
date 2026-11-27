<?php

namespace App\Modules\Media\Observers;

use App\Modules\Identity\Support\SupportSession;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Media\Support\MediaKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Force-purge cleanup for a media master (design.md §16, D3). Soft-delete (Thùng rác) keeps the R2
 * object + attachments; a force-delete (emptying the trash) is the irreversible purge. We drop the
 * master's attachments in forceDeleting — while media_id still resolves them, BEFORE the FK SET NULL
 * cascade strands them. forceDeleted decides which stored objects go (inside the transaction, where
 * the website's other rows are still visible) and deletes them only once that transaction has
 * committed (afterCommit): a purge that fails later on, at its counter write or anywhere else, rolls
 * its rows back into the Trash with their files still there. A worker killed between the commit and
 * the delete leaves an object no row names (a leak, never a row without its file). RLS scopes the
 * attachment cleanup to the master's site.
 *
 * A row media:rekey-site moved still has its old object at `meta.moved_from` (kept so addresses
 * already shared keep working); a purge removes that one too, so a file deleted for good is gone
 * under both addresses — unless another row of the website still holds that key or was seeded from
 * it. `meta.seed_source` is never deleted: it is the shared seed file other websites copy from.
 * Nor is any object under a demo seed source (`media.seed_folders`), whichever field names it: a
 * row still holding a seed key, or one whose recorded old key is a seed file.
 *
 * Per-instance only: a MASS forceDelete (Media::query()->forceDelete()) bypasses model events, so
 * this never runs — the FK SET NULL keeps mediables valid, and the content-hash R2 orphans are
 * swept by the deferred D3 job. Purge masters one instance at a time (as the Trash UI does).
 *
 * Support staff signed in for a member never delete the website's files for good: forceDeleting
 * refuses it before anything goes, whoever asks (a queued job or the console has no session).
 */
final class MediaObserver
{
    public function forceDeleting(Media $media): void
    {
        app(SupportSession::class)->forbid();
        Mediable::query()->where('media_id', $media->id)->delete();
    }

    public function forceDeleted(Media $media): void
    {
        $keys = MediaKey::isSeedKey($media->storage_key) ? [] : [$media->storage_key];

        $meta = $media->getAttribute('meta');
        $movedFrom = is_array($meta) ? ($meta['moved_from'] ?? null) : null;
        if (is_string($movedFrom) && $movedFrom !== '' && $movedFrom !== $media->storage_key && ! MediaKey::isSeedKey($movedFrom)) {
            $stillUsed = Media::query()->withTrashed()
                ->where(fn (Builder $q) => $q->where('storage_key', $movedFrom)->orWhere('meta->seed_source', $movedFrom)->orWhere('meta->moved_from', $movedFrom))
                ->exists();
            if (! $stillUsed) {
                $keys[] = $movedFrom;
            }
        }
        if ($keys === []) {
            return;
        }

        // no query in here: after the commit the website's context is gone
        $disk = $media->disk;
        $media->getConnection()->afterCommit(static function () use ($disk, $keys): void {
            $store = Storage::disk($disk);
            foreach ($keys as $key) {
                $store->delete($key);
            }
        });
    }
}
