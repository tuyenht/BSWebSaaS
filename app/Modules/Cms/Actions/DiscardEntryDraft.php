<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Exceptions\EntryDraftRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Support\EntryDraftAudit;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Throws away a live entry's pending change (design.md §8): the website keeps what it shows, and
 * the change's content is gone — the live version and its revisions are untouched. Guarded like an
 * edit (EntryPolicy::update), and only while the change is with its authors: once sent for review
 * it is the reviewer's to return first (change_pending). The version the actor was shown must
 * still be the change's, so nobody throws away edits they have not seen.
 */
final readonly class DiscardEntryDraft
{
    public function __construct(private EntryWorkflowResolver $workflows) {}

    /**
     * @param  string  $seenVersion  EntryDraft::version() of the change the actor was shown
     *
     * @throws AuthorizationException the actor may not edit this entry
     * @throws EntryDraftRejectedException change_stale · change_pending
     */
    public function handle(Entry $entry, User $actor, string $seenVersion): void
    {
        if (! Gate::forUser($actor)->allows('update', $entry)) {
            throw new AuthorizationException(__('entries.err_update_forbidden'));
        }

        DB::transaction(function () use ($entry, $actor, $seenVersion): void {
            Entry::withTrashed()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $entry->refresh();

            $change = EntryDraft::query()->where('entry_id', $entry->id)->first();
            if ($change === null || ! hash_equals($change->version(), $seenVersion)) {
                throw new EntryDraftRejectedException('change_stale');
            }

            if (! $this->workflows->changeIsOpen($this->workflows->forEntry($entry), $change)) {
                throw new EntryDraftRejectedException('change_pending');
            }

            $locales = array_map(strval(...), array_keys($change->content['locales']));
            sort($locales, SORT_STRING);

            $change->delete();

            EntryDraftAudit::record(EntryDraftAudit::DISCARDED, $entry, $actor, $locales, $change->workflow_state);
        });
    }
}
