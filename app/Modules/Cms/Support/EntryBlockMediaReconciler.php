<?php

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;

/**
 * Materializes an entry's block image usage into `mediables` (collection='content') so "where is this
 * media used" is an indexed lookup, not a scan of every entry body — the anti-scan contract at tenant
 * scale (a media_id buried in blocks[].props can't be found by an indexed query otherwise).
 *
 * Reconciled on save, across ALL locale bodies, through the shared BlockMediaUsage (which media a
 * block names, which of them are this site's, prune and add). It runs inside the request transaction
 * (the save), so the usage index never drifts from the content. Lives in Cms, which already depends
 * on Media and Theme, so every Cms write path that changes a body (the editor save, a revision
 * restore) reconciles through this one class.
 */
final readonly class EntryBlockMediaReconciler
{
    public function __construct(private BlockMediaUsage $usage) {}

    public function reconcile(Entry $entry): void
    {
        $lists = [];
        foreach ($entry->translations()->get() as $translation) {
            $lists[] = is_array($translation->body['blocks'] ?? null) ? $translation->body['blocks'] : [];
        }

        $this->usage->sync('entry', $entry->id, $lists);
    }
}
