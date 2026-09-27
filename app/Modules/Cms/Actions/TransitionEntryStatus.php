<?php

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Exceptions\WorkflowGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\EntryStatusAudit;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Workflow Tầng-1 lifecycle transitions the Pages admin drives from the row status menu:
 * draft (take offline), scheduled (auto-publish at published_at), private (finished but
 * deliberately non-public), archived (retired). Going straight to `published` is NOT here —
 * that must run through PublishEntry so the canonical route + 301 stay in sync — and `pending`
 * is only ever the output of a submit. Every transition needs the `publish` ability: changing
 * a page's lifecycle (above all taking a live page offline) is a publisher control, the same
 * guard PublishEntry applies so a submit-only author cannot unpublish live content.
 *
 * `scheduled` is the one target that later ENDS in `published` (via entries:publish-due), so
 * where an approval workflow governs the entry it is refused like a direct publish would be —
 * otherwise "schedule it for a minute from now" would walk straight around the gate. The other
 * targets only ever take content off the public site, which no gate needs to approve.
 */
final readonly class TransitionEntryStatus
{
    /** States this action may set — published/pending are excluded by design (see class doc). */
    private const array ALLOWED = [
        EntryStatus::Draft->value,
        EntryStatus::Scheduled->value,
        EntryStatus::Private->value,
        EntryStatus::Archived->value,
    ];

    public function __construct(private EntryWorkflowResolver $workflows) {}

    /**
     * @throws WorkflowGovernedException scheduling content an active workflow governs
     */
    public function handle(Entry $entry, EntryStatus $target, User $actor, ?DateTimeInterface $publishAt = null): void
    {
        if (! in_array($target->value, self::ALLOWED, true)) {
            throw new InvalidArgumentException(__('entries.err_status_needs_publish_flow', ['status' => $target->value]));
        }

        if (! Gate::forUser($actor)->allows('publish', $entry)) {
            throw new AuthorizationException(__('entries.err_status_forbidden'));
        }

        $governed = $this->workflows->forEntry($entry) !== null;

        if ($target === EntryStatus::Scheduled) {
            if ($publishAt === null || $publishAt <= now()) {
                throw new InvalidArgumentException(__('entries.err_schedule_in_past'));
            }

            if ($governed) {
                throw new WorkflowGovernedException;
            }

            $from = $entry->status;
            $entry->update(['status' => EntryStatus::Scheduled->value, 'published_at' => $publishAt]);
            EntryStatusAudit::record($entry, $from, EntryStatus::Scheduled->value, 'status-menu', $actor, ['publish_at' => $publishAt->format(DATE_ATOM)]);

            return;
        }

        $from = $entry->status;
        // Under a workflow, leaving the public site from this menu also leaves the flow's stage
        // behind: the stage is re-derived from the new status (the start), so the only road back
        // to live is approval — and the stepper never claims "Đã đăng" over a hidden post.
        $entry->update(['status' => $target->value] + ($governed ? ['workflow_state' => null] : []));
        EntryStatusAudit::record($entry, $from, $target->value, 'status-menu', $actor);
    }
}
