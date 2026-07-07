<?php

namespace App\Modules\Cms\Observers;

use App\Modules\Cms\Models\Entry;
use App\Modules\Media\Models\Mediable;

/**
 * Cleans an entry's block image usage index when the entry is PERMANENTLY deleted. `mediables` is a
 * polymorphic soft-attachment (no FK back to entries), so a force-delete would otherwise strand the
 * entry's content rows — leaving the media they referenced read as "used" by a gone entry forever
 * (a false "used" in the library filter).
 *
 * A soft delete deliberately KEEPS the rows: a trashed entry still reserves its media, so the media
 * isn't reported unused and deleted out from under a restore; restoring + the next save re-reconciles.
 */
final class EntryObserver
{
    public function forceDeleted(Entry $entry): void
    {
        Mediable::query()
            ->where('mediable_type', 'entry')
            ->where('mediable_id', $entry->id)
            ->where('collection', 'content')
            ->delete();
    }
}
