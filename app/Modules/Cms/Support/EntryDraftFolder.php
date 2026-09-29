<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Identity\Models\User;
use LogicException;

/**
 * When a live entry with a pending change leaves the website (retracted, archived, set to draft or
 * private), the change is folded into the entry (design.md §8, owner decision): nobody's work is
 * lost, and nothing unapproved reaches visitors because the entry is offline now. The version that
 * was live is kept in its revisions. The change is then deleted and an audit line says so.
 *
 * Called by the roads that take an entry offline, inside their transaction and after they set the
 * new status — writing while the entry is still published would move live routes. Content that no
 * longer passes the editor's checks (a language whose layout uses a retired block, a category that
 * would leave the approval workflow) is skipped and named in the audit line rather than blocking
 * the entry from going offline. No revision is recorded: nothing new reached visitors.
 */
final readonly class EntryDraftFolder
{
    public function __construct(private EntryDraftApplier $applier) {}

    /**
     * Fold the entry's pending change into it; false when it has none.
     *
     * @throws LogicException called while the entry is still live
     */
    public function fold(Entry $entry, ?User $actor): bool
    {
        // Normally already held by the caller; taking it again in the same transaction is free and
        // keeps the lock-then-read order even for a caller that forgot.
        Entry::withTrashed()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

        if ($entry->status === 'published') {
            throw new LogicException("Entry {$entry->id} is still live: set its new status before folding its pending change.");
        }

        $change = EntryDraft::query()->where('entry_id', $entry->id)->first();
        if ($change === null) {
            return false;
        }

        $applied = $this->applier->apply($entry, $change, lenient: true);
        $change->delete();

        EntryDraftAudit::record(EntryDraftAudit::FOLDED, $entry, $actor, $applied['locales'], $change->workflow_state, [
            'skipped_locales' => $applied['skipped'],
            'status' => $entry->status,
            'dropped_media' => $applied['dropped_media'],
        ]);

        return true;
    }
}
