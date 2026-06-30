<?php

namespace App\Modules\Media\Observers;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use Illuminate\Support\Facades\Storage;

/**
 * Force-purge cleanup for a media master (design.md §16, D3). Soft-delete (Thùng rác) keeps the R2
 * object + attachments; a force-delete (emptying the trash) is the irreversible purge. We drop the
 * master's attachments in forceDeleting — while media_id still resolves them, BEFORE the FK SET NULL
 * cascade strands them — and delete the single R2 object in forceDeleted, after the row is gone, so
 * a failed purge never strands a live image. RLS scopes the attachment cleanup to the master's site.
 *
 * Per-instance only: a MASS forceDelete (Media::query()->forceDelete()) bypasses model events, so
 * this never runs — the FK SET NULL keeps mediables valid, and the content-hash R2 orphans are
 * swept by the deferred D3 job. Purge masters one instance at a time (as the Trash UI does).
 */
final class MediaObserver
{
    public function forceDeleting(Media $media): void
    {
        Mediable::query()->where('media_id', $media->id)->delete();
    }

    public function forceDeleted(Media $media): void
    {
        Storage::disk($media->disk)->delete($media->storage_key);
    }
}
