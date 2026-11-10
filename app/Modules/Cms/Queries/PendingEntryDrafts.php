<?php

declare(strict_types=1);

namespace App\Modules\Cms\Queries;

use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Shared\Support\Cast;

/**
 * Which entries have a pending change waiting (an entry_drafts row), within the current site. It
 * reads only the entry ids — never the draft content, which is not approved yet.
 */
final readonly class PendingEntryDrafts
{
    /**
     * @param  list<int>  $entryIds
     * @return list<int>
     */
    public function among(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        return array_values(array_map(Cast::int(...), EntryDraft::query()->whereIn('entry_id', $entryIds)->pluck('entry_id')->all()));
    }
}
