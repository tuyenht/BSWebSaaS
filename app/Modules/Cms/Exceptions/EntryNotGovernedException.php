<?php

namespace App\Modules\Cms\Exceptions;

use RuntimeException;

/**
 * A workflow transition was requested for content no active workflow governs — the section's
 * flow was switched off (or the post re-filed) while an editor had the approval block open.
 *
 * It exists so callers can catch THIS instead of a bare RuntimeException: QueryException is a
 * RuntimeException too, so a broad catch would report a deadlock or an RLS denial to the editor
 * as "this type no longer runs an approval workflow" — and send them to publish directly.
 */
final class EntryNotGovernedException extends RuntimeException
{
    public static function for(int $entryId): self
    {
        return new self("Entry {$entryId} is not governed by an active workflow.");
    }
}
