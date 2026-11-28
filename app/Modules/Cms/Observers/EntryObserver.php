<?php

namespace App\Modules\Cms\Observers;

use App\Modules\Cms\Events\EntryPublished;
use App\Modules\Cms\Exceptions\HomePageLockedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\BlockMediaUsage;
use App\Modules\Cms\Support\EntryCoverUsage;
use App\Modules\Cms\Support\HomePages;
use App\Modules\Media\Models\Mediable;

/**
 * Keeps the library's "in use" row of an entry's featured picture (EntryCoverUsage) in step with
 * its meta: written when an entry is created with a picture, its picture changes or it is restored
 * from the Trash, in that save's transaction, whichever path saved it.
 *
 * Cleans an entry's block image and featured picture usage rows when the entry is PERMANENTLY
 * deleted. `mediables` is a polymorphic soft-attachment (no FK back to entries), so a force-delete
 * would otherwise strand the entry's rows — leaving the media they referenced read as "used" by a gone entry forever
 * (a false "used" in the library filter).
 *
 * A soft delete deliberately KEEPS the rows: a trashed entry still reserves its media, so the media
 * isn't reported unused and deleted out from under a restore; restoring + the next save re-reconciles.
 *
 * It also announces an entry going public (EntryPublished), whichever path published it, so a
 * listener such as "Tự thêm trang mới" never depends on one action remembering to tell it.
 *
 * And it keeps the website's Home page out of the Trash, whichever path tries to move it there
 * (HomePages): the last guard behind the screens' own checks.
 */
final class EntryObserver
{
    public function created(Entry $entry): void
    {
        if (EntryCoverUsage::keyOf($entry->meta) !== null || $entry->cover_media_id !== null) {
            EntryCoverUsage::sync($entry);
        }
        if ($entry->status === 'published') {
            event(new EntryPublished($entry->id, $entry->type));
        }
    }

    public function updated(Entry $entry): void
    {
        // getOriginal() still holds the stored meta while `updated` runs
        if ($entry->wasChanged('cover_media_id')
            || ($entry->wasChanged('meta') && EntryCoverUsage::keyOf($entry->getOriginal('meta')) !== EntryCoverUsage::keyOf($entry->meta))) {
            EntryCoverUsage::sync($entry);
        }
        if ($entry->wasChanged('status') && $entry->status === 'published') {
            event(new EntryPublished($entry->id, $entry->type));
        }
    }

    /** A move to the Trash (a soft delete) of the Home page is refused; a purge from the Trash is not. */
    /** Back from the Trash: its cover row is made to match its meta (a no-op when it already does). */
    public function restored(Entry $entry): void
    {
        EntryCoverUsage::sync($entry);
    }

    public function deleting(Entry $entry): void
    {
        if (! $entry->isForceDeleting() && HomePages::is($entry)) {
            throw new HomePageLockedException;
        }
    }

    public function forceDeleted(Entry $entry): void
    {
        Mediable::query()
            ->where('mediable_type', 'entry')
            ->where('mediable_id', $entry->id)
            ->whereIn('collection', [BlockMediaUsage::COLLECTION, EntryCoverUsage::COLLECTION])
            ->delete();
    }
}
