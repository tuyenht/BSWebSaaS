<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Audit\Support\DateChangeAudit;
use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\CategoryPublicCounts;
use App\Modules\Cms\Support\EntryDraftFolder;
use App\Modules\Cms\Support\EntryStatusAudit;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Cms\Support\HomePages;
use App\Modules\Workflow\Services\WorkflowEngine;
use Illuminate\Support\Facades\DB;

/**
 * «Gỡ khỏi website lúc»: takes a live post or page off the website when its end time comes, driven by
 * entries:unpublish-due. It becomes `archived` («Gỡ khỏi website», its content kept), the status every
 * public list, the sitemap, search and the category counts already leave out; the write purges the
 * edge cache. Staff still find it in the admin and can preview it.
 *
 * The sweep reads the due rows before it takes them down one by one, and an editor can still move the
 * end time, take the post down, or trash it in between. So the row is locked and re-read first, like
 * PublishScheduledEntry, and only a row that is STILL live, out of the Trash and due is taken down.
 *
 * Under an approval workflow the move goes through the engine's system road (withdrawAsSystem): the
 * flow's own move off the website when it has one, else forced off anyway and logged as such, so a
 * reviewer sees it in the post's history. Either way the post ends `archived` and its stage is the one
 * the engine returned, its status read from that stage as a member's move reads it (the flow's start:
 * a draft; its end of the line: archived; a stage in between: waiting for review). A pending change to
 * it is folded in, as when a member takes it down.
 *
 * The website's Home page is never taken down by this (its «/» would answer 404): it is held once and
 * its end time cleared (so the sweep does not pick it again every minute), and the end-time form
 * refuses one on it in the first place.
 *
 * Every take-down writes its status audit row (actor «scheduler», the end time and the road in its
 * meta) in the same transaction: no take-down without its line.
 */
final readonly class UnpublishExpiredEntry
{
    public const string UNPUBLISHED = 'unpublished';

    /** Taken down under an approval workflow with no move off the website from its stage. */
    public const string FORCED = 'forced';

    /** Due, but the website's Home page: left live. */
    public const string HELD = 'held';

    /** No longer due (moved, taken down, trashed or gone meanwhile): nothing written. */
    public const string NOT_DUE = 'not_due';

    public function __construct(
        private EntryWorkflowResolver $workflows,
        private WorkflowEngine $engine,
        private EntryDraftFolder $folder,
        private CategoryPublicCounts $publicCounts,
        private DateChangeAudit $dates,
    ) {}

    /** What became of the entry: UNPUBLISHED, FORCED, HELD or NOT_DUE. */
    public function handle(Entry $entry): string
    {
        return $this->dates->during(DateChangeAudit::VIA_SCHEDULER, fn (): string => DB::transaction(
            fn (): string => $this->publicCounts->track('entry_taxonomies', [$entry->id], fn (): string => $this->unpublishLocked($entry)),
        ));
    }

    /**
     * entries:publish-due found a post due to go live whose end time has passed meanwhile (the
     * scheduler was down, or the site was deferred): it never goes live. It leaves the schedule
     * archived, its approval (if any) and stage cleared as on any move off the website, with its
     * status audit row (actor «scheduler», reason expired), so nobody is told it went live or that
     * an approver lost a right. False when, re-read under the lock, it was no longer such a post.
     */
    public function archiveScheduled(Entry $entry): bool
    {
        return DB::transaction(function () use ($entry): bool {
            if (Entry::withTrashed()->whereKey($entry->id)->lockForUpdate()->first() === null) {
                return false;
            }
            $entry->refresh();

            $expiresAt = $entry->expires_at;
            if ($entry->status !== EntryStatus::Scheduled->value || $entry->trashed() || $expiresAt === null || $expiresAt > now()
                || $entry->published_at === null || $entry->published_at > now()) {
                return false;
            }

            $entry->update(['status' => EntryStatus::Archived->value, 'scheduled_by' => null, 'workflow_state' => null]);
            EntryStatusAudit::record($entry, EntryStatus::Scheduled->value, EntryStatus::Archived->value, DateChangeAudit::VIA_SCHEDULER, null,
                ['reason' => 'expired', 'expires_at' => $expiresAt->format(DATE_ATOM)], actorLabel: DateChangeAudit::VIA_SCHEDULER, strict: true);

            return true;
        });
    }

    /** True when the entry's end time has come: it must not go live (now or at its scheduled time). */
    public static function isPast(Entry $entry): bool
    {
        return $entry->expires_at !== null && $entry->expires_at <= now();
    }

    private function unpublishLocked(Entry $entry): string
    {
        // Lock FIRST, then read: every check below judges the row as it is now.
        if (Entry::withTrashed()->whereKey($entry->id)->lockForUpdate()->first() === null) {
            return self::NOT_DUE;   // purged from the Trash in the meantime
        }
        $entry->refresh();

        $expiresAt = $entry->expires_at;
        if ($entry->status !== EntryStatus::Published->value || $entry->trashed() || $expiresAt === null || $expiresAt > now()) {
            return self::NOT_DUE;
        }

        if (HomePages::is($entry)) {
            // only a pack or a direct write can give it one: cleared, with its date audit row
            $entry->update(['expires_at' => null]);

            return self::HELD;
        }

        $extra = ['reason' => 'expired', 'expires_at' => $expiresAt->format(DATE_ATOM)];
        $forced = false;
        $to = EntryStatus::Archived->value;
        $workflow = $this->workflows->forEntry($entry);
        if ($workflow !== null) {
            [$stage, $forced] = $this->engine->withdrawAsSystem(
                $workflow, $this->workflows->originFor($workflow, $entry), $entry->id, null, (string) __('workflow.note_expired'),
            );
            // the same reading of a stage as ApplyEntryWorkflowTransition (the stage is never a public one)
            $to = match (true) {
                $stage->is_initial => EntryStatus::Draft->value,
                $stage->is_terminal => EntryStatus::Archived->value,
                default => EntryStatus::Pending->value,
            };
            $entry->update(['status' => $to, 'workflow_state' => $stage->key, 'scheduled_by' => null]);
            $extra['workflow'] = $forced ? self::FORCED : 'moved';
        } else {
            $entry->update(['status' => EntryStatus::Archived->value, 'scheduled_by' => null]);
        }

        // nobody's pending work is lost, and nothing unapproved shows: the post is offline now
        $this->folder->fold($entry, null);

        EntryStatusAudit::record($entry, EntryStatus::Published->value, $to, DateChangeAudit::VIA_SCHEDULER, null, $extra, actorLabel: DateChangeAudit::VIA_SCHEDULER, strict: true);

        return $forced ? self::FORCED : self::UNPUBLISHED;
    }
}
