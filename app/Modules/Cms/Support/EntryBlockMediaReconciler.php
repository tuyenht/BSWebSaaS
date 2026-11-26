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
 *
 * The same read also rewrites where the entry places shared blocks (BlockUsageIndex, per language),
 * so every body write keeps that index too at no extra read: it also says whether the entry has
 * rows there, so a body that places none and had none costs no write.
 */
final readonly class EntryBlockMediaReconciler
{
    public function __construct(private BlockMediaUsage $usage, private BlockUsageIndex $blocks) {}

    public function reconcile(Entry $entry): void
    {
        $lists = [];
        // no translation read: rows may be left, so they are cleared
        $hasRows = null;
        $translations = $entry->translations()->select('entry_translations.*')
            ->selectRaw('exists(select 1 from block_usages b where b.entry_id = entry_translations.entry_id) as has_block_usage')
            ->get();
        foreach ($translations as $translation) {
            $lists[$translation->locale] = is_array($translation->body['blocks'] ?? null) ? $translation->body['blocks'] : [];
            // only a plain "no" counts as none: anything else clears the rows (never leaves a stale one)
            $hasRows = $hasRows === true || ! in_array($translation->getAttribute('has_block_usage'), [false, 0, 'f', '0'], true);
        }

        $this->usage->sync('entry', $entry->id, array_values($lists));
        $this->blocks->syncEntry($entry, $lists, $hasRows ?? true);
    }
}
