<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\EntryDraftRejectedException;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Support\EntryDraftApplier;
use App\Modules\Cms\Support\EntryDraftAudit;
use App\Modules\Cms\Support\EntryNotifier;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Update the website" (design.md §8, workflow tier-1): a publisher puts a live entry's pending
 * change online where no approval workflow governs the entry. That is the change of someone
 * without the publish right, or one left over when a workflow was switched off mid-review. Only
 * the publish right may do it (EntryPolicy::publish): on a site without a workflow, deciding what
 * visitors see is exactly what that right means.
 *
 * Applied like an approval, in one transaction with the entry row locked first: written through
 * the editor's checks (a new address moves the route and leaves a 301), an `edit` revision in the
 * publisher's name, the change deleted, an audit line. Where a workflow governs the entry the
 * change must be approved instead (workflow_governed). The version the publisher was shown must
 * still be the change's: without a workflow the change stays editable while a publisher reads it.
 */
final readonly class ApplyEntryDraft
{
    public function __construct(
        private EntryWorkflowResolver $workflows,
        private EntryDraftApplier $applier,
        private RecordEntryRevision $revisions,
        private EntryNotifier $notifier,
    ) {}

    /**
     * @param  string  $seenVersion  EntryDraft::version() of the change the publisher was shown
     * @return list<string> the languages written, in order
     *
     * @throws AuthorizationException the actor may not publish, or the change files the post outside their category limits
     * @throws EntryDraftRejectedException workflow_governed · not_live · change_stale · content_invalid · category_governed · slug_taken
     */
    public function handle(Entry $entry, User $actor, string $seenVersion): array
    {
        if (! Gate::forUser($actor)->allows('publish', $entry)) {
            throw new AuthorizationException(__('entries.err_publish_forbidden'));
        }

        try {
            /** @var array{0: EntryDraft, 1: list<string>} $done */
            $done = DB::transaction(function () use ($entry, $actor, $seenVersion): array {
                Entry::withTrashed()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
                $entry->refresh();

                // Read under the lock: a workflow switched on a moment ago must not be skipped.
                if ($this->workflows->forEntry($entry) !== null) {
                    throw new EntryDraftRejectedException('workflow_governed');
                }

                if ($entry->status !== 'published' || $entry->trashed()) {
                    throw new EntryDraftRejectedException('not_live');
                }

                $change = EntryDraft::query()->where('entry_id', $entry->id)->first();
                if ($change === null || ! hash_equals($change->version(), $seenVersion)) {
                    throw new EntryDraftRejectedException('change_stale');
                }

                $applied = $this->applier->apply($entry, $change, actor: $actor);

                if ($applied['all_locales']) {
                    $this->revisions->forAllLocales($entry, RevisionKind::Edit, $actor);
                } else {
                    foreach ($applied['translations'] as $translation) {
                        $this->revisions->forLocale($entry, $translation, RevisionKind::Edit, $actor);
                    }
                }

                $change->delete();

                EntryDraftAudit::record(EntryDraftAudit::APPLIED, $entry, $actor, $applied['locales'], null, [
                    'via' => 'direct',
                    'dropped_media' => $applied['dropped_media'],
                ]);

                return [$change, $applied['locales']];
            });
        } catch (RouteConflictException) {
            throw new EntryDraftRejectedException('slug_taken');
        }

        [$change, $locales] = $done;

        if ($locales !== []) {
            $this->notifier->published($entry, $locales[0], $actor, $change);
        }

        return $locales;
    }
}
