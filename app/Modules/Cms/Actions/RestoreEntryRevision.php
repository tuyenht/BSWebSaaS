<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\RevisionRestoreRejectedException;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Support\EntryContentWriter;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Writes an earlier revision's content back onto its entry (design.md §8). Guarded like an edit
 * (EntryPolicy::update, own/any), and written through the editor's own checks and side effects by
 * EntryContentWriter: the same title/slug/block rules, the route moved with a 301 when a live
 * entry's slug changes, and the block image usage index rebuilt.
 *
 * The status is never touched: restoring content is not publishing. On live content the change is
 * visible at once, exactly like an editor save — an approval workflow governs the decision to
 * publish, not each edit (design.md §15.4) — so the audit line records the status and workflow
 * stage the restore landed on.
 *
 * Media that has left the library since the revision was taken is dropped rather than refused: a
 * missing cover / OG image becomes none, and a block image prop is cleared (the block and its text
 * stay, as when the editor's "remove image" is used). The count of dropped references is returned
 * so the screen can say so.
 */
final readonly class RestoreEntryRevision
{
    public const string AUDIT_ACTION = 'entries.revision.restored';

    public function __construct(
        private EntryContentWriter $writer,
        private RecordEntryRevision $revisions,
        private SiteLocale $locales,
    ) {}

    /**
     * @return int how many media references were dropped because the media no longer exists
     *
     * @throws AuthorizationException the actor may not edit this entry
     * @throws RevisionRestoreRejectedException one of its REASONS
     */
    public function handle(EntryRevision $revision, User $actor): int
    {
        // Trashed entries are looked up too, so the refusal can say why instead of a 404.
        $entry = Entry::withTrashed()->whereKey($revision->entry_id)->firstOrFail();

        if (! Gate::forUser($actor)->allows('update', $entry)) {
            throw new AuthorizationException(__('entries.err_update_forbidden'));
        }

        if ($entry->trashed()) {
            throw new RevisionRestoreRejectedException('entry_trashed');
        }

        if (! $this->locales->isEnabled($revision->locale)) {
            throw new RevisionRestoreRejectedException('locale_disabled');
        }

        $content = $this->writer->read($revision->snapshot)
            ?? throw new RevisionRestoreRejectedException('snapshot_incompatible');
        [$content, $dropped] = $this->writer->withoutMissingMedia($content);

        try {
            DB::transaction(function () use ($entry, $revision, $content, $actor): void {
                $written = $this->writer->write($entry, $revision->locale, $content);

                // The shared fields (template, cover, meta) belong to every language's page: when the
                // restore changed them, each language's current state is recorded so its own history
                // still shows what it looked like. Other languages' text is never touched.
                if ($written['shared_changed']) {
                    $this->revisions->forAllLocales($entry, RevisionKind::Restore, $actor);
                } else {
                    $this->revisions->forLocale($entry, $written['translation'], RevisionKind::Restore, $actor);
                }
            });
        } catch (RouteConflictException) {
            throw new RevisionRestoreRejectedException('slug_taken');
        }

        Audit::record(self::AUDIT_ACTION, [
            'auditable_type' => Entry::class,
            'auditable_id' => $entry->getKey(),
            'actor_id' => $actor->getKey(),
            'actor_label' => $actor->email,
            'meta' => [
                'entry_ulid' => $entry->ulid,
                'revision_ulid' => $revision->ulid,
                'locale' => $revision->locale,
                'status' => $entry->status,
                'workflow_state' => $entry->workflow_state,
                'dropped_media' => $dropped,
            ],
        ]);

        return $dropped;
    }
}
