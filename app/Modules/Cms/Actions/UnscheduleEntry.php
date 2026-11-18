<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\EntryStatusAudit;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * «Hủy hẹn giờ»: a scheduled entry will not go live at its time after all, and goes back to a draft
 * to be worked on. The same guard and lock as every other status move from the list
 * (TransitionEntryStatus): the row is locked first and judged as it is now, and the move needs the
 * `publish` ability on it. A row that is no longer scheduled (entries:publish-due published it, or a
 * colleague moved it meanwhile) is left as it is — never taken off the website by this — and the
 * caller hears so. The publish time goes with the schedule, so the background publisher never
 * picks the draft up.
 */
final readonly class UnscheduleEntry
{
    public function __construct(private EntryWorkflowResolver $workflows) {}

    /**
     * @return bool whether the entry was moved back to a draft (false: it was no longer scheduled)
     *
     * @throws AuthorizationException the member may not change this entry's status
     * @throws ModelNotFoundException the entry left the list meanwhile (sent to the Trash or purged)
     */
    public function handle(Entry $entry, User $actor): bool
    {
        return DB::transaction(function () use ($entry, $actor): bool {
            $locked = Entry::query()->whereKey($entry->id)->lockForUpdate()->first()
                ?? throw (new ModelNotFoundException)->setModel(Entry::class, [$entry->id]);
            $entry->setRawAttributes($locked->getAttributes(), true);

            if (! Gate::forUser($actor)->allows('publish', $entry)) {
                throw new AuthorizationException(__('entries.err_status_forbidden'));
            }
            if ($entry->status !== EntryStatus::Scheduled->value) {
                return false;
            }

            // under a workflow the stage is re-derived from the draft (its start), as TransitionEntryStatus does
            $governed = $this->workflows->forEntry($entry) !== null;
            $entry->update(['status' => EntryStatus::Draft->value, 'published_at' => null] + ($governed ? ['workflow_state' => null] : []));
            EntryStatusAudit::record($entry, EntryStatus::Scheduled->value, EntryStatus::Draft->value, 'status-menu', $actor, ['unscheduled' => true]);

            return true;
        });
    }
}
