<?php

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Exceptions\HomePageLockedException;
use App\Modules\Cms\Exceptions\SchedulePastException;
use App\Modules\Cms\Exceptions\WorkflowGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\EntryStatusAudit;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Cms\Support\HomePages;
use App\Modules\Identity\Models\User;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Workflow Tầng-1 lifecycle transitions the Pages admin drives from the row status menu:
 * draft (take offline), scheduled (auto-publish at published_at), archived («Gỡ khỏi website»:
 * off the website, its content kept). `private` is retired and refused as a target; the rows
 * still in it are moved to archived once by retirePrivate(). Going straight to `published` is NOT here —
 * that must run through PublishEntry so the canonical route + 301 stay in sync — and `pending`
 * is only ever the output of a submit. Every transition needs the `publish` ability (which
 * includes the right to edit this entry): changing a page's lifecycle (above all taking a live
 * page offline) is a publisher control, the same guard PublishEntry applies so a submit-only
 * author cannot unpublish live content.
 *
 * `scheduled` is the one target that later ENDS in `published` (via entries:publish-due), so
 * where an approval workflow governs the entry it is refused like a direct publish would be —
 * otherwise "schedule it for a minute from now" would walk straight around the gate. The other
 * targets only ever take content off the public site, which no gate needs to approve.
 *
 * The live Home page never leaves `published` here (not to a draft, archived, nor a
 * schedule that hides it until its time): the website's «/» would answer 404 (RouteResolver serves
 * published entries only). A member picks another page as the Home page first.
 */
final readonly class TransitionEntryStatus
{
    /** States this action may set — published/pending are excluded by design (see class doc). */
    private const array ALLOWED = [
        EntryStatus::Draft->value,
        EntryStatus::Scheduled->value,
        EntryStatus::Archived->value,
    ];

    /** The audit `via` of the one-off move of retired `private` rows (cms:retire-private-status). */
    public const string VIA_RETIRE_PRIVATE = 'retire-private';

    public function __construct(
        private EntryWorkflowResolver $workflows,
        private ApproveEntryForLater $later,
    ) {}

    /**
     * @throws SchedulePastException a publish time that is missing or not after now
     * @throws HomePageLockedException any move of the live Home page (it would leave the website, «/» with it)
     * @throws WorkflowGovernedException scheduling content an active workflow governs
     * @throws ModelNotFoundException the entry left the list meanwhile (sent to the Trash or purged)
     */
    public function handle(Entry $entry, EntryStatus $target, User $actor, ?DateTimeInterface $publishAt = null): void
    {
        if (! in_array($target->value, self::ALLOWED, true)) {
            throw new InvalidArgumentException(__('entries.err_status_needs_publish_flow', ['status' => $target->value]));
        }

        DB::transaction(fn () => $this->handleLocked($entry, $target, $actor, $publishAt));
    }

    /**
     * «Hủy hẹn giờ»: a scheduled entry will not go live at its time after all, and goes back to a
     * draft to be worked on, under the same lock and guard as every other move here. A row that is
     * no longer scheduled (entries:publish-due published it, or a colleague moved it meanwhile) is
     * left as it is — never taken off the website by this. The publish time goes with the schedule.
     * A post approved for later under an approval workflow is cancelled the workflow's way instead
     * (ApproveEntryForLater::cancel): back to waiting at its stage, asked of someone who could approve it.
     *
     * @return bool whether the entry was moved back to a draft (false: it was no longer scheduled)
     *
     * @throws AuthorizationException the member may not change this entry's status
     * @throws ModelNotFoundException the entry left the list meanwhile (sent to the Trash or purged)
     */
    public function unschedule(Entry $entry, User $actor): bool
    {
        return DB::transaction(function () use ($entry, $actor): bool {
            $approved = Entry::query()->whereKey($entry->id)->lockForUpdate()->value('scheduled_by');
            if ($approved !== null) {
                return $this->later->cancel($entry, $actor);
            }

            $this->lockAndAuthorize($entry, $actor);
            if ($entry->status !== EntryStatus::Scheduled->value) {
                return false;
            }

            $governed = $this->workflows->forEntry($entry) !== null;
            $entry->update(['status' => EntryStatus::Draft->value, 'published_at' => null, 'scheduled_by' => null] + ($governed ? ['workflow_state' => null] : []));
            EntryStatusAudit::record($entry, EntryStatus::Scheduled->value, EntryStatus::Draft->value, 'status-menu', $actor, ['unscheduled' => true]);

            return true;
        });
    }

    /**
     * The one-off move of a row still in the retired `private` state to `archived` («Gỡ khỏi
     * website»), run by cms:retire-private-status inside the row's site context. The system makes
     * it, not a member: there is no actor to authorize, and the audit row names the system. Both
     * states keep the entry off the website, so visitors see no change. Under a workflow the stage
     * is cleared, as every move off the website here clears it. The entry's translations keep their
     * own statuses (only the entry's status decides what the website serves). No member edited it, so
     * its edit time stays as it was, and its audit row is written in the same transaction or the move
     * is rolled back (the one road whose audit line is not best-effort). A row no longer
     * `private` when locked (moved meanwhile, or by an earlier run) is left as it is, so a re-run
     * writes nothing.
     *
     * @return bool whether the entry was moved (false: it was no longer private, or is gone)
     */
    public function retirePrivate(Entry $entry): bool
    {
        return DB::transaction(function () use ($entry): bool {
            $locked = Entry::withTrashed()->whereKey($entry->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== EntryStatus::Private->value) {
                return false;
            }
            $entry->setRawAttributes($locked->getAttributes(), true);

            $governed = $this->workflows->forEntry($entry) !== null;
            $entry->timestamps = false;
            try {
                $entry->update(['status' => EntryStatus::Archived->value] + ($governed ? ['workflow_state' => null] : []));
            } finally {
                $entry->timestamps = true;
            }
            EntryStatusAudit::record($entry, EntryStatus::Private->value, EntryStatus::Archived->value, self::VIA_RETIRE_PRIVATE, null, actorLabel: 'system', strict: true);

            return true;
        });
    }

    private function lockAndAuthorize(Entry $entry, User $actor): void
    {
        // Take the row lock FIRST, then judge the row as it is now, like PublishEntry: the screen
        // loaded it earlier, and meanwhile a colleague may have sent it to the Trash (or the Trash
        // purged it) or changed its status. Only the row's own columns are read again: the loaded
        // relations stay, so a bulk run of 200 rows costs one locking read per row, not a reload.
        $locked = Entry::query()->whereKey($entry->id)->lockForUpdate()->first()
            ?? throw (new ModelNotFoundException)->setModel(Entry::class, [$entry->id]);
        $entry->setRawAttributes($locked->getAttributes(), true);

        if (! Gate::forUser($actor)->allows('publish', $entry)) {
            throw new AuthorizationException(__('entries.err_status_forbidden'));
        }
    }

    private function handleLocked(Entry $entry, EntryStatus $target, User $actor, ?DateTimeInterface $publishAt): void
    {
        $this->lockAndAuthorize($entry, $actor);

        // Every target here takes a live entry off the website (none is `published`): the live Home
        // page would leave «/» answering 404 (RouteResolver serves published entries only), so it is
        // refused whichever target was asked for, with the sentence that says why.
        if ($entry->status === EntryStatus::Published->value && HomePages::is($entry)) {
            throw new HomePageLockedException($target === EntryStatus::Scheduled ? 'entries.home_sched_why' : 'entries.home_offline_why');
        }

        $governed = $this->workflows->forEntry($entry) !== null;

        if ($target === EntryStatus::Scheduled) {
            if ($publishAt === null || $publishAt <= now()) {
                throw new SchedulePastException;
            }

            if ($governed) {
                throw new WorkflowGovernedException;
            }

            $from = $entry->status;
            // a new time set here is this member's own schedule: an approval for later (its flow since
            // switched off) no longer stands behind it, nor a stage of that flow
            $entry->update(['status' => EntryStatus::Scheduled->value, 'published_at' => $publishAt, 'scheduled_by' => null, 'workflow_state' => null]);
            EntryStatusAudit::record($entry, $from, EntryStatus::Scheduled->value, 'status-menu', $actor, ['publish_at' => $publishAt->format(DATE_ATOM)]);

            return;
        }

        // A post approved for later is taken back the «Hủy hẹn giờ» way first, as unschedule() does: the same
        // right, its author told (of the status it ends at), its time cleared. Then the move asked for.
        if ($entry->scheduled_by !== null) {
            $this->later->cancel($entry, $actor, $target);
            if ($entry->status === $target->value) {
                return;
            }
        }

        $from = $entry->status;
        // Under a workflow, leaving the public site from this menu also leaves the flow's stage
        // behind: the stage is re-derived from the new status (the start), so the only road back
        // to live is approval — and the stepper never claims "Đã đăng" over a hidden post.
        $entry->update(['status' => $target->value, 'scheduled_by' => null] + ($governed ? ['workflow_state' => null] : []));
        EntryStatusAudit::record($entry, $from, $target->value, 'status-menu', $actor);
    }
}
