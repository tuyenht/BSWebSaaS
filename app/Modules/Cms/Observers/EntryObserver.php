<?php

namespace App\Modules\Cms\Observers;

use App\Modules\Cms\Events\EntryPublished;
use App\Modules\Cms\Exceptions\HomePageLockedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\HomePages;
use App\Modules\Media\Models\Mediable;

/**
 * Cleans an entry's block image usage index when the entry is PERMANENTLY deleted. `mediables` is a
 * polymorphic soft-attachment (no FK back to entries), so a force-delete would otherwise strand the
 * entry's content rows — leaving the media they referenced read as "used" by a gone entry forever
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
        if ($entry->status === 'published') {
            event(new EntryPublished($entry->id, $entry->type));
        }
    }

    public function updated(Entry $entry): void
    {
        if ($entry->wasChanged('status') && $entry->status === 'published') {
            event(new EntryPublished($entry->id, $entry->type));
        }
    }

    /** A move to the Trash (a soft delete) of the Home page is refused; a purge from the Trash is not. */
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
            ->where('collection', 'content')
            ->delete();
    }
}
