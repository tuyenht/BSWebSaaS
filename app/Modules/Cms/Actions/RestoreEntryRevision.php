<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\EntryDraftRejectedException;
use App\Modules\Cms\Exceptions\RevisionRestoreRejectedException;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Support\EntryContentWriter;
use App\Modules\Cms\Support\JobFacts;
use App\Modules\Cms\Validation\EntryContentRules;
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
 * The status is never touched: restoring content is not publishing. On live content it lands
 * where an editor save of the same member lands (SaveEntryDraft::holdsBack, judged under the row
 * lock): straight on the website for a member who may publish an entry no approval workflow
 * governs, otherwise in the entry's pending change — visitors keep the live version until the
 * change is approved or applied, and no revision is recorded (nothing reached them). A change
 * already sent for review is the reviewer's to return first (change_pending). The audit line
 * records the status and workflow stage the restore landed on, and whether it went into the change.
 *
 * Media that has left the library since the revision was taken is dropped rather than refused: a
 * missing cover / OG image becomes none, and a block image prop is cleared (the block and its text
 * stay, as when the editor's "remove image" is used). The count of dropped references is returned
 * so the screen can say so. A place that shows a shared block deleted since is dropped the same way
 * (EntryContentWriter::withoutMissingBlocks; the audit line counts them): the storefront draws nothing there.
 *
 * The job card in `meta` (facts, badge) holds every language's words: only the restored language's
 * come back from the version, onto the card as it is now (JobFacts::restored), so the other languages'
 * words, and the card's shape outside the default language, stay as they are.
 */
final readonly class RestoreEntryRevision
{
    public const string AUDIT_ACTION = 'entries.revision.restored';

    public function __construct(
        private EntryContentWriter $writer,
        private RecordEntryRevision $revisions,
        private SiteLocale $locales,
        private SaveEntryDraft $changes,
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

        [$snapshot, $droppedBlocks] = $this->writer->withoutMissingBlocks($revision->snapshot);
        $content = $this->writer->read($snapshot, $entry->type, null, true, EntryContentRules::storedRefs($entry->ulid))
            ?? throw new RevisionRestoreRejectedException('snapshot_incompatible');
        $dropped = 0;

        try {
            /** @var bool $held */
            $held = DB::transaction(function () use ($entry, $revision, $content, $actor, &$dropped): bool {
                // The entry row first, as every entry action takes it, before its translation is
                // written back (a check of the search words running meanwhile waits for it).
                // A trash committed while this waited still refuses the restore.
                $locked = Entry::withTrashed()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
                if ($locked->trashed()) {
                    throw new RevisionRestoreRejectedException('entry_trashed');
                }
                // approved for later: it goes live as it was approved, so nothing is written until «Hủy hẹn giờ»
                if ($locked->approvedForLater()) {
                    throw new RevisionRestoreRejectedException('approved_later');
                }
                $entry->setRawAttributes($locked->getAttributes(), true);
                // The pictures the version names, checked in this transaction: the files kept stay
                // FOR KEY SHARE until it commits, so a purge meanwhile cannot leave an id naming nothing.
                [$content, $dropped] = $this->writer->withoutMissingMedia($content);

                // Live content this member's save would hold back goes into the pending change, as their save does.
                if ($this->changes->holdsBack($entry, $actor)) {
                    $this->intoChange($entry, $revision->locale, $content, $actor);

                    return true;
                }

                $content['meta'] = JobFacts::restored($entry->meta, $content['meta'], $revision->locale, $this->locales->default());
                $written = $this->writer->write($entry, $revision->locale, $content);

                // The shared fields (template, cover, meta) belong to every language's page: when the
                // restore changed them, each language's current state is recorded so its own history
                // still shows what it looked like. Other languages' text is never touched (their words for a
                // changed picture aside: EntryContentWriter::write empties them).
                if ($written['shared_changed']) {
                    $this->revisions->forAllLocales($entry, RevisionKind::Restore, $actor);
                } else {
                    $this->revisions->forLocale($entry, $written['translation'], RevisionKind::Restore, $actor);
                }

                return false;
            });
        } catch (RouteConflictException $e) {
            throw $e->subPageOf($entry->id)
                ? new RevisionRestoreRejectedException('subpage_path_taken', $e->path)
                : new RevisionRestoreRejectedException('slug_taken');
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
                'dropped_blocks' => $droppedBlocks,
                'into_change' => $held,
            ],
        ]);

        return $dropped;
    }

    /**
     * The version, as read, saved into the entry's pending change: this language's text and the
     * shared fields the version carries (template, cover, picture); the main category stays as the
     * change or the website has it.
     *
     * @param  array<string, mixed>  $content  EntryContentWriter::read(), media already checked
     *
     * @throws RevisionRestoreRejectedException change_pending · snapshot_incompatible · locale_disabled · entry_trashed
     */
    private function intoChange(Entry $entry, string $locale, array $content, User $actor): void
    {
        // the job card onto the card as the change holds it (or the website shows it): read under the
        // entry's row lock, which every writer of the change takes first
        $held = EntryDraft::query()->where('entry_id', $entry->id)->first()?->content['shared'] ?? null;
        $current = is_array($held) && is_array($held['meta'] ?? null) ? $held['meta'] : $entry->meta;
        $content['meta'] = JobFacts::restored($current, is_array($content['meta'] ?? null) ? $content['meta'] : [], $locale, $this->locales->default());
        $text = ['body' => ['blocks' => $content['blocks'] ?? []]]
            + array_intersect_key($content, array_flip(['title', 'slug', 'excerpt', 'seo_title', 'seo_description', 'og_image_id', 'author_name', 'cover_alt']));
        $shared = array_intersect_key($content, array_flip(['template_override', 'cover_media_id', 'meta']));

        try {
            $this->changes->handle($entry, $actor, $locale, $text, $shared);
        } catch (EntryDraftRejectedException $refused) {
            throw new RevisionRestoreRejectedException(match ($refused->reason) {
                'change_locked' => 'change_pending',
                'locale_disabled' => 'locale_disabled',
                'not_live' => 'entry_trashed',
                default => 'snapshot_incompatible',
            });
        }
    }
}
